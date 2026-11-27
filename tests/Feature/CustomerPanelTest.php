<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use Nebrix\Auth\Role;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;

final class CustomerPanelTest extends FeatureTestCase
{
    public function testDashboardShowsAccountOverview(): void
    {
        $customer = $this->createUser(Role::CUSTOMER, ['first_name' => 'Ada']);
        $this->db->insert('nx_services', ['user_id' => $customer->id, 'category' => 'teamspeak', 'name' => 'Clan TS', 'status' => 'active', 'billing_cycle' => 'monthly', 'recurring_amount' => 300, 'currency' => 'EUR', 'next_due_at' => '2026-12-01 00:00:00', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->app->container()->get(SettingsRepository::class)->set('branding.dashboard_welcome', 'Planned maintenance on Sunday.');
        $this->actingAs($customer);

        $body = $this->get('/dashboard')->body();

        self::assertStringContainsString('class="p-profile-name">' . $customer->displayName(), $body);
        self::assertStringContainsString('C' . $customer->id, $body, 'Customer number in the profile card.');
        self::assertStringContainsString('Clan TS', $body);
        self::assertStringContainsString('Planned maintenance on Sunday.', $body);
        self::assertSame(1, preg_match('/id="support-pin" class="mono">(\d{3}-\d{3}-\d{3})</', $body, $pin), 'Support PIN shown.');
        self::assertStringContainsString('€3.00', $body, 'Monthly costs of the TeamSpeak server.');
        preg_match('/id="support-pin" class="mono">(\d{3}-\d{3}-\d{3})</', $this->get('/dashboard')->body(), $again);
        self::assertSame($pin[1], $again[1] ?? null, 'The PIN stays the same.');

        // Store filter by group and search.
        $this->app->container()->get(\Nebrix\Repository\ProductRepository::class)->create([
            'category' => 'teamspeak', 'name' => 'TS Starter', 'slug' => 'ts-starter', 'price' => 300, 'setup_fee' => 0, 'currency' => 'EUR',
            'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => [],
        ]);
        self::assertStringContainsString('TS Starter', $this->get('/store', ['group' => 'teamspeak'])->body());
        self::assertStringNotContainsString('TS Starter', $this->get('/store', ['group' => 'server'])->body());
        self::assertStringContainsString('TS Starter', $this->get('/store', ['search' => 'starter'])->body());
        self::assertStringNotContainsString('TS Starter', $this->get('/store', ['search' => 'minecraft'])->body());
    }

    public function testInterfaceLanguageFollowsTheUserPreference(): void
    {
        $customer = $this->createUser(Role::CUSTOMER, ['language' => 'de', 'first_name' => 'Ada']);
        $this->actingAs($customer);

        $body = $this->get('/dashboard')->body();

        self::assertStringContainsString('Kundennummer', $body);
        self::assertStringContainsString('Meine Dienste', $body);
        self::assertStringContainsString('<html lang="de"', $body);
    }

    public function testTicketConversationBetweenCustomerAndSupport(): void
    {
        $customer = $this->createUser();
        $serviceId = $this->db->insert('nx_services', ['user_id' => $customer->id, 'category' => 'lxc', 'name' => 'web01', 'status' => 'active', 'billing_cycle' => 'monthly', 'currency' => 'EUR', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->actingAs($customer);

        $this->post('/tickets', ['subject' => 'Server unreachable', 'message' => 'My server <b>web01</b> does not respond.', 'priority' => 'high', 'service_id' => (string) $serviceId]);
        $ticketId = (int) $this->db->fetchValue('SELECT id FROM nx_tickets WHERE user_id = ?', [$customer->id]);
        self::assertGreaterThan(0, $ticketId);

        $page = $this->get('/tickets/' . $ticketId)->body();
        self::assertStringContainsString('&lt;b&gt;web01&lt;/b&gt;', $page, 'Messages are escaped.');

        $support = $this->createUser(Role::SUPPORT);
        $this->actingAs($support);
        self::assertSame(200, $this->get('/admin/tickets')->status());
        $this->post('/admin/tickets/' . $ticketId . '/reply', ['message' => 'We restarted the node, please check again.']);
        self::assertSame('answered', $this->db->fetchValue('SELECT status FROM nx_tickets WHERE id = ?', [$ticketId]));
        self::assertSame($customer->email, $this->lastMail('ticket_reply')['to'] ?? null);

        $this->post('/admin/tickets/' . $ticketId, ['status' => 'closed', 'priority' => 'normal', 'assigned_to' => (string) $support->id]);
        self::assertSame('closed', $this->db->fetchValue('SELECT status FROM nx_tickets WHERE id = ?', [$ticketId]));
        self::assertSame($support->id, (int) $this->db->fetchValue('SELECT assigned_to FROM nx_tickets WHERE id = ?', [$ticketId]));

        // Assigning a ticket to a customer account is rejected.
        self::assertSame(400, $this->post('/admin/tickets/' . $ticketId, ['status' => 'open', 'priority' => 'normal', 'assigned_to' => (string) $customer->id])->status());

        // A customer reply reopens the ticket.
        $this->actingAs($customer);
        $this->post('/tickets/' . $ticketId . '/reply', ['message' => 'Still down.']);
        self::assertSame('customer_reply', $this->db->fetchValue('SELECT status FROM nx_tickets WHERE id = ?', [$ticketId]));
    }

    public function testTicketsAreIsolatedBetweenCustomers(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $foreignService = $this->db->insert('nx_services', ['user_id' => $owner->id, 'category' => 'lxc', 'name' => 'x', 'status' => 'active', 'billing_cycle' => 'monthly', 'currency' => 'EUR', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->actingAs($owner);
        $this->post('/tickets', ['subject' => 'Private matter', 'message' => 'Confidential details here.', 'priority' => 'normal']);
        $ticketId = (int) $this->db->fetchValue('SELECT id FROM nx_tickets');

        $this->actingAs($other);
        self::assertSame(404, $this->get('/tickets/' . $ticketId)->status());
        self::assertSame(404, $this->post('/tickets/' . $ticketId . '/reply', ['message' => 'hijack'])->status());
        self::assertSame(404, $this->post('/tickets/' . $ticketId . '/close')->status());

        // Referencing another customer's service is rejected.
        $this->post('/tickets', ['subject' => 'About a server', 'message' => 'Please look at this server.', 'priority' => 'normal', 'service_id' => (string) $foreignService]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_tickets WHERE user_id = ?', [$other->id]));

        // Customers cannot reach the staff ticket views.
        self::assertSame(403, $this->get('/admin/tickets/' . $ticketId)->status());
    }

    public function testTicketCreationIsRateLimitedAndCanBeDisabled(): void
    {
        $this->actingAs($this->createUser());
        for ($i = 0; $i < 6; $i++) {
            $this->post('/tickets', ['subject' => 'Question ' . $i, 'message' => 'A question about my account.', 'priority' => 'low']);
        }
        self::assertSame(5, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_tickets'));

        $this->app->container()->get(SettingsRepository::class)->set('system.tickets_enabled', false);
        self::assertSame(404, $this->get('/tickets')->status());
    }

    public function testAllCustomerPagesRender(): void
    {
        $this->actingAs($this->createUser());
        foreach (['/dashboard', '/services', '/store', '/orders', '/invoices', '/tickets', '/tickets/create', '/account/profile', '/account/security', '/account/two-factor/setup'] as $page) {
            self::assertSame(200, $this->get($page)->status(), $page);
        }
    }
}
