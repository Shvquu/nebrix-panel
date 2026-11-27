<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Billing\InvoiceService;
use Nebrix\Billing\OrderException;
use Nebrix\Billing\OrderService;
use Nebrix\Integration\IntegrationRepository;
use Nebrix\Payment\PaymentService;
use Nebrix\Repository\ProductRepository;
use Nebrix\Repository\RoleRepository;
use Nebrix\Repository\UserRepository;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Fixes from the security review: lockout keyed on the account, role
 * escalation, billing decisions behind invoices.manage, chargebacks of
 * spent credit, tax country changes, credentials bound to their endpoint.
 */
final class SecurityHardeningTest extends FeatureTestCase
{
    private function staffWith(array $permissions): User
    {
        $roles = $this->app->container()->get(RoleRepository::class);
        $roleId = $roles->create('Team lead ' . bin2hex(random_bytes(3)), null);
        $roles->syncPermissions($roleId, $permissions);
        $user = $this->createUser(Role::SUPPORT);
        $this->db->update('nx_users', ['role_id' => $roleId], ['id' => $user->id]);

        return $this->app->container()->get(UserRepository::class)->find($user->id) ?? throw new \RuntimeException('missing');
    }

    public function testLockoutCountsEverySpellingOfTheSameAccount(): void
    {
        $victim = $this->createUser(Role::CUSTOMER, ['email' => 'admin@example.test']);
        $this->app->container()->get(SettingsRepository::class)->set('auth.max_login_attempts', 3);
        // Accent variants resolve to the same row in the database (case- and accent-insensitive collation).
        foreach (['ädmin@example.test', 'àdmin@example.test', 'admín@example.test'] as $spelling) {
            $this->post('/login', ['email' => $spelling, 'password' => 'wrong-password-1']);
        }
        $response = $this->followRedirect($this->post('/login', ['email' => 'ADMIN@example.test', 'password' => 'Valid-Password-123']));
        self::assertStringContainsString('Too many failed sign-in attempts', $response->body());
        self::assertSame(4, $this->app->container()->get(\Nebrix\Security\RateLimiter::class)->attempts('login:user:' . $victim->id));
    }

    public function testRoleManagersCannotGrantWhatTheyDoNotHold(): void
    {
        $lead = $this->staffWith(['admin.access', 'roles.manage', 'tickets.view', 'tickets.reply']);
        $roles = $this->app->container()->get(RoleRepository::class);
        $junior = $roles->create('Junior', null);
        $roles->syncPermissions($junior, ['admin.access', 'tickets.view']);
        $this->actingAs($lead);

        // Granting a permission the lead does not hold is refused.
        self::assertSame(403, $this->post('/admin/roles/' . $junior, ['name' => 'Junior', 'permissions' => ['admin.access', 'tickets.view', 'payments.refund']])->status());
        self::assertNotContains('payments.refund', $roles->permissions($junior));
        // Within the lead's own permissions it works.
        $this->post('/admin/roles/' . $junior, ['name' => 'Junior', 'permissions' => ['admin.access', 'tickets.view', 'tickets.reply']]);
        self::assertContains('tickets.reply', $roles->permissions($junior));
        // The own role and roles with more permissions are off limits.
        self::assertSame(403, $this->post('/admin/roles/' . $lead->roleId, ['name' => 'Lead', 'permissions' => ['admin.access', 'roles.manage', 'settings.system']])->status());
        $adminRole = (int) $this->db->fetchValue("SELECT `id` FROM `nx_roles` WHERE `slug` = 'admin'");
        self::assertSame(403, $this->post('/admin/roles/' . $adminRole, ['name' => 'Administrator', 'permissions' => ['admin.access']])->status());
        self::assertStringContainsString('fewer permissions than your own', $this->get('/admin/roles/' . $adminRole)->body());
    }

    public function testDueDatesAndCountryChangesAreBillingDecisions(): void
    {
        $customer = $this->createUser(Role::CUSTOMER, ['country' => 'DE']);
        $services = $this->app->container()->get(ServiceRepository::class);
        $serviceId = $services->create([
            'user_id' => $customer->id, 'category' => 'lxc', 'name' => 'web', 'status' => Service::STATUS_ACTIVE, 'billing_cycle' => 'monthly',
            'recurring_amount' => 500, 'tax_rate_bp' => 1900, 'currency' => 'EUR', 'next_due_at' => '2026-12-01 00:00:00', 'config' => [],
        ]);

        // Support may manage services but not move the due date (free service time).
        $this->actingAs($this->createUser(Role::SUPPORT));
        self::assertSame(403, $this->post('/admin/services/' . $serviceId . '/action', ['action' => 'due_date', 'next_due_at' => '2030-01-01'])->status());
        self::assertSame('2026-12-01 00:00:00', $this->db->fetchValue('SELECT `next_due_at` FROM `nx_services` WHERE `id` = ?', [$serviceId]));

        // After the first paid invoice the customer cannot switch the tax country alone.
        $invoice = $this->app->container()->get(InvoiceService::class)->createCreditTopUp($customer, 1000, 'EUR');
        $this->app->container()->get(PaymentService::class)->recordPayment('manual', 'bank-top', $invoice, 1000, 'EUR');
        $this->actingAs($customer);
        $this->post('/account/profile', ['first_name' => 'A', 'last_name' => 'B', 'language' => 'en', 'country' => 'US']);
        self::assertSame('DE', $this->db->fetchValue('SELECT `country` FROM `nx_users` WHERE `id` = ?', [$customer->id]));
        self::assertStringContainsString('contact our support to change it', $this->get('/account/profile')->body());
    }

