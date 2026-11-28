<?php

declare(strict_types=1);

namespace Nebrix\Marketing;

use Nebrix\Audit\AuditLogger;
use Nebrix\Auth\User;
use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Mail\Mailer;
use Nebrix\Security\RateLimiter;
use Nebrix\Settings\SettingsRepository;

/**
 * Newsletter subscriptions with double opt-in.
 *
 * - A sign-up stores the address as "pending" and sends a confirmation
 *   link with a 256-bit random token (stored as SHA-256, expires after the
 *   configured hours). Only the confirmation makes the address
 *   "confirmed"; campaigns are sent to confirmed addresses only.
 * - Proof of consent: time, truncated IP address and source of the request
 *   and of the confirmation, and the text the subscriber agreed to.
 * - The website form answers the same for every address (no enumeration);
 *   confirmation e-mails are limited per address, sign-ups per network.
 * - Customers with a verified account e-mail address subscribe in their
 *   account; the signed-in, verified account is the confirmation.
 */
class Newsletter
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const UNSUBSCRIBED = 'unsubscribed';

    /** Confirmation e-mails per address and day (sign-up form). */
    private const MAILS_PER_ADDRESS = 3;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SettingsRepository $settings,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('newsletter.enabled');
    }

    /**
     * Sign-up from the website. Never reveals whether the address is
     * already known: confirmed addresses get no e-mail, every other address
     * a (new) confirmation link, at most MAILS_PER_ADDRESS per day.
     */
    public function subscribe(string $email, string $locale, string $ip, string $consentText): void
    {
        $email = mb_strtolower(trim($email));
        $now = $this->clock->nowString();
        $this->db->transaction(function () use ($email, $locale, $ip, $consentText, $now): void {
            $existing = $this->db->fetchOne('SELECT * FROM `nx_newsletter_subscribers` WHERE `email` = ? FOR UPDATE', [$email]);
            if ($existing !== null && $existing['status'] === self::CONFIRMED) {
                return;
            }
            if (!$this->limiter->attempt('newsletter-confirm:' . hash('sha256', $email), self::MAILS_PER_ADDRESS, 86400)) {
                return;
            }
            $token = bin2hex(random_bytes(32));
            $row = [
                'locale' => $locale,
                'status' => self::PENDING,
                'source' => 'website',
                'consent_text' => mb_substr($consentText, 0, 500),
                'confirm_token_hash' => hash('sha256', $token),
                'confirm_expires_at' => $this->clock->now()->modify(sprintf('+%d hours', $this->confirmHours()))->format('Y-m-d H:i:s'),
                'requested_at' => $now,
                'requested_ip' => IpAddress::truncate($ip),
                'updated_at' => $now,
            ];
            if ($existing === null) {
                $this->db->insert('nx_newsletter_subscribers', $row + ['email' => $email, 'created_at' => $now]);
            } else {
                $this->db->update('nx_newsletter_subscribers', $row, ['id' => (int) $existing['id']]);
            }
            $this->mailer->queue($email, null, 'newsletter_confirm', [
                'confirm_url' => $this->url('/newsletter/confirm/' . $token),
                'expires_hours' => $this->confirmHours(),
            ], $locale);
        });
    }

    /**
     * @return array<string, mixed>|null the pending subscription of a valid, unexpired token
     */
    public function findPending(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM `nx_newsletter_subscribers` WHERE `confirm_token_hash` = ? AND `status` = ? AND `confirm_expires_at` > ?',
            [hash('sha256', $token), self::PENDING, $this->clock->nowString()],
        );
    }

    /** Confirms a subscription (double opt-in); the token can be used once. */
    public function confirm(string $token, string $ip): bool
    {
        $subscriber = $this->findPending($token);
        if ($subscriber === null) {
            return false;
        }
        $now = $this->clock->nowString();
        $confirmed = $this->db->run(
            'UPDATE `nx_newsletter_subscribers` SET `status` = ?, `confirmed_at` = ?, `confirmed_ip` = ?, `confirm_token_hash` = NULL, `confirm_expires_at` = NULL, `unsubscribed_at` = NULL, `updated_at` = ?
              WHERE `id` = ? AND `status` = ? AND `confirm_token_hash` = ?',
            [self::CONFIRMED, $now, IpAddress::truncate($ip), $now, (int) $subscriber['id'], self::PENDING, hash('sha256', $token)],
        )->rowCount() === 1;
        if ($confirmed) {
            $this->audit->log($subscriber['user_id'] !== null ? (int) $subscriber['user_id'] : null, 'newsletter.confirmed', 'newsletter_subscriber', (int) $subscriber['id'], ['source' => $subscriber['source']], IpAddress::truncate($ip));
        }

        return $confirmed;
    }

    /**
     * The subscriber of an unsubscribe link (token of a delivered newsletter), or null.
     *
     * @return array<string, mixed>|null
     */
    public function findByUnsubscribeToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT s.* FROM `nx_newsletter_deliveries` d JOIN `nx_newsletter_subscribers` s ON s.`id` = d.`subscriber_id` WHERE d.`unsubscribe_token_hash` = ?',
            [hash('sha256', $token)],
        );
    }

    /** Unsubscribes the recipient of a newsletter link; false for unknown tokens. */
    public function unsubscribeByToken(string $token, ?string $ip): bool
    {
        $subscriber = $this->findByUnsubscribeToken($token);
        if ($subscriber === null) {
            return false;
        }
        $this->markUnsubscribed((int) $subscriber['id'], 'link', $ip);

        return true;
    }

    /**
     * The subscription of a customer account (by account or e-mail address).
     *
     * @return array<string, mixed>|null
     */
    public function forUser(User $user): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_newsletter_subscribers` WHERE `user_id` = ? OR `email` = ? ORDER BY (`user_id` = ?) DESC LIMIT 1', [$user->id, mb_strtolower($user->email), $user->id]);
    }

    public function isSubscribed(User $user): bool
    {
        return ($this->forUser($user)['status'] ?? null) === self::CONFIRMED;
    }

    /**
     * Subscription in the customer account. The account's e-mail address
     * is verified and the customer is signed in, so the subscription is
     * confirmed at once (no further e-mail); without a verified address a
     * confirmation link is sent instead.
     */
    public function subscribeAccount(User $user, string $ip, string $consentText): bool
    {
        if ($user->emailVerifiedAt === null) {
            $this->subscribe($user->email, (string) ($user->attributes['language'] ?? 'en'), $ip, $consentText);

            return false;
        }
        $now = $this->clock->nowString();
        $email = mb_strtolower($user->email);
        $this->db->transaction(function () use ($user, $email, $ip, $consentText, $now): void {
            $existing = $this->db->fetchOne('SELECT * FROM `nx_newsletter_subscribers` WHERE `email` = ? FOR UPDATE', [$email]);
            $row = [
                'user_id' => $user->id,
                'locale' => (string) ($user->attributes['language'] ?? 'en') ?: 'en',
                'status' => self::CONFIRMED,
                'source' => 'account',
                'consent_text' => mb_substr($consentText, 0, 500),
                'confirm_token_hash' => null,
                'confirm_expires_at' => null,
                'requested_at' => $now,
                'requested_ip' => IpAddress::truncate($ip),
                'confirmed_at' => $now,
                'confirmed_ip' => IpAddress::truncate($ip),
                'unsubscribed_at' => null,
                'updated_at' => $now,
            ];
            if ($existing === null) {
                $id = $this->db->insert('nx_newsletter_subscribers', $row + ['email' => $email, 'created_at' => $now]);
            } elseif ($existing['status'] !== self::CONFIRMED) {
                $id = (int) $existing['id'];
                $this->db->update('nx_newsletter_subscribers', $row, ['id' => $id]);
            } else {
                $this->db->update('nx_newsletter_subscribers', ['user_id' => $user->id, 'updated_at' => $now], ['id' => (int) $existing['id']]);

                return;
            }
            $this->audit->log($user->id, 'newsletter.confirmed', 'newsletter_subscriber', $id, ['source' => 'account'], IpAddress::truncate($ip));
        });

        return true;
    }

    public function unsubscribeAccount(User $user, string $ip): void
    {
        $subscriber = $this->forUser($user);
        if ($subscriber !== null && $subscriber['status'] !== self::UNSUBSCRIBED) {
            $this->markUnsubscribed((int) $subscriber['id'], 'account', $ip, $user->id);
        }
    }

    /**
     * Subscribers for the administration.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(string $search, string $status, int $page, int $perPage = 50): array
    {
        [$where, $params] = $this->filter($search, $status);

        return [
            'rows' => $this->db->fetchAll('SELECT * FROM `nx_newsletter_subscribers` WHERE ' . $where . ' ORDER BY `id` DESC LIMIT ' . $perPage . ' OFFSET ' . (max(1, $page) - 1) * $perPage, $params),
            'total' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_newsletter_subscribers` WHERE ' . $where, $params),
        ];
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function export(string $search, string $status): \Generator
    {
        [$where, $params] = $this->filter($search, $status);
        $lastId = 0;
        do {
            $rows = $this->db->fetchAll('SELECT * FROM `nx_newsletter_subscribers` WHERE ' . $where . ' AND `id` > ? ORDER BY `id` LIMIT 1000', [...$params, $lastId]);
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                yield $row;
            }
        } while (count($rows) === 1000);
    }

    /**
     * @return array<string, int> status => count
     */
    public function counts(): array
    {
        $counts = [self::PENDING => 0, self::CONFIRMED => 0, self::UNSUBSCRIBED => 0];
        foreach ($this->db->fetchAll('SELECT `status`, COUNT(*) AS `n` FROM `nx_newsletter_subscribers` GROUP BY `status`') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_newsletter_subscribers` WHERE `id` = ?', [$id]);
    }

    /** Deletes a subscriber with their deliveries (erasure request). */
    public function delete(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->db->run('DELETE FROM `nx_newsletter_deliveries` WHERE `subscriber_id` = ?', [$id]);
            $this->db->run('DELETE FROM `nx_newsletter_subscribers` WHERE `id` = ?', [$id]);
        });
    }

    /** Scheduler: removes sign-ups that were never confirmed (no consent, no reason to keep the address). */
    public function pruneUnconfirmed(): int
    {
        return $this->db->run(
            'DELETE FROM `nx_newsletter_subscribers` WHERE `status` = ? AND `confirm_expires_at` < ? AND `confirmed_at` IS NULL',
            [self::PENDING, $this->clock->now()->modify('-7 days')->format('Y-m-d H:i:s')],
        )->rowCount();
    }

    private function markUnsubscribed(int $id, string $via, ?string $ip, ?int $actorId = null): void
    {
        $now = $this->clock->nowString();
        $changed = $this->db->run(
            'UPDATE `nx_newsletter_subscribers` SET `status` = ?, `unsubscribed_at` = ?, `confirm_token_hash` = NULL, `confirm_expires_at` = NULL, `updated_at` = ? WHERE `id` = ? AND `status` <> ?',
            [self::UNSUBSCRIBED, $now, $now, $id, self::UNSUBSCRIBED],
        )->rowCount() === 1;
        if ($changed) {
            $this->audit->log($actorId, 'newsletter.unsubscribed', 'newsletter_subscriber', $id, ['via' => $via], $ip !== null ? IpAddress::truncate($ip) : null);
        }
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function filter(string $search, string $status): array
    {
        $where = '1 = 1';
        $params = [];
        if (in_array($status, [self::PENDING, self::CONFIRMED, self::UNSUBSCRIBED], true)) {
            $where .= ' AND `status` = ?';
            $params[] = $status;
        }
        $search = mb_substr(trim($search), 0, 100);
        if ($search !== '') {
            $where .= ' AND `email` LIKE ?';
            $params[] = '%' . addcslashes(mb_strtolower($search), '%_\\') . '%';
        }

        return [$where, $params];
    }

    private function confirmHours(): int
    {
        return max(1, min(168, $this->settings->int('newsletter.confirm_hours') ?: 48));
    }

    private function url(string $path): string
    {
        return rtrim($this->config->string('app.url'), '/') . $path;
    }
}
