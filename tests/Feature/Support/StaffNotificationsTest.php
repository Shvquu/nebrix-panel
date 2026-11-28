<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature\Support;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Nebrix\Auth\Role;
use Nebrix\Notification\StaffNotifier;
use Nebrix\Notification\StaffWebhookJob;
use Nebrix\Provisioning\RetryLaterException;
use Nebrix\Queue\PermanentJobFailure;
use Nebrix\Security\UrlGuard;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Support\TicketRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\FakeHttp;

/**
 * Staff notifications in a Discord channel webhook: URL validation, the
 * collected events (no customer data, no duplicates, no backlog) and the
 * queued post through the UrlGuard (fake Discord, fake DNS).
 */
final class StaffNotificationsTest extends FeatureTestCase
{
    private const WEBHOOK = 'https://discord.com/api/webhooks/123456789012345678/AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abcdef';

    private FakeHttp $http;

    /** @var list<string> */
    private array $resolved = ['162.159.135.232'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp();
        $this->app->container()->set(ClientInterface::class, fn (): ClientInterface => $this->http->client());
        $this->app->container()->set(UrlGuard::class, fn (): UrlGuard => new UrlGuard(false, false, fn (): array => $this->resolved));
    }

    /**
     * @return list<string>
     */
    private function queued(): array
    {
        return array_map(static fn (array $row): string => (string) json_decode((string) $row['payload'], true)['content'], $this->db->fetchAll('SELECT `payload` FROM `nx_jobs` WHERE `type` = ? ORDER BY `id`', [StaffNotifier::JOB]));
    }

    public function testTheWebhookUrlMustBeADiscordWebhook(): void
    {
        $this->actingAs($this->createUser(Role::SUPER_ADMIN));
        $settings = $this->app->container()->get(SettingsRepository::class);
        foreach (['https://evil.example/api/webhooks/123456789012345678/' . str_repeat('a', 40), 'http://discord.com/api/webhooks/123456789012345678/' . str_repeat('a', 40), 'https://discord.com.evil.example/api/webhooks/123456789012345678/' . str_repeat('a', 40)] as $url) {
            $this->post('/admin/settings/system', ['group' => 'notifications', 'notifications__staff_webhook_url' => $url, 'notifications__staff_events' => ['order']]);
            self::assertFalse($settings->isSet('notifications.staff_webhook_url'), $url);
        }
        $this->post('/admin/settings/system', ['group' => 'notifications', 'notifications__staff_webhook_url' => self::WEBHOOK, 'notifications__staff_events' => ['order', 'ticket']]);
        $settings->flush();
        self::assertSame(self::WEBHOOK, $settings->string('notifications.staff_webhook_url'));
        self::assertNotSame(self::WEBHOOK, $this->db->fetchValue("SELECT `value` FROM `nx_settings` WHERE `key` = 'notifications.staff_webhook_url'"), 'Stored encrypted.');
        self::assertStringNotContainsString(self::WEBHOOK, (string) json_encode($this->db->fetchAll("SELECT `details` FROM `nx_audit_log` WHERE `action` = 'settings.updated'")));
    }

