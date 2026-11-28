<?php

declare(strict_types=1);

namespace Nebrix\Notification;

use Nebrix\Billing\Money;
use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Queue\JobQueue;
use Nebrix\Settings\SettingsRepository;
use Nebrix\View\Translator;
use PDOException;

/**
 * Staff notifications in a Discord channel (webhook): new orders, new
 * tickets, failed payments and failed provisioning.
 *
 * A scheduler task collects new events every minute and queues one post
 * per event; nx_staff_notifications records what was posted, so nothing
 * is posted twice. When the webhook is configured, only events from then
 * on are posted (no backlog). Messages contain numbers, amounts and links
 * to the admin panel – no customer names, e-mail addresses or ticket texts.
 */
class StaffNotifier
{
    public const JOB = 'notifications.staff_discord';
    public const KINDS = ['order', 'ticket', 'payment_failed', 'provisioning_failed'];
    private const BATCH = 50;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SettingsRepository $settings,
        private readonly JobQueue $jobs,
        private readonly Config $config,
        private readonly Translator $translator,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->string('notifications.staff_webhook_url') !== '';
    }

    /** Scheduler: queues posts for new events; returns how many were queued. */
    public function collect(): int
    {
        if (!$this->enabled()) {
            return 0;
        }
        $queued = 0;
        $kinds = array_values(array_intersect(self::KINDS, $this->settings->list('notifications.staff_events')));
        foreach ($kinds as $kind) {
            $baseline = $this->baseline($kind);
            if ($baseline === null) {
                continue;
            }
            foreach ($this->events($kind, $baseline) as $event) {
                if ($this->record($event['key'], $kind, $event['ref'])) {
                    $this->jobs->dispatch(self::JOB, ['content' => $event['text']], 'staff-discord:' . $event['key'], 5);
                    $queued++;
                }
            }
        }

        return $queued;
    }

    /**
     * Where to start: the newest ID and the time when posting started for
     * this kind. The first run only records the starting point.
     *
     * @return array{ref: int, since: string}|null
     */
    private function baseline(string $kind): ?array
    {
        $row = $this->db->fetchOne('SELECT `created_at` FROM `nx_staff_notifications` WHERE `event_key` = ?', ['baseline:' . $kind]);
        if ($row === null) {
            $max = match ($kind) {
                'order' => $this->db->fetchValue('SELECT MAX(`id`) FROM `nx_orders`'),
                'ticket' => $this->db->fetchValue('SELECT MAX(`id`) FROM `nx_tickets`'),
                default => 0,
            };
            $this->record('baseline:' . $kind, $kind, (int) $max);

            return null;
        }
        $last = (int) $this->db->fetchValue('SELECT MAX(`ref_id`) FROM `nx_staff_notifications` WHERE `kind` = ?', [$kind]);
        $dayAgo = $this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s');

        return ['ref' => $last, 'since' => max($dayAgo, (string) $row['created_at'])];
    }

    /**
     * @param array{ref: int, since: string} $baseline
     * @return list<array{key: string, ref: int, text: string}>
     */
    private function events(string $kind, array $baseline): array
    {
        $locale = $this->settings->string('general.default_language') ?: 'en';
        $t = fn (string $text, array $replace): string => $this->translator->translateTo($locale, $text, $replace);
        $events = [];
        switch ($kind) {
            case 'order':
                foreach ($this->db->fetchAll('SELECT `id`, `number`, `total`, `currency` FROM `nx_orders` WHERE `id` > ? ORDER BY `id` LIMIT ' . self::BATCH, [$baseline['ref']]) as $row) {
                    $events[] = ['key' => 'order:' . $row['id'], 'ref' => (int) $row['id'], 'text' => $t('New order :number (:total): :url', [
                        'number' => (string) $row['number'], 'total' => Money::format((int) $row['total'], (string) $row['currency'], $locale), 'url' => $this->url('/admin/orders/' . $row['id']),
                    ])];
                }
                break;
            case 'ticket':
                foreach ($this->db->fetchAll('SELECT `id`, `priority` FROM `nx_tickets` WHERE `id` > ? ORDER BY `id` LIMIT ' . self::BATCH, [$baseline['ref']]) as $row) {
                    $events[] = ['key' => 'ticket:' . $row['id'], 'ref' => (int) $row['id'], 'text' => $t('New ticket #:id (priority :priority): :url', [
                        'id' => (int) $row['id'], 'priority' => $t(ucfirst((string) $row['priority']), []), 'url' => $this->url('/admin/tickets/' . $row['id']),
                    ])];
                }
                break;
            case 'payment_failed':
                foreach ($this->db->fetchAll(
                    "SELECT t.`id`, t.`gateway`, t.`amount`, t.`currency`, t.`invoice_id`, t.`updated_at`, i.`number` FROM `nx_transactions` t LEFT JOIN `nx_invoices` i ON i.`id` = t.`invoice_id`
                      WHERE t.`status` = 'failed' AND t.`type` = 'payment' AND t.`updated_at` >= ? ORDER BY t.`updated_at`, t.`id` LIMIT " . self::BATCH,
                    [$baseline['since']],
                ) as $row) {
                    $events[] = ['key' => 'payment_failed:' . $row['id'] . ':' . $row['updated_at'], 'ref' => 0, 'text' => $t('Failed payment via :gateway (:amount) for invoice :number: :url', [
                        'gateway' => (string) $row['gateway'], 'amount' => Money::format((int) $row['amount'], (string) $row['currency'], $locale),
                        'number' => (string) ($row['number'] ?? '–'), 'url' => $this->url($row['invoice_id'] !== null ? '/admin/invoices/' . $row['invoice_id'] : '/admin/transactions'),
                    ])];
                }
                break;
            case 'provisioning_failed':
                foreach ($this->db->fetchAll(
                    "SELECT `id`, `category`, `updated_at` FROM `nx_services` WHERE `status` = 'failed' AND `updated_at` >= ? ORDER BY `updated_at`, `id` LIMIT " . self::BATCH,
                    [$baseline['since']],
                ) as $row) {
                    $events[] = ['key' => 'provisioning_failed:' . $row['id'] . ':' . $row['updated_at'], 'ref' => 0, 'text' => $t('Provisioning failed for service #:id (:category): :url', [
                        'id' => (int) $row['id'], 'category' => (string) $row['category'], 'url' => $this->url('/admin/services/' . $row['id']),
                    ])];
                }
                break;
        }

        return $events;
    }

    /** Records an event once; false when it was recorded before. */
    private function record(string $key, string $kind, int $ref): bool
    {
        try {
            $this->db->insert('nx_staff_notifications', ['event_key' => mb_substr($key, 0, 120), 'kind' => $kind, 'ref_id' => max(0, $ref), 'created_at' => $this->clock->nowString()]);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    private function url(string $path): string
    {
        return rtrim($this->config->string('app.url'), '/') . $path;
    }
}
