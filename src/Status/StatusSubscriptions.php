<?php

declare(strict_types=1);

namespace Nebrix\Status;

use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Mail\Mailer;
use Nebrix\Security\RateLimiter;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Support\Signer;
use Nebrix\View\Translator;
use PDOException;

/**
 * E-mail subscriptions to the public status page.
 *
 * - Double opt-in: a subscription only becomes active through the link in
 *   the confirmation e-mail (256-bit token, stored as SHA-256, valid for
 *   48 hours, single use). Unconfirmed entries are deleted after expiry.
 * - The response never reveals whether an address is already subscribed.
 * - Every e-mail carries a one-click unsubscribe link (List-Unsubscribe,
 *   RFC 8058): an HMAC of the subscriber ID and a random per-subscriber
 *   salt with a key derived from APP_KEY, so it is not stored anywhere.
 *   Unsubscribing deletes the entry.
 */
class StatusSubscriptions
{
    public const CONFIRM_HOURS = 48;
    private const PURPOSE = 'status-unsubscribe';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly Signer $signer,
        private readonly SettingsRepository $settings,
        private readonly Translator $translator,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('notifications.status_subscriptions');
    }

    /**
     * Starts (or restarts) the double opt-in for an address. Confirmed
     * addresses are left alone; the caller shows the same answer either way.
     */
    public function subscribe(string $email, string $locale): void
    {
        $email = mb_strtolower(trim($email));
        $locale = in_array($locale, ['de', 'en'], true) ? $locale : 'en';
        $existing = $this->db->fetchOne('SELECT * FROM `nx_status_subscribers` WHERE `email` = ?', [$email]);
        if ($existing !== null && $existing['confirmed_at'] !== null) {
            return;
        }
        // At most 3 confirmation e-mails per address and day, 200 per hour in total.
        if (!$this->limiter->attempt('status-subscribe-to:' . hash('sha256', $email), 3, 86400) || !$this->limiter->attempt('status-subscribe-mail:all', 200, 3600)) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $now = $this->clock->nowString();
        $expires = $this->clock->now()->modify('+' . self::CONFIRM_HOURS . ' hours')->format('Y-m-d H:i:s');
        if ($existing === null) {
            try {
                $this->db->insert('nx_status_subscribers', [
                    'email' => $email, 'locale' => $locale, 'confirm_hash' => hash('sha256', $token), 'confirm_expires_at' => $expires,
                    'secret' => bin2hex(random_bytes(16)), 'created_at' => $now, 'updated_at' => $now,
                ]);
            } catch (PDOException $exception) {
                if (($exception->errorInfo[1] ?? null) === 1062) {
                    return; // subscribed in parallel
                }

                throw $exception;
            }
        } else {
            $this->db->update('nx_status_subscribers', ['locale' => $locale, 'confirm_hash' => hash('sha256', $token), 'confirm_expires_at' => $expires, 'updated_at' => $now], ['id' => (int) $existing['id']]);
        }

        $this->mailer->queue($email, null, 'status_subscription_confirm', [
            'confirm_url' => $this->url('/status/subscribe/confirm/' . $token),
            'expires_hours' => self::CONFIRM_HOURS,
            'status_url' => $this->url('/status'),
        ], $locale);
    }

    /**
     * @return array<string, mixed>|null the pending subscription of a valid, unexpired token
     */
    public function pending(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM `nx_status_subscribers` WHERE `confirm_hash` = ? AND `confirmed_at` IS NULL AND `confirm_expires_at` > ?',
            [hash('sha256', $token), $this->clock->nowString()],
        );
    }

    /** Confirms the subscription; the token cannot be used again. */
    public function confirm(string $token): bool
    {
        $pending = $this->pending($token);
        if ($pending === null) {
            return false;
        }
        $now = $this->clock->nowString();

        return $this->db->run(
            'UPDATE `nx_status_subscribers` SET `confirmed_at` = ?, `confirm_hash` = NULL, `confirm_expires_at` = NULL, `updated_at` = ? WHERE `id` = ? AND `confirm_hash` = ?',
            [$now, $now, (int) $pending['id'], hash('sha256', $token)],
        )->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $subscriber
     */
    public function unsubscribeUrl(array $subscriber): string
    {
        return $this->url('/status/unsubscribe/' . (int) $subscriber['id'] . '/' . $this->signer->sign(self::PURPOSE, $subscriber['id'] . ':' . $subscriber['secret']));
    }

    /**
     * @return array<string, mixed>|null the subscriber when the unsubscribe token is valid
     */
    public function forUnsubscribe(int $id, string $token): ?array
    {
        $subscriber = $this->db->fetchOne('SELECT * FROM `nx_status_subscribers` WHERE `id` = ?', [$id]);
        if ($subscriber === null || !$this->signer->verify(self::PURPOSE, $subscriber['id'] . ':' . $subscriber['secret'], $token)) {
            return null;
        }

        return $subscriber;
    }

    public function unsubscribe(int $id, string $token): bool
    {
        if ($this->forUnsubscribe($id, $token) === null) {
            return false;
        }

        return $this->db->delete('nx_status_subscribers', ['id' => $id]) > 0;
    }

    /**
     * E-mails a published incident or maintenance announcement (or an update
     * of it) to all confirmed subscribers.
     *
     * @param array<string, mixed> $incident row of StatusService::findIncident()
     * @param callable(string, string): string $localTime UTC time + locale => display time
     */
    public function notify(array $incident, string $message, callable $localTime): int
    {
        if (!$this->enabled()) {
            return 0;
        }
        $planned = $incident['type'] === StatusService::PLANNED;
        $sent = 0;
        foreach ($this->db->fetchAll('SELECT `id`, `email`, `locale`, `secret` FROM `nx_status_subscribers` WHERE `confirmed_at` IS NOT NULL ORDER BY `id` LIMIT 20000') as $subscriber) {
            $locale = (string) $subscriber['locale'];
            $unsubscribe = $this->unsubscribeUrl($subscriber);
            $this->mailer->queue((string) $subscriber['email'], null, 'status_subscriber_update', [
                'kind' => $this->translator->translateTo($locale, $planned ? 'Maintenance' : 'Incident'),
                'title' => (string) $incident['title'],
                'message' => $message,
                'window' => $planned ? $localTime((string) $incident['starts_at'], $locale) . ($incident['ends_at'] !== null ? ' – ' . $localTime((string) $incident['ends_at'], $locale) : '') : '',
                'status' => $this->translator->translateTo($locale, ucfirst(str_replace('_', ' ', (string) $incident['status']))),
                'status_url' => $this->url('/status'),
                'unsubscribe_url' => $unsubscribe,
            ], $locale, [], null, ['list_unsubscribe' => $unsubscribe]);
            $sent++;
        }

        return $sent;
    }

    /** Scheduler: removes unconfirmed subscriptions whose link has expired. */
    public function purgeExpired(): int
    {
        return $this->db->run('DELETE FROM `nx_status_subscribers` WHERE `confirmed_at` IS NULL AND `confirm_expires_at` < ?', [$this->clock->nowString()])->rowCount();
    }

    private function url(string $path): string
    {
        return rtrim($this->config->string('app.url'), '/') . $path;
    }
}
