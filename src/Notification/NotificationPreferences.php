<?php

declare(strict_types=1);

namespace Nebrix\Notification;

use Nebrix\Core\Clock;
use Nebrix\Database\Connection;

/**
 * Which customer notifications are sent by e-mail (and, optionally, as a
 * Discord direct message).
 *
 * Only the events listed here can be switched off. Invoices are legally
 * required documents and are always e-mailed; so are all e-mails that are
 * not listed (account and security notices, payment reminders, contract
 * declarations and their receipts, correction documents).
 */
class NotificationPreferences
{
    /**
     * event => label, help, the mail templates it covers, whether the
     * e-mail is mandatory (cannot be switched off)
     *
     * @var array<string, array{label: string, help: string, templates: list<string>, mandatory: bool}>
     */
    public const EVENTS = [
        'invoice_created' => ['label' => 'Invoice created', 'help' => 'Invoices are legally required documents and are always sent by e-mail.', 'templates' => ['invoice_created'], 'mandatory' => true],
        'payment_received' => ['label' => 'Payment received', 'help' => 'Confirmation when a payment has been booked.', 'templates' => ['payment_confirmation'], 'mandatory' => false],
        'service_ready' => ['label' => 'Service ready', 'help' => 'When a service you ordered has been set up. The access data stays available in the customer area.', 'templates' => ['service_provisioned'], 'mandatory' => false],
        'service_suspended' => ['label' => 'Service suspended', 'help' => 'When a service is suspended, e.g. because of an unpaid invoice.', 'templates' => ['service_suspended'], 'mandatory' => false],
        'ticket_reply' => ['label' => 'Ticket replies', 'help' => 'Replies from our support team and the closing of your tickets.', 'templates' => ['ticket_reply', 'ticket_closed'], 'mandatory' => false],
        'maintenance' => ['label' => 'Maintenance and incidents', 'help' => 'Announcements of maintenance and incidents that affect your services.', 'templates' => ['status_maintenance', 'status_incident'], 'mandatory' => false],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    public static function eventForTemplate(string $template): ?string
    {
        foreach (self::EVENTS as $event => $definition) {
            if (in_array($template, $definition['templates'], true)) {
                return $event;
            }
        }

        return null;
    }

    /**
     * The user's choices; events without a stored choice use the defaults
     * (e-mail on, Discord off). Mandatory e-mails are always on.
     *
     * @return array<string, array{email: bool, discord: bool}>
     */
    public function forUser(int $userId): array
    {
        $stored = [];
        foreach ($this->db->fetchAll('SELECT `event`, `email`, `discord` FROM `nx_notification_preferences` WHERE `user_id` = ?', [$userId]) as $row) {
            $stored[(string) $row['event']] = ['email' => (bool) $row['email'], 'discord' => (bool) $row['discord']];
        }
        $result = [];
        foreach (self::EVENTS as $event => $definition) {
            $choice = $stored[$event] ?? ['email' => true, 'discord' => false];
            if ($definition['mandatory']) {
                $choice['email'] = true;
            }
            $result[$event] = $choice;
        }

        return $result;
    }

    /**
     * @param list<string> $email   events to e-mail
     * @param list<string> $discord events to send as Discord direct message
     */
    public function save(int $userId, array $email, array $discord): void
    {
        $now = $this->clock->nowString();
        $this->db->transaction(function () use ($userId, $email, $discord, $now): void {
            foreach (self::EVENTS as $event => $definition) {
                $mail = $definition['mandatory'] || in_array($event, $email, true) ? 1 : 0;
                $dm = in_array($event, $discord, true) ? 1 : 0;
                $this->db->run(
                    'INSERT INTO `nx_notification_preferences` (`user_id`, `event`, `email`, `discord`, `updated_at`) VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE `email` = ?, `discord` = ?, `updated_at` = ?',
                    [$userId, $event, $mail, $dm, $now, $mail, $dm, $now],
                );
            }
        });
    }
}
