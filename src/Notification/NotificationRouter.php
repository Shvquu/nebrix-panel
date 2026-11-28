<?php

declare(strict_types=1);

namespace Nebrix\Notification;

use Nebrix\Database\Connection;
use Nebrix\Queue\JobQueue;
use Nebrix\Settings\SettingsRepository;
use Nebrix\View\Translator;

/**
 * Applies the customer's notification preferences to an e-mail before it
 * is queued (called by the Mailer) and queues the optional Discord direct
 * message for the same event.
 *
 * E-mails to addresses without an account, templates that are not one of
 * the switchable events and mandatory e-mails (invoices) are always sent.
 */
class NotificationRouter
{
    public const JOB_DISCORD_DM = 'notifications.discord_dm';

    /** Short, generic texts for direct messages (no access data, only a link). */
    private const DM_TEXTS = [
        'invoice_created' => 'New invoice :number (:total): :url',
        'payment_confirmation' => 'Payment received for invoice :number.',
        'service_provisioned' => 'Your service ":service" is ready: :url',
        'service_suspended' => 'Your service ":service" has been suspended: :url',
        'ticket_reply' => 'New reply to your ticket ":subject": :url',
        'ticket_closed' => 'Your ticket ":subject" has been closed: :url',
        'status_maintenance' => 'Maintenance announced: :title – :url',
        'status_incident' => 'Incident: :title – :url',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
        private readonly JobQueue $jobs,
        private readonly Translator $translator,
    ) {
    }

    /**
     * @param array<string, scalar|null> $variables the e-mail's template variables
     * @return bool whether the e-mail should be sent
     */
    public function route(string $toEmail, string $template, array $variables): bool
    {
        $event = NotificationPreferences::eventForTemplate($template);
        if ($event === null) {
            return true;
        }
        $row = $this->db->fetchOne(
            'SELECT u.`id`, u.`language`, p.`email` AS pref_email, p.`discord` AS pref_discord, l.`discord_id`
               FROM `nx_users` u
               LEFT JOIN `nx_notification_preferences` p ON p.`user_id` = u.`id` AND p.`event` = ?
               LEFT JOIN `nx_discord_links` l ON l.`user_id` = u.`id`
              WHERE u.`email` = ?',
            [$event, mb_strtolower($toEmail)],
        );
        if ($row === null) {
            return true;
        }

        if ((bool) $row['pref_discord'] && $row['discord_id'] !== null && $this->directMessagesAvailable()) {
            $this->jobs->dispatch(self::JOB_DISCORD_DM, [
                'user_id' => (int) $row['id'],
                'text' => $this->directMessage($template, $variables, (string) ($row['language'] ?? 'en')),
            ], null, 3);
        }

        return NotificationPreferences::EVENTS[$event]['mandatory'] || $row['pref_email'] === null || (bool) $row['pref_email'];
    }

    /** Direct messages are sent by the Discord bot of the Discord integration. */
    public function directMessagesAvailable(): bool
    {
        return $this->settings->bool('notifications.discord_dm') && $this->settings->string('discord.bot_token') !== '';
    }

    /**
     * @param array<string, scalar|null> $variables
     */
    private function directMessage(string $template, array $variables, string $locale): string
    {
        $value = static function (int $max, string ...$keys) use ($variables): string {
            foreach ($keys as $key) {
                $text = trim((string) ($variables[$key] ?? ''));
                if ($text !== '') {
                    return mb_substr($text, 0, $max);
                }
            }

            return '';
        };
        $text = $this->translator->translateTo($locale, self::DM_TEXTS[$template] ?? 'You have a new notification: :url', [
            'number' => $value(60, 'invoice_number'),
            'total' => $value(40, 'invoice_total'),
            'service' => $value(150, 'service_name'),
            'subject' => $value(150, 'ticket_subject'),
            'title' => $value(150, 'title'),
            'url' => $value(500, 'ticket_url', 'service_url', 'invoice_url', 'status_url'),
        ]);

        return mb_substr(trim($text), 0, 1900);
    }
}
