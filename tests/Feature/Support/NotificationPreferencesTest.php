<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature\Support;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Mail\Mailer;
use Nebrix\Notification\DiscordDirectMessageJob;
use Nebrix\Notification\NotificationRouter;
use Nebrix\Queue\PermanentJobFailure;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Support\TicketRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\FakeHttp;

/**
 * Customer notification preferences (mandatory e-mails stay on) and the
 * optional Discord direct messages (fake Discord API).
 */
final class NotificationPreferencesTest extends FeatureTestCase
{
    private const DISCORD_USER = '523456789012345678';

    private function mailCount(string $template, string $to): int
    {
        $count = 0;
        foreach ($this->db->fetchAll("SELECT `id` FROM `nx_jobs` WHERE `type` = 'mail.send' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.template')) = ?", [$template]) as $job) {
            $payload = json_decode((string) $this->db->fetchValue('SELECT `payload` FROM `nx_jobs` WHERE `id` = ?', [$job['id']]), true);
            $message = $this->app->container()->get(\Nebrix\Security\Encrypter::class)->decryptArray((string) $payload['message'], 'mail-job');
            $count += $message['to'] === $to ? 1 : 0;
        }

        return $count;
    }

    private function staffReply(User $customer): void
    {
        $ticketId = $this->app->container()->get(TicketRepository::class)->create($customer->id, 'Question', 'A question about my server.', null, 'normal');
        $this->actingAs($this->createUser(Role::SUPPORT));
        $this->post('/admin/tickets/' . $ticketId . '/reply', ['message' => 'Here is the answer.']);
    }

    public function testCustomersChooseOptionalEmailsButNotMandatoryOnes(): void
    {
        $customer = $this->createUser();
        $this->actingAs($customer);
        $page = $this->get('/account/notifications');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('Invoices are legally required documents and are always sent by e-mail.', $page->body());
        self::assertStringContainsString('always sent', $page->body());
        self::assertStringNotContainsString('name="email[]" value="invoice_created"', $page->body());
        self::assertStringContainsString('name="email[]" value="ticket_reply" checked', $page->body(), 'On by default.');

        // Everything off – including an attempt to switch off invoices.
        $this->post('/account/notifications', ['email' => ['invoice_created' => 'x'], 'discord' => ['ticket_reply']]);
        $this->post('/account/notifications', []);
        $stored = $this->db->fetchAll('SELECT `event`, `email`, `discord` FROM `nx_notification_preferences` WHERE `user_id` = ? ORDER BY `event`', [$customer->id]);
        self::assertSame(1, (int) array_column($stored, 'email', 'event')['invoice_created']);
        self::assertSame(0, (int) array_column($stored, 'email', 'event')['ticket_reply']);
        self::assertSame(0, array_sum(array_map('intval', array_column($stored, 'discord'))), 'No Discord messages without a linked account.');
        self::assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = 'account.notifications_updated'"));

        // Honoured when the e-mails are queued.
        $this->staffReply($customer);
        self::assertSame(0, $this->mailCount('ticket_reply', $customer->email));
        $mailer = $this->app->container()->get(Mailer::class);
        $mailer->queue($customer->email, null, 'invoice_created', ['name' => 'x', 'invoice_number' => 'R-1', 'invoice_total' => '1 €', 'due_date' => 'today', 'invoice_url' => 'http://localhost/invoices/1']);
        self::assertSame(1, $this->mailCount('invoice_created', $customer->email), 'Invoices are always sent.');
        $mailer->queue($customer->email, null, 'payment_confirmation', ['name' => 'x', 'invoice_number' => 'R-1', 'amount' => '1 €', 'gateway' => 'stripe']);
        self::assertSame(0, $this->mailCount('payment_confirmation', $customer->email));
        $mailer->queue($customer->email, null, 'password_changed', ['name' => 'x', 'ip' => '1.2.3.4', 'time' => 'now']);
        self::assertSame(1, $this->mailCount('password_changed', $customer->email), 'Security e-mails are not switchable.');

        // Switched on again.
        $this->actingAs($customer);
        $this->post('/account/notifications', ['email' => ['ticket_reply', 'maintenance']]);
        $this->staffReply($customer);
        self::assertSame(1, $this->mailCount('ticket_reply', $customer->email));

        // Other customers keep the defaults.
        $other = $this->createUser();
        $mailer->queue($other->email, null, 'payment_confirmation', ['name' => 'x', 'invoice_number' => 'R-2', 'amount' => '1 €', 'gateway' => 'stripe']);
        self::assertSame(1, $this->mailCount('payment_confirmation', $other->email));
    }

