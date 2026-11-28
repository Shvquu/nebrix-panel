<?php

declare(strict_types=1);

namespace Nebrix\Marketing;

use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Mail\Mailer;
use Nebrix\Queue\JobQueue;
use Nebrix\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Newsletter campaigns: written in Markdown, previewed, sent as a test to
 * the staff member's own address and then sent through the job queue.
 *
 * Sending creates one delivery per confirmed subscriber (unique per
 * campaign and subscriber). The job "newsletter.batch" takes the next
 * batch of deliveries under a row lock of the campaign, queues one e-mail
 * per recipient (job key per campaign and subscriber, so no recipient gets
 * a campaign twice, also after a crash or a retry) and schedules the next
 * batch after the configured interval (throttling). Recipients who
 * unsubscribed in the meantime are skipped.
 */
class NewsletterCampaigns
{
    public const JOB_BATCH = 'newsletter.batch';

    public const DRAFT = 'draft';
    public const SENDING = 'sending';
    public const SENT = 'sent';
    public const CANCELLED = 'cancelled';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SettingsRepository $settings,
        private readonly Mailer $mailer,
        private readonly JobQueue $jobs,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM `nx_newsletter_campaigns` ORDER BY `id` DESC LIMIT 200');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_newsletter_campaigns` WHERE `id` = ?', [$id]);
    }

    /**
     * @param array{subject: string, body: string, audience: string} $data
     */
    public function save(?int $id, array $data, int $actorId): int
    {
        $now = $this->clock->nowString();
        if ($id === null) {
            return $this->db->insert('nx_newsletter_campaigns', $data + ['status' => self::DRAFT, 'created_by' => $actorId, 'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
        }
        // Only drafts can be changed: recipients must get what was sent to the others.
        $changed = $this->db->run(
            'UPDATE `nx_newsletter_campaigns` SET `subject` = ?, `body` = ?, `audience` = ?, `updated_by` = ?, `updated_at` = ? WHERE `id` = ? AND `status` = ?',
            [$data['subject'], $data['body'], $data['audience'], $actorId, $now, $id, self::DRAFT],
        )->rowCount();
        if ($changed === 0 && ($this->find($id)['status'] ?? null) !== self::DRAFT) {
            throw new RuntimeException('Only drafts can be changed.');
        }

        return $id;
    }

    public function delete(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $campaign = $this->db->fetchOne('SELECT `status` FROM `nx_newsletter_campaigns` WHERE `id` = ? FOR UPDATE', [$id]);
            // Sent e-mails keep working unsubscribe links: their deliveries are never deleted.
            if ($campaign === null || $campaign['status'] !== self::DRAFT) {
                throw new RuntimeException('Only drafts can be deleted.');
            }
            $this->db->run('DELETE FROM `nx_newsletter_deliveries` WHERE `campaign_id` = ?', [$id]);
            $this->db->run('DELETE FROM `nx_newsletter_campaigns` WHERE `id` = ?', [$id]);
        });
    }

    /** Confirmed subscribers the campaign would reach. */
    public function audienceSize(string $audience): int
    {
        [$where, $params] = $this->audienceFilter($audience);

        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_newsletter_subscribers` s WHERE ' . $where, $params);
    }

    /** The campaign rendered for the preview (safe HTML). */
    public function previewHtml(string $body): string
    {
        return Markdown::toHtml($body, rtrim($this->config->string('app.url'), '/'), true);
    }

    /** Sends the campaign once to one address (the staff member's own) – marked as a test. */
    public function sendTest(int $id, string $email, ?string $name, string $locale): void
    {
        $campaign = $this->find($id) ?? throw new RuntimeException('Not found.');
        $this->mailer->queue($email, $name, 'newsletter', [
            'subject' => '[Test] ' . $campaign['subject'],
            // A test has no subscription; the link leads to the newsletter page.
            'unsubscribe_url' => rtrim($this->config->string('app.url'), '/') . '/newsletter',
        ], $locale, html: ['content' => $this->previewHtml((string) $campaign['body'])]);
    }

    /**
     * Starts sending: one delivery per confirmed subscriber of the
     * audience, then the first batch job.
     *
     * @return int number of recipients
     */
    public function start(int $id, int $actorId): int
    {
        return $this->db->transaction(function () use ($id, $actorId): int {
            $campaign = $this->db->fetchOne('SELECT * FROM `nx_newsletter_campaigns` WHERE `id` = ? FOR UPDATE', [$id]);
            if ($campaign === null || $campaign['status'] !== self::DRAFT) {
                throw new RuntimeException('This campaign has already been sent.');
            }
            [$where, $params] = $this->audienceFilter((string) $campaign['audience']);
            $now = $this->clock->nowString();
            $this->db->run(
                'INSERT IGNORE INTO `nx_newsletter_deliveries` (`campaign_id`, `subscriber_id`, `status`, `created_at`)
                 SELECT ?, s.`id`, \'pending\', ? FROM `nx_newsletter_subscribers` s WHERE ' . $where,
                [$id, $now, ...$params],
            );
            $recipients = (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_newsletter_deliveries` WHERE `campaign_id` = ?', [$id]);
            $this->db->update('nx_newsletter_campaigns', ['status' => self::SENDING, 'recipients' => $recipients, 'started_at' => $now, 'updated_by' => $actorId, 'updated_at' => $now], ['id' => $id]);
            $this->jobs->dispatch(self::JOB_BATCH, ['campaign_id' => $id, 'batch' => 1], 'newsletter-batch:' . $id . ':1', 10);

            return $recipients;
        });
    }

    /** Stops a running campaign; deliveries not yet queued are not sent. */
    public function cancel(int $id): bool
    {
        return $this->db->run(
            'UPDATE `nx_newsletter_campaigns` SET `status` = ?, `finished_at` = ?, `updated_at` = ? WHERE `id` = ? AND `status` = ?',
            [self::CANCELLED, $this->clock->nowString(), $this->clock->nowString(), $id, self::SENDING],
        )->rowCount() === 1;
    }

    /**
     * Job newsletter.batch: queues the next batch of e-mails and schedules
     * the following batch (idempotent: deliveries are claimed under the
     * campaign's row lock, every e-mail has its own job key).
     */
    public function processBatch(int $id, int $batch): void
    {
        $this->db->transaction(function () use ($id, $batch): void {
            $campaign = $this->db->fetchOne('SELECT * FROM `nx_newsletter_campaigns` WHERE `id` = ? FOR UPDATE', [$id]);
            if ($campaign === null || $campaign['status'] !== self::SENDING) {
                return;
            }
            $size = max(1, min(1000, $this->settings->int('newsletter.batch_size') ?: 50));
            $deliveries = $this->db->fetchAll(
                'SELECT d.`id`, d.`subscriber_id`, s.`email`, s.`status` AS `subscriber_status`, s.`locale`
                   FROM `nx_newsletter_deliveries` d LEFT JOIN `nx_newsletter_subscribers` s ON s.`id` = d.`subscriber_id`
                  WHERE d.`campaign_id` = ? AND d.`status` = \'pending\' ORDER BY d.`id` LIMIT ' . $size,
                [$id],
            );
            $content = $this->previewHtml((string) $campaign['body']);
            $base = rtrim($this->config->string('app.url'), '/');
            $now = $this->clock->nowString();
            $queued = 0;
            $skipped = 0;
            foreach ($deliveries as $delivery) {
                // Never to addresses that are not (or no longer) confirmed.
                if ($delivery['subscriber_status'] !== Newsletter::CONFIRMED || !is_string($delivery['email'])) {
                    $this->db->update('nx_newsletter_deliveries', ['status' => 'skipped'], ['id' => (int) $delivery['id']]);
                    $skipped++;
                    continue;
                }
                $token = bin2hex(random_bytes(32));
                $unsubscribe = $base . '/newsletter/unsubscribe/' . $token;
                $this->db->update('nx_newsletter_deliveries', ['status' => 'queued', 'unsubscribe_token_hash' => hash('sha256', $token), 'queued_at' => $now], ['id' => (int) $delivery['id']]);
                $this->mailer->queue(
                    $delivery['email'],
                    null,
                    'newsletter',
                    ['subject' => (string) $campaign['subject'], 'unsubscribe_url' => $unsubscribe],
                    (string) $delivery['locale'],
                    headers: ['List-Unsubscribe' => '<' . $unsubscribe . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
                    html: ['content' => $content],
                    uniqueKey: 'newsletter-mail:' . $id . ':' . $delivery['subscriber_id'],
                );
                $queued++;
            }
            $this->db->run(
                'UPDATE `nx_newsletter_campaigns` SET `queued_count` = `queued_count` + ?, `skipped_count` = `skipped_count` + ?, `updated_at` = ? WHERE `id` = ?',
                [$queued, $skipped, $now, $id],
            );
            $remaining = (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_newsletter_deliveries` WHERE `campaign_id` = ? AND `status` = 'pending'", [$id]);
            if ($remaining > 0) {
                $interval = max(10, min(3600, $this->settings->int('newsletter.batch_interval') ?: 60));
                $this->jobs->dispatch(self::JOB_BATCH, ['campaign_id' => $id, 'batch' => $batch + 1], 'newsletter-batch:' . $id . ':' . ($batch + 1), 10, $this->clock->now()->modify('+' . $interval . ' seconds'));
            } else {
                $this->db->update('nx_newsletter_campaigns', ['status' => self::SENT, 'finished_at' => $now], ['id' => $id]);
                $this->logger->info('Newsletter campaign sent', ['campaign' => $id, 'recipients' => (int) $campaign['recipients']]);
            }
        });
    }

    /**
     * Delivery state of the campaign's e-mails in the job queue.
     *
     * @return array{delivered: int, waiting: int, failed: int}
     */
    public function mailStats(int $id): array
    {
        $stats = ['delivered' => 0, 'waiting' => 0, 'failed' => 0];
        foreach ($this->db->fetchAll('SELECT `status`, COUNT(*) AS `n` FROM `nx_jobs` WHERE `unique_key` LIKE ? GROUP BY `status`', ['newsletter-mail:' . $id . ':%']) as $row) {
            $key = match ((string) $row['status']) {
                JobQueue::STATUS_COMPLETED => 'delivered',
                JobQueue::STATUS_FAILED, JobQueue::STATUS_CANCELLED => 'failed',
                default => 'waiting',
            };
            $stats[$key] += (int) $row['n'];
        }

        return $stats;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function audienceFilter(string $audience): array
    {
        $where = "s.`status` = 'confirmed'";
        $params = [];
        if ($audience !== 'all') {
            $where .= ' AND s.`locale` = ?';
            $params[] = $audience;
        }

        return [$where, $params];
    }
}