    public function testChargebackOfSpentCreditSuspendsAndBlocksOrders(): void
    {
        $customer = $this->createUser();
        $payments = $this->app->container()->get(PaymentService::class);
        $invoices = $this->app->container()->get(InvoiceService::class);
        $topUp = $invoices->createCreditTopUp($customer, 5000, 'EUR');
        $payments->recordPayment('manual', 'card-top', $topUp, 5000, 'EUR');
        $serviceId = $this->app->container()->get(ServiceRepository::class)->create([
            'user_id' => $customer->id, 'category' => 'lxc', 'name' => 'web', 'status' => Service::STATUS_ACTIVE, 'billing_cycle' => 'monthly',
            'recurring_amount' => 500, 'tax_rate_bp' => 1900, 'currency' => 'EUR', 'next_due_at' => '2026-12-01 00:00:00', 'config' => [],
        ]);
        // The credit is spent, then the top-up is charged back.
        $this->app->container()->get(\Nebrix\Billing\CreditService::class)->adjust($customer->id, -4000, 'Paid invoices');
        $payments->recordDispute('manual', 'dp-1', 'card-top', null, null);

        self::assertSame(-4000, (int) $this->db->fetchValue('SELECT `credit_balance` FROM `nx_users` WHERE `id` = ?', [$customer->id]));
        self::assertSame('suspended', $this->db->fetchValue('SELECT `status` FROM `nx_services` WHERE `id` = ?', [$serviceId]));
        $product = $this->app->container()->get(ProductRepository::class)->create([
            'category' => 'dedicated', 'name' => 'Root', 'slug' => 'root-x', 'price' => 1000, 'setup_fee' => 0, 'currency' => 'EUR', 'billing_cycle' => 'monthly',
            'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => [],
        ]);
        $fresh = $this->app->container()->get(UserRepository::class)->find($customer->id) ?? $customer;
        $this->expectException(OrderException::class);
        $this->expectExceptionMessage('negative balance');
        $this->app->container()->get(OrderService::class)->place($fresh, $product, ['hostname' => 'x1'], '127.0.0.1');
    }

    public function testStoredCredentialsStayWithTheirEndpoint(): void
    {
        $id = $this->app->container()->get(IntegrationRepository::class)->create('proxmox', 'Cluster', true, ['base_url' => 'https://203.0.113.10:8006', 'token_id' => 'nebrix@pve!panel', 'verify_tls' => true], ['token_secret' => 'stored-secret']);
        $this->actingAs($this->createUser(Role::ADMIN));
        $form = ['name' => 'Cluster', 'is_enabled' => '1', 'config_base_url' => 'https://203.0.113.99:8006', 'config_token_id' => 'nebrix@pve!panel', 'config_verify_tls' => '1'];

        // A new address without re-entering the secret is refused; the stored secret is not reused.
        $this->post('/admin/integrations/' . $id, $form);
        self::assertSame('https://203.0.113.10:8006', $this->app->container()->get(IntegrationRepository::class)->find($id)?->config('base_url'));
        $this->post('/admin/integrations/' . $id, $form + ['secret_token_secret' => '1a2b3c4d-5e6f-4a1b-8c2d-3e4f5a6b7c8d']);
        self::assertSame('https://203.0.113.99:8006', $this->app->container()->get(IntegrationRepository::class)->find($id)?->config('base_url'));

        // The same for the SMTP password.
        $settings = $this->app->container()->get(SettingsRepository::class);
        $settings->saveGroup('mail', ['mail__enabled' => '1', 'mail__host' => 'smtp.example.test', 'mail__port' => '587', 'mail__encryption' => 'tls', 'mail__username' => 'panel', 'mail__password' => 'smtp-secret', 'mail__from_address' => 'billing@example.test', 'mail__from_name' => 'Nebrix', 'mail__verify_tls' => '1'], null);
        $this->post('/admin/settings/email', ['group' => 'mail', 'mail__enabled' => '1', 'mail__host' => 'evil.example.test', 'mail__port' => '587', 'mail__encryption' => 'tls', 'mail__username' => 'panel', 'mail__from_address' => 'billing@example.test', 'mail__from_name' => 'Nebrix', 'mail__verify_tls' => '1']);
        self::assertSame('smtp.example.test', $settings->string('mail.host'));
    }
}