    public function testEventsArePostedOnceWithoutCustomerData(): void
    {
        $notifier = $this->app->container()->get(StaffNotifier::class);
        self::assertSame(0, $notifier->collect(), 'Off without a webhook.');

        $customer = $this->createUser(Role::CUSTOMER, ['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace.private@example.test']);
        $tickets = $this->app->container()->get(TicketRepository::class);
        $old = $tickets->create($customer->id, 'Old ticket before setup', 'Created before the webhook existed.', null, 'normal');

        $this->app->container()->get(SettingsRepository::class)->set('notifications.staff_webhook_url', self::WEBHOOK);
        self::assertSame(0, $notifier->collect(), 'The first run only records where to start.');

        $ticket = $tickets->create($customer->id, 'My password is hunter2', 'Private details of Grace.', null, 'high');
        $now = $this->clock()->nowString();
        $order = $this->db->insert('nx_orders', ['number' => 'ORD-2026-0042', 'user_id' => $customer->id, 'status' => 'pending', 'currency' => 'EUR', 'subtotal' => 1000, 'total' => 1190, 'tax' => 190, 'created_at' => $now, 'updated_at' => $now]);
        $invoice = $this->db->insert('nx_invoices', ['number' => 'R-2026-0042', 'user_id' => $customer->id, 'type' => 'order', 'status' => 'unpaid', 'currency' => 'EUR', 'subtotal' => 1000, 'tax' => 190, 'total' => 1190, 'due_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('nx_transactions', ['invoice_id' => $invoice, 'user_id' => $customer->id, 'gateway' => 'stripe', 'type' => 'payment', 'status' => 'failed', 'amount' => 1190, 'currency' => 'EUR', 'reference' => 'pi_failed', 'failure_reason' => 'card declined', 'created_at' => $now, 'updated_at' => $now]);
        $service = $this->app->container()->get(\Nebrix\Service\ServiceRepository::class)->create(['user_id' => $customer->id, 'category' => 'lxc', 'name' => 'grace-private-host', 'status' => 'failed', 'billing_cycle' => 'monthly', 'recurring_amount' => 500, 'tax_rate_bp' => 1900, 'currency' => 'EUR', 'config' => []]);

        self::assertSame(4, $notifier->collect());
        $posts = $this->queued();
        self::assertCount(4, $posts);
        $all = implode("\n", $posts);
        self::assertStringContainsString('New ticket #' . $ticket . ' (priority High): http://localhost/admin/tickets/' . $ticket, $all);
        self::assertStringContainsString('New order ORD-2026-0042', $all);
        self::assertStringContainsString('http://localhost/admin/orders/' . $order, $all);
        self::assertStringContainsString('Failed payment via stripe', $all);
        self::assertStringContainsString('R-2026-0042', $all);
        self::assertStringContainsString('Provisioning failed for service #' . $service . ' (lxc)', $all);
        foreach (['Grace', 'Hopper', 'grace.private@example.test', 'hunter2', 'grace-private-host', 'card declined', 'Old ticket'] as $private) {
            self::assertStringNotContainsString($private, $all);
        }
        self::assertStringNotContainsString('New ticket #' . $old . ' ', $all, 'No backlog from before the setup.');

        // Nothing twice.
        self::assertSame(0, $notifier->collect());
        self::assertCount(4, $this->queued());

        // Only the chosen events.
        $this->app->container()->get(SettingsRepository::class)->set('notifications.staff_events', ['order']);
        $tickets->create($customer->id, 'Another', 'Another ticket.', null, 'normal');
        self::assertSame(0, $notifier->collect());
    }

    public function testThePostGoesThroughTheUrlGuardAndHandlesDiscordAnswers(): void
    {
        $settings = $this->app->container()->get(SettingsRepository::class);
        $settings->set('notifications.staff_webhook_url', self::WEBHOOK);
        $status = 204;
        $this->http->on('POST', '/api/webhooks/123456789012345678/[A-Za-z0-9_-]+', static function () use (&$status): Response {
            return $status === 429 ? new Response(429, [], '{"retry_after": 2.2}') : new Response($status);
        });
        $job = $this->app->container()->get(StaffWebhookJob::class);

        $job->handle(['content' => 'New ticket #1 (priority High): http://localhost/admin/tickets/1 @everyone']);
        $request = $this->http->last();
        self::assertSame(self::WEBHOOK, (string) $request->getUri());
        self::assertSame(['content' => 'New ticket #1 (priority High): http://localhost/admin/tickets/1 @everyone', 'allowed_mentions' => ['parse' => []]], FakeHttp::json($request));
        $options = $this->http->history[0]['options'];
        self::assertFalse($options['allow_redirects']);
        self::assertSame(['discord.com:443:162.159.135.232'], $options['curl'][CURLOPT_RESOLVE], 'The checked address is pinned.');

        $status = 429;
        try {
            $job->handle(['content' => 'x']);
            self::fail('A rate limit must be retried.');
        } catch (RetryLaterException $retry) {
            self::assertSame(3, $retry->delaySeconds);
        }
        $status = 404;
        try {
            $job->handle(['content' => 'x']);
            self::fail('A deleted webhook is not retried.');
        } catch (PermanentJobFailure $failure) {
            self::assertStringNotContainsString('AbCdEf', $failure->getMessage(), 'The webhook token is never part of errors.');
        }

        // DNS pointing at an internal address (SSRF) is refused before any request.
        $this->resolved = ['169.254.169.254'];
        $this->app->container()->set(UrlGuard::class, fn (): UrlGuard => new UrlGuard(false, false, fn (): array => $this->resolved));
        $requests = count($this->http->history);
        try {
            $this->app->container()->get(StaffWebhookJob::class)->handle(['content' => 'x']);
            self::fail('A webhook resolving to a metadata address must be refused.');
        } catch (PermanentJobFailure) {
        }
        self::assertCount($requests, $this->http->history);

        // A URL that is no Discord webhook (e.g. set before validation) is never called.
        $settings->set('notifications.staff_webhook_url', 'https://example.test/hook');
        try {
            $this->app->container()->get(StaffWebhookJob::class)->handle(['content' => 'x']);
            self::fail('Only Discord webhooks are called.');
        } catch (PermanentJobFailure) {
        }
        self::assertCount($requests, $this->http->history);
    }
}