    public function testMaintenanceAnnouncementsFollowThePreference(): void
    {
        $services = $this->app->container()->get(\Nebrix\Service\ServiceRepository::class);
        $customers = [];
        foreach ([true, false] as $wants) {
            $customer = $this->createUser();
            $services->create(['user_id' => $customer->id, 'category' => 'lxc', 'name' => 'web', 'status' => 'active', 'billing_cycle' => 'monthly', 'recurring_amount' => 500, 'tax_rate_bp' => 1900, 'currency' => 'EUR', 'config' => []]);
            $this->actingAs($customer);
            $this->post('/account/notifications', ['email' => $wants ? ['maintenance'] : []]);
            $customers[] = $customer;
        }
        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/status/incidents', ['type' => 'maintenance', 'title' => 'Kernel updates', 'status' => 'scheduled', 'starts_at' => '2030-01-01T02:00', 'ends_at' => '2030-01-01T03:00', 'message' => 'Short reboots.', 'is_published' => '1', 'notify' => '1']);
        self::assertSame(1, $this->mailCount('status_maintenance', $customers[0]->email));
        self::assertSame(0, $this->mailCount('status_maintenance', $customers[1]->email));
    }

    public function testDiscordDirectMessagesForLinkedCustomers(): void
    {
        $http = new FakeHttp();
        $http->on('POST', '/api/v10/users/@me/channels', static fn ($request): Response => new Response(200, [], (string) json_encode(['id' => '723456789012345678', 'type' => 1, 'recipient' => FakeHttp::json($request)['recipient_id']])))
            ->on('POST', '/api/v10/channels/723456789012345678/messages', static fn (): Response => new Response(200, [], '{"id":"1"}'));
        $this->app->container()->set(ClientInterface::class, static fn (): ClientInterface => $http->client());
        $settings = $this->app->container()->get(SettingsRepository::class);
        $settings->set('discord.bot_token', 'bot-token');

        $customer = $this->createUser();
        $this->db->insert('nx_discord_links', ['user_id' => $customer->id, 'discord_id' => self::DISCORD_USER, 'username' => 'ada', 'linked_at' => $this->clock()->nowString()]);
        $this->actingAs($customer);

        // Not offered while switched off in the settings.
        self::assertStringNotContainsString('name="discord[]"', $this->get('/account/notifications')->body());
        $this->post('/account/notifications', ['email' => ['ticket_reply'], 'discord' => ['ticket_reply']]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT `discord` FROM `nx_notification_preferences` WHERE `user_id` = ? AND `event` = ?', [$customer->id, 'ticket_reply']));

        $settings->set('notifications.discord_dm', true);
        self::assertStringContainsString('name="discord[]"', $this->get('/account/notifications')->body());
        $this->post('/account/notifications', ['email' => [], 'discord' => ['ticket_reply']]);
        $this->staffReply($customer);
        self::assertSame(0, $this->mailCount('ticket_reply', $customer->email), 'Only the direct message was chosen.');
        $job = $this->db->fetchOne('SELECT `id`, `payload` FROM `nx_jobs` WHERE `type` = ?', [NotificationRouter::JOB_DISCORD_DM]);
        self::assertNotNull($job);
        $payload = (array) json_decode((string) $job['payload'], true);
        self::assertSame($customer->id, $payload['user_id']);
        self::assertStringStartsWith('New reply to your ticket "Question": http://localhost/tickets/', (string) $payload['text']);

        $this->app->container()->get(DiscordDirectMessageJob::class)->handle($payload);
        self::assertSame(['recipient_id' => self::DISCORD_USER], FakeHttp::json($http->requests('POST', '/api/v10/users/@me/channels')[0]));
        $message = $http->requests('POST', '/api/v10/channels/\d+/messages')[0];
        self::assertSame('Bot bot-token', $message->getHeaderLine('Authorization'));
        self::assertSame(['content' => $payload['text'], 'allowed_mentions' => ['parse' => []]], FakeHttp::json($message));

        // Discord refuses (user does not accept direct messages): not retried.
        $http->on('POST', '/api/v10/channels/723456789012345678/messages', static fn (): Response => new Response(403, [], '{"message":"Cannot send messages to this user","code":50007}'));
        try {
            $this->app->container()->get(DiscordDirectMessageJob::class)->handle($payload);
            self::fail('A refused direct message must not be retried.');
        } catch (PermanentJobFailure) {
        }

        // Unlinked accounts get nothing.
        $this->db->delete('nx_discord_links', ['user_id' => $customer->id]);
        $before = count($http->history);
        $this->app->container()->get(DiscordDirectMessageJob::class)->handle($payload);
        self::assertCount($before, $http->history);
    }
}
