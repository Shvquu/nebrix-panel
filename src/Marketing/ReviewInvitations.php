<?php

declare(strict_types=1);

namespace Nebrix\Marketing;

use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Mail\Mailer;
use Nebrix\Settings\SettingsRepository;
use Nebrix\View\Translator;
use Psr\Log\LoggerInterface;

/**
 * Review invitations (scheduler task reviews.invitations).
 *
 * A review request by e-mail is advertising (§ 7 Abs. 2 Nr. 2 UWG): it is
 * sent only to customers with an active consent, once per customer, N days
 * (setting) after their first service became active, while they still have
 * an active service. Every invitation carries an opt-out link (and the
 * List-Unsubscribe headers) that withdraws the consent. Off by default.
 */
class ReviewInvitations
{
    /** Invitations per scheduler run. */
    private const BATCH = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SettingsRepository $settings,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly Consents $consents,
        private readonly LoggerInterface $logger,
        private readonly Translator $translator,
    ) {
    }

    /** The text customers agree to (registration, order, account); stored with the consent. */
    public function consentText(): string
    {
        return $this->translator->translate('Yes, :brand may invite me once by e-mail to review my experience (e.g. on Trustpilot). I can withdraw this consent at any time.', ['brand' => $this->settings->string('general.company_name')]);
    }

    /**
     * Records the consent given with the optional, unticked checkbox
     * "review_consent" of the registration or order form.
     */
    public function consentFromForm(\Nebrix\Http\Request $request, int $userId, string $source): void
    {
        if ($request->input('review_consent') === '1' && $this->enabled() && !$this->consents->has($userId, Consents::REVIEW_INVITATION)) {
            $this->consents->grant($userId, Consents::REVIEW_INVITATION, $source, $request->clientIp(), $this->consentText());
        }
    }

    public function enabled(): bool
    {
        return $this->settings->bool('reviews.enabled') && $this->reviewUrl() !== null;
    }

    /** The configured review link (https, Trustpilot), or null. */
    public function reviewUrl(): ?string
    {
        $url = $this->settings->string('reviews.review_url');

        return preg_match('#^https://([a-z]{2,3}\.)?trustpilot\.com/[A-Za-z0-9._~/?=&%+-]*$#', $url) === 1 ? $url : null;
    }

    /**
     * Sends the invitations that are due.
     *
     * @return int number of invitations queued
     */
    public function sendDue(): int
    {
        $reviewUrl = $this->reviewUrl();
        if (!$this->settings->bool('reviews.enabled') || $reviewUrl === null) {
            return 0;
        }
        $cutoff = $this->clock->now()->modify(sprintf('-%d days', max(1, $this->settings->int('reviews.delay_days'))))->format('Y-m-d H:i:s');
        $rows = $this->db->fetchAll(
            "SELECT u.`id`, u.`email`, u.`first_name`, u.`last_name`, u.`language`, MIN(s.`provisioned_at`) AS `first_active`
               FROM `nx_users` u
               JOIN `nx_roles` r ON r.`id` = u.`role_id` AND r.`is_staff` = 0
               JOIN `nx_marketing_consents` c ON c.`user_id` = u.`id` AND c.`purpose` = ? AND c.`granted_at` IS NOT NULL AND c.`withdrawn_at` IS NULL
               JOIN `nx_services` s ON s.`user_id` = u.`id` AND s.`provisioned_at` IS NOT NULL
              WHERE u.`status` = 'active'
                AND NOT EXISTS (SELECT 1 FROM `nx_review_invitations` i WHERE i.`user_id` = u.`id`)
                AND EXISTS (SELECT 1 FROM `nx_services` a WHERE a.`user_id` = u.`id` AND a.`status` = 'active')
              GROUP BY u.`id`, u.`email`, u.`first_name`, u.`last_name`, u.`language`
             HAVING MIN(s.`provisioned_at`) <= ?
              ORDER BY `first_active` LIMIT " . self::BATCH,
            [Consents::REVIEW_INVITATION, $cutoff],
        );

        $sent = 0;
        foreach ($rows as $row) {
            $userId = (int) $row['id'];
            $token = bin2hex(random_bytes(32));
            $queued = $this->db->transaction(function () use ($row, $userId, $token, $reviewUrl): bool {
                $serviceId = $this->db->fetchValue('SELECT `id` FROM `nx_services` WHERE `user_id` = ? AND `provisioned_at` IS NOT NULL ORDER BY `provisioned_at`, `id` LIMIT 1', [$userId]);
                // One invitation per customer: the unique key decides, also when two runs overlap.
                $inserted = $this->db->run(
                    'INSERT IGNORE INTO `nx_review_invitations` (`user_id`, `service_id`, `optout_token_hash`, `sent_at`) VALUES (?, ?, ?, ?)',
                    [$userId, $serviceId, hash('sha256', $token), $this->clock->nowString()],
                )->rowCount() === 1;
                if (!$inserted) {
                    return false;
                }
                $name = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
                $optOut = rtrim($this->config->string('app.url'), '/') . '/reviews/opt-out/' . $token;
                $this->mailer->queue(
                    (string) $row['email'],
                    $name !== '' ? $name : null,
                    'review_invitation',
                    ['name' => $name !== '' ? $name : (string) $row['email'], 'review_url' => $reviewUrl, 'optout_url' => $optOut],
                    is_string($row['language']) && $row['language'] !== '' ? $row['language'] : null,
                    headers: ['List-Unsubscribe' => '<' . $optOut . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
                    uniqueKey: 'review-invitation:' . $userId,
                );

                return true;
            });
            if ($queued) {
                $sent++;
            }
        }
        if ($sent > 0) {
            $this->logger->info('Review invitations queued', ['count' => $sent]);
        }

        return $sent;
    }

    /**
     * @return array<string, mixed>|null the invitation of the opt-out link
     */
    public function findByToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return $this->db->fetchOne('SELECT * FROM `nx_review_invitations` WHERE `optout_token_hash` = ?', [hash('sha256', $token)]);
    }

    /** Withdraws the consent of the invitation's customer (opt-out link). */
    public function optOut(string $token, ?string $ip): bool
    {
        $invitation = $this->findByToken($token);
        if ($invitation === null) {
            return false;
        }
        $this->consents->withdraw((int) $invitation['user_id'], Consents::REVIEW_INVITATION, 'email', $ip);

        return true;
    }
}
