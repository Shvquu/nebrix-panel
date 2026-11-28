<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature\Finance;

use DateTimeImmutable;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Billing\OrderService;
use Nebrix\Finance\Tiers\TierService;
use Nebrix\Payment\PaymentService;
use Nebrix\Repository\CustomerGroupRepository;
use Nebrix\Repository\ProductRepository;
use Nebrix\Repository\UserRepository;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Reseller tiers: rules by revenue and active services, evaluation,
 * manually assigned groups, at most one downgrade per period, audit log,
 * group discount on new orders, admin pages and permissions.
 */
final class TierTest extends FeatureTestCase
{
    private TierService $tiers;
    /** @var array<string, int> */
    private array $groups = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-03-01 08:00:00'));
        $this->app->container()->get(SettingsRepository::class)->set('tiers.enabled', true);
        $this->tiers = $this->app->container()->get(TierService::class);
        $repository = $this->app->container()->get(CustomerGroupRepository::class);
        foreach (['Bronze' => 500, 'Silver' => 1000, 'Gold' => 1500, 'Staff' => 5000] as $name => $discount) {
            $this->groups[$name] = $repository->save(null, ['name' => $name, 'description' => null, 'discount_bp' => $discount, 'can_order' => 1, 'can_manage_services' => 1]);
        }
        $this->tiers->saveRule(null, ['name' => 'Bronze', 'customer_group_id' => $this->groups['Bronze'], 'metric' => TierService::REVENUE, 'threshold' => 5000, 'tier_rank' => 1, 'is_active' => true], 1);
        $this->tiers->saveRule(null, ['name' => 'Silver', 'customer_group_id' => $this->groups['Silver'], 'metric' => TierService::REVENUE, 'threshold' => 20000, 'tier_rank' => 2, 'is_active' => true], 1);
        $this->tiers->saveRule(null, ['name' => 'Gold', 'customer_group_id' => $this->groups['Gold'], 'metric' => TierService::SERVICES, 'threshold' => 3, 'tier_rank' => 3, 'is_active' => true], 1);
    }

    private function paid(User $customer, int $net): int
    {
        $product = $this->app->container()->get(ProductRepository::class)->create([
            'category' => 'dedicated', 'name' => 'Root ' . $net, 'slug' => 'root-' . bin2hex(random_bytes(3)), 'price' => $net, 'setup_fee' => 0, 'currency' => 'EUR',
            'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => [],
        ]);
        $invoice = $this->app->container()->get(OrderService::class)->place($customer, $product, ['hostname' => 'srv' . random_int(1, 99999)], '127.0.0.1')['invoice_id'];
        $total = (int) $this->db->fetchValue('SELECT `total` FROM `nx_invoices` WHERE `id` = ?', [$invoice]);
        self::assertTrue($this->app->container()->get(PaymentService::class)->recordPayment('manual', 'm-' . $invoice, $invoice, $total, 'EUR')->isSuccessful());

        return $invoice;
    }

    private function services(User $customer, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->app->container()->get(\Nebrix\Service\ServiceRepository::class)->create([
                'user_id' => $customer->id, 'category' => 'dedicated', 'name' => 'Server ' . $i, 'status' => 'active', 'billing_cycle' => 'monthly', 'currency' => 'EUR', 'config' => [],
            ]);
        }
    }

    private function group(User $customer): ?string
    {
        $id = $this->db->fetchValue('SELECT `customer_group_id` FROM `nx_users` WHERE `id` = ?', [$customer->id]);

        return $id === null ? null : (string) array_search((int) $id, $this->groups, true);
    }

    private function later(string $modify): void
    {
        $this->clock()->freeze($this->clock()->now()->modify($modify));
    }

    public function testCustomersAreMovedIntoTierGroups(): void
    {
        $bronze = $this->createUser();
        $silver = $this->createUser();
        $gold = $this->createUser();
        $staff = $this->createUser(Role::CUSTOMER, ['customer_group_id' => null]);
        $none = $this->createUser();
        $this->paid($bronze, 6000);
        $this->paid($silver, 15000);
        $this->paid($silver, 10000);
        $this->services($gold, 3);
        $this->db->update('nx_users', ['customer_group_id' => $this->groups['Staff']], ['id' => $staff->id]);
        $this->paid($staff, 50000);

        $result = $this->tiers->evaluate();
        self::assertSame(['upgraded' => 3, 'downgraded' => 0, 'held' => 0], array_intersect_key($result, ['upgraded' => 0, 'downgraded' => 0, 'held' => 0]));
        self::assertSame(['Bronze', 'Silver', 'Gold', 'Staff', null], [$this->group($bronze), $this->group($silver), $this->group($gold), $this->group($staff), $this->group($none)], 'Manually assigned groups stay.');
        $audit = (array) $this->db->fetchOne("SELECT * FROM `nx_audit_log` WHERE `action` = 'tier.upgraded' AND `target_id` = ?", [(string) $silver->id]);
        $details = json_decode((string) $audit['details'], true);
        self::assertSame([null, $this->groups['Silver'], 'Silver', 25000], [$details['from_group'], $details['to_group'], $details['rule'], $details['revenue_12m']]);
        self::assertSame(['upgraded' => 0, 'downgraded' => 0], array_intersect_key($this->tiers->evaluate(), ['upgraded' => 0, 'downgraded' => 0]), 'Stable on the next run.');

        // The group discount applies to new orders.
        $customer = $this->app->container()->get(UserRepository::class)->find($silver->id);
        self::assertNotNull($customer);
        $invoice = $this->paid($customer, 10000);
        self::assertSame(9000, (int) $this->db->fetchValue('SELECT `subtotal` FROM `nx_invoices` WHERE `id` = ?', [$invoice]), 'Silver: 10 % discount.');
    }

    public function testDowngradesAtMostOncePerPeriodAndUpgradesAtOnce(): void
    {
        $customer = $this->createUser();
        $first = $this->paid($customer, 25000);
        $this->tiers->evaluate();
        self::assertSame('Silver', $this->group($customer));

        // 13 months later only a newer, smaller invoice counts: Silver → Bronze.
        $this->later('+13 months');
        $recent = $this->paid($customer, 6000);
        $result = $this->tiers->evaluate();
        self::assertSame(1, $result['downgraded']);
        self::assertSame('Bronze', $this->group($customer));
        $state = (array) $this->db->fetchOne('SELECT * FROM `nx_customer_tiers` WHERE `user_id` = ?', [$customer->id]);
        self::assertSame($this->clock()->nowString(), $state['last_downgrade_at']);

        // The newer invoice is refunded: no second downgrade within 90 days …
        $this->db->update('nx_invoices', ['amount_refunded' => (int) $this->db->fetchValue('SELECT `total` FROM `nx_invoices` WHERE `id` = ?', [$recent]), 'status' => 'refunded'], ['id' => $recent]);
        $this->later('+30 days');
        self::assertSame(1, $this->tiers->evaluate()['held']);
        self::assertSame('Bronze', $this->group($customer));
        // … but upgrades are never held back.
        $this->services($customer, 3);
        self::assertSame(1, $this->tiers->evaluate()['upgraded']);
        self::assertSame('Gold', $this->group($customer));
        // A downgrade is possible again after the period.
        $this->db->run("UPDATE `nx_services` SET `status` = 'terminated' WHERE `user_id` = ?", [$customer->id]);
        self::assertSame(1, $this->tiers->evaluate()['held'], 'Still within 90 days of the last downgrade.');
        $this->later('+61 days');
        self::assertSame(1, $this->tiers->evaluate()['downgraded']);
        self::assertNull($this->group($customer));
        self::assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = 'tier.downgraded' AND `target_id` = ?", [(string) $customer->id]));
        self::assertGreaterThan(0, $first);
    }

    public function testAdminPagesAndScheduler(): void
    {
        $this->actingAs($this->createUser(Role::SUPPORT));
        self::assertSame(403, $this->get('/admin/tiers')->status());
        self::assertSame(403, $this->post('/admin/tiers/evaluate')->status());

        $admin = $this->createUser(Role::ADMIN);
        $this->actingAs($admin);
        $page = $this->get('/admin/tiers')->body();
        self::assertStringContainsString('Silver', $page);
        $this->post('/admin/tiers', ['name' => 'Platinum', 'customer_group_id' => (string) $this->groups['Staff'], 'metric' => 'revenue_12m', 'threshold' => '1.000,00', 'tier_rank' => '4', 'is_active' => '1']);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_tier_rules` WHERE `name` = 'Platinum'"), 'Invalid amount.');
        $this->post('/admin/tiers', ['name' => 'Platinum', 'customer_group_id' => (string) $this->groups['Staff'], 'metric' => 'revenue_12m', 'threshold' => '1000,00', 'tier_rank' => '4', 'is_active' => '1']);
        $rule = (array) $this->db->fetchOne("SELECT * FROM `nx_tier_rules` WHERE `name` = 'Platinum'");
        self::assertSame([100000, 4, 1], [(int) $rule['threshold'], (int) $rule['tier_rank'], (int) $rule['is_active']]);
        $this->post('/admin/tiers/' . $rule['id'], ['name' => 'Platinum', 'customer_group_id' => (string) $this->groups['Staff'], 'metric' => 'active_services', 'threshold' => '10', 'tier_rank' => '4']);
        self::assertSame(['active_services', 10, 0], array_values(array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, (array) $this->db->fetchOne('SELECT `metric`, `threshold`, `is_active` FROM `nx_tier_rules` WHERE `id` = ?', [$rule['id']]))));
        $this->post('/admin/tiers/' . $rule['id'] . '/delete');
        self::assertNull($this->tiers->rule((int) $rule['id']));

        $customer = $this->createUser();
        $this->paid($customer, 6000);
        $this->post('/admin/tiers/evaluate');
        self::assertSame('Bronze', $this->group($customer));
        foreach (['tier.rule_created', 'tier.rule_updated', 'tier.rule_deleted', 'tier.evaluated'] as $action) {
            self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = ? AND `actor_id` = ?', [$action, $admin->id]), $action);
        }
        self::assertStringContainsString($customer->email, $this->get('/admin/tiers')->body());

        // Daily scheduler task; switched off it does nothing.
        $scheduler = $this->app->container()->get(\Nebrix\Scheduler\Scheduler::class);
        $other = $this->createUser();
        $this->services($other, 3);
        self::assertStringContainsString('1 customer(s) upgraded', $scheduler->run(true)['tiers.evaluate'] ?? '');
        $this->app->container()->get(SettingsRepository::class)->set('tiers.enabled', false);
        self::assertSame('automatic tiers disabled', $scheduler->run(true)['tiers.evaluate'] ?? '');
        $this->post('/admin/tiers/evaluate');
        self::assertStringContainsString('Automatic tiers are switched off', $this->get('/admin/tiers')->body());
    }
}
