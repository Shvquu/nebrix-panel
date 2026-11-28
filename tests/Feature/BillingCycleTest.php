<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use DateTimeImmutable;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Billing\InvoiceService;
use Nebrix\Billing\OrderException;
use Nebrix\Billing\OrderService;
use Nebrix\Billing\Sales\SaleService;
use Nebrix\Catalog\ProductPricing;
use Nebrix\Payment\PaymentService;
use Nebrix\Provisioning\ProvisioningService;
use Nebrix\Repository\ProductRepository;
use Nebrix\Repository\UserRepository;
use Nebrix\Service\CycleChangeService;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceChangeException;
use Nebrix\Service\ServiceChangeService;
use Nebrix\Service\ServiceRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Several billing cycles per product: prices with cycle discounts, orders,
 * services and renewals in the chosen cycle, promotions/sales/group
 * discounts/coupons on top of the cycle price, proration of package
 * changes, the cycle switch for the next term, admin form, price history
 * and the website.
 */
final class BillingCycleTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-07-01 12:00:00'));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function product(int $price, array $extra = [], string $category = 'teamspeak'): int
    {
        return $this->app->container()->get(ProductRepository::class)->create($extra + [
            'category' => $category, 'name' => 'TS ' . random_int(1, 99999), 'slug' => 'ts-' . random_int(1, 999999), 'price' => $price, 'setup_fee' => 0, 'currency' => 'EUR',
            'billing_cycle' => 'monthly', 'billing_cycles' => ['quarterly' => 500, 'annually' => 1500], 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0,
            'provisioning_config' => $category === 'teamspeak' ? ['slots' => 32] : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        return $this->app->container()->get(ProductRepository::class)->find($id) ?? throw new \RuntimeException('Product missing.');
    }

    /**
     * @return array<string, mixed>
     */
    private function item(int $orderId): array
    {
        return (array) $this->db->fetchOne('SELECT * FROM nx_order_items WHERE order_id = ? ORDER BY id LIMIT 1', [$orderId]);
    }

    private function payAndFulfil(array $result): Service
    {
        $total = (int) $this->db->fetchValue('SELECT total FROM nx_invoices WHERE id = ?', [$result['invoice_id']]);
        self::assertTrue($this->app->container()->get(PaymentService::class)->recordPayment('manual', 'bank-' . $result['invoice_id'], $result['invoice_id'], $total, 'EUR')->isSuccessful());
        $this->app->container()->get(ProvisioningService::class)->fulfilOrder($result['order_id']);
        $id = (int) $this->db->fetchValue('SELECT s.id FROM nx_services s JOIN nx_order_items i ON i.id = s.order_item_id WHERE i.order_id = ?', [$result['order_id']]);

        return $this->app->container()->get(ServiceRepository::class)->find($id) ?? throw new \RuntimeException('Service missing.');
    }

    private function inGroup(User $user, int $discountBp): User
    {
        $group = $this->db->insert('nx_customer_groups', ['name' => 'G' . random_int(1, 99999), 'discount_bp' => $discountBp, 'can_order' => 1, 'can_manage_services' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->db->update('nx_users', ['customer_group_id' => $group], ['id' => $user->id]);

        return $this->app->container()->get(UserRepository::class)->find($user->id) ?? $user;
    }

    public function testCyclePricesOrdersServicesAndRenewalsFollowTheChosenCycle(): void
    {
        $id = $this->product(1000);
        $product = $this->find($id);
        self::assertSame(['monthly' => 0, 'quarterly' => 500, 'annually' => 1500], ProductPricing::cycles($product));
        self::assertSame([1000, 2850, 10200], [ProductPricing::cyclePrice($product, 'monthly'), ProductPricing::cyclePrice($product, 'quarterly'), ProductPricing::cyclePrice($product, 'annually')]);

        $orders = $this->app->container()->get(OrderService::class);
        $customer = $this->createUser();
        $quote = $orders->quote($product, null, $customer, null, [], false, 'annually');
        self::assertSame([10200, 10200 + 1938, 'annually'], [$quote['price'], $quote['total'], $quote['cycle']]);
        self::assertSame('monthly', $orders->quote($product, null, $customer, null, [], false, 'biennially')['cycle'], 'Unknown cycles fall back to the product cycle in previews.');

        $result = $orders->place($customer, $id, ['server_name' => 'Clan'], '127.0.0.1', null, 'annually');
        $item = $this->item($result['order_id']);
        self::assertSame([10200, 'annually'], [(int) $item['unit_price'], $item['billing_cycle']]);
        self::assertStringContainsString('(Annually)', (string) $item['description']);
        self::assertSame(12138, (int) $this->db->fetchValue('SELECT total FROM nx_invoices WHERE id = ?', [$result['invoice_id']]));

        $service = $this->payAndFulfil($result);
        self::assertSame(['annually', 10200, '2027-07-01 12:00:00'], [$service->billingCycle, $service->recurringAmount, $service->nextDueAt]);
        // A cancellation ends the service at the end of its annual term.
        self::assertSame('2027-07-01 12:00:00', $this->app->container()->get(\Nebrix\Contract\CancellationService::class)->effectiveDate($service, null)->format('Y-m-d H:i:s'));

        $renewal = $this->app->container()->get(InvoiceService::class)->createRenewal($service);
        $line = (array) $this->db->fetchOne('SELECT * FROM nx_invoice_items WHERE invoice_id = ?', [$renewal]);
        self::assertSame([10200, '2027-07-01 12:00:00', '2028-07-01 12:00:00'], [(int) $line['unit_amount'], $line['period_start'], $line['period_end']]);

        // A cycle the product does not offer is refused (tampered form).
        foreach (['semiannually', 'biennially', 'one_time'] as $cycle) {
            try {
                $orders->place($customer, $id, ['server_name' => 'Clan'], '127.0.0.1', null, $cycle);
                self::fail('Cycle ' . $cycle . ' was accepted.');
            } catch (OrderException $exception) {
                self::assertSame('This billing cycle is not available for this product.', $exception->getMessage());
            }
        }
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_orders WHERE user_id = ?', [$customer->id]));
    }

    public function testDomainsAndOneTimeProductsKeepTheirSingleCycle(): void
    {
        $domain = $this->find($this->product(1200, ['billing_cycle' => 'annually', 'billing_cycles' => ['monthly' => 0], 'provisioning_config' => ['tld' => 'de']], 'domain'));
        self::assertSame(['annually' => 0], ProductPricing::cycles($domain));
        $once = $this->find($this->product(5000, ['billing_cycle' => 'one_time'], 'dedicated'));
        self::assertSame(['one_time' => 0], ProductPricing::cycles($once));
    }

    public function testDiscountsApplyOnTopOfTheCyclePriceWithoutDoubleDipping(): void
    {
        // Promotion 20 % (permanent), group 10 %, coupon 10 % forever – on the annual price 102.00.
        $id = $this->product(1000, ['promo_bp' => 2000, 'promo_starts_at' => '2026-06-01 00:00:00', 'promo_ends_at' => '2026-08-01 00:00:00']);
        $this->db->insert('nx_coupons', ['code' => 'LOYAL', 'type' => 'percent', 'value' => 1000, 'duration' => 'forever', 'max_uses_per_customer' => 5, 'is_active' => 1, 'uses' => 0, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $customer = $this->inGroup($this->createUser(), 1000);
        $orders = $this->app->container()->get(OrderService::class);

        $quote = $orders->quote($this->find($id), $customer->customerGroupId, $customer, 'LOYAL', [], false, 'annually');
        self::assertSame([10200, 2040, 816, 734], [$quote['price'], $quote['promo_discount'], $quote['group_discount'], $quote['coupon_discount']]);

        $result = $orders->place($customer, $id, ['server_name' => 'Clan'], '127.0.0.1', 'LOYAL', 'annually');
        $item = $this->item($result['order_id']);
        // 102.00 − 20.40 promotion − 8.16 group − 7.34 coupon = 66.10 (each discount counted once)
        self::assertSame([10200, 3590, 3590, 2000], [(int) $item['unit_price'], (int) $item['discount'], (int) $item['recurring_discount'], (int) $item['promo_bp']]);
        self::assertSame(6610 + 1256, (int) $this->db->fetchValue('SELECT total FROM nx_invoices WHERE id = ?', [$result['invoice_id']]));
        self::assertSame(6610, $this->payAndFulfil($result)->recurringAmount, 'Renewals keep the permanent discounts of the annual price.');

        // A first-period sale of 30 % and the promotion of 20 % never add up: 30 % in the first year, 20 % afterwards.
        $this->app->container()->get(SaleService::class)->save(null, [
            'name' => 'Summer', 'banner_text' => null, 'discount_bp' => 3000, 'permanent' => false, 'scope' => 'all', 'categories' => [], 'product_ids' => [],
            'starts_at' => '2026-06-01 00:00:00', 'ends_at' => '2026-08-01 00:00:00', 'show_banner' => false, 'is_active' => true,
        ], 1);
        $other = $orders->place($this->createUser(), $id, ['server_name' => 'Two'], '127.0.0.1', null, 'annually');
        $item = $this->item($other['order_id']);
        self::assertSame([3060, 2040], [(int) $item['discount'], (int) $item['recurring_discount']]);
    }

    public function testPackageChangesProrateInTheServiceCycle(): void
    {
        $small = $this->product(1000, ['category' => 'dedicated', 'upgrade_group' => 'dedi', 'provisioning_config' => []], 'dedicated');
        $large = $this->product(2000, ['category' => 'dedicated', 'upgrade_group' => 'dedi', 'provisioning_config' => []], 'dedicated');
        // A product without the annual cycle is no target for an annual service.
        $monthlyOnly = $this->product(3000, ['category' => 'dedicated', 'upgrade_group' => 'dedi', 'billing_cycles' => [], 'provisioning_config' => []], 'dedicated');
        $customer = $this->createUser();
        $result = $this->app->container()->get(OrderService::class)->place($customer, $small, ['hostname' => 'dedi1'], '127.0.0.1', null, 'annually');
        $service = $this->payAndFulfil($result);
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        // Half of the annual term is left.
        $this->clock()->freeze(new DateTimeImmutable('2026-12-31 00:00:00'));
        $service = $this->app->container()->get(ServiceRepository::class)->find($service->id) ?? $service;

        $changes = $this->app->container()->get(ServiceChangeService::class);
        $targets = array_map(static fn (array $t): int => (int) $t['product']['id'], $changes->adminTargets($service, $customer));
        self::assertContains($large, $targets);
        self::assertNotContains($monthlyOnly, $targets);

        $change = $changes->request($service, $customer, $large, $customer->id, null, true);
        $row = (array) $this->db->fetchOne('SELECT * FROM nx_service_changes WHERE id = ?', [$change['change_id']]);
        // Annual prices 102.00 → 204.00: the difference 102.00 for 182.5 of 365 days.
        self::assertSame([10200, 20400], [(int) $row['old_recurring'], (int) $row['new_recurring']]);
        $period = (new DateTimeImmutable('2027-07-01 12:00:00'))->getTimestamp() - (new DateTimeImmutable('2026-07-01 12:00:00'))->getTimestamp();
        $left = (new DateTimeImmutable('2027-07-01 12:00:00'))->getTimestamp() - (new DateTimeImmutable('2026-12-31 00:00:00'))->getTimestamp();
        self::assertSame((int) round(10200 * $left / $period), (int) $row['charge']);
    }

    public function testConfiguredResourcesArePricedInTheServiceCycle(): void
    {
        $id = $this->app->container()->get(ProductRepository::class)->create([
            'category' => 'lxc', 'name' => 'LXC Flex', 'slug' => 'lxc-flex', 'price' => 1000, 'setup_fee' => 0, 'currency' => 'EUR', 'billing_cycle' => 'monthly', 'billing_cycles' => ['annually' => 1500],
            'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0,
            'provisioning_config' => ['ostemplate' => 'local:vztmpl/d.tar.zst', 'storage' => 'local-lvm', 'bridge' => 'vmbr0', 'cores' => 1, 'memory_mb' => 1024, 'disk_gb' => 10, 'configurator' => true, 'cfg_max_cores' => 8, 'cfg_price_core' => '1.50'],
        ]);
        $customer = $this->createUser();
        // 4 cores: 10.00 + 3 × 1.50 = 14.50 per month → 174.00 per year less 15 % = 147.90.
        $result = $this->app->container()->get(OrderService::class)->place($customer, $id, ['hostname' => 'flex1', 'cores' => '4'], '127.0.0.1', null, 'annually');
        self::assertSame(14790, (int) $this->item($result['order_id'])['unit_price']);
        $service = $this->payAndFulfil($result);
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        $service = $this->app->container()->get(ServiceRepository::class)->find($service->id) ?? $service;

        // 6 cores: 17.50 per month → 178.50 per year: the difference is an upgrade in the annual cycle.
        $offer = $this->app->container()->get(ServiceChangeService::class)->configurationOffer($service, $customer, ['cores' => '6', 'memory_gb' => '1', 'disk_gb' => '10'], false);
        self::assertSame(['upgrade', 17850], [$offer['direction'], $offer['recurring']]);
        self::assertSame(17850 - 14790, $offer['charge'], 'The whole annual term is left.');
    }

    public function testCustomersSwitchTheCycleForTheNextTerm(): void
    {
        $id = $this->product(1000);
        $customer = $this->createUser();
        $service = $this->payAndFulfil($this->app->container()->get(OrderService::class)->place($customer, $id, ['server_name' => 'Clan'], '127.0.0.1'));
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        $service = $this->app->container()->get(ServiceRepository::class)->find($service->id) ?? $service;
        self::assertSame(['monthly', 1000, '2026-08-01 12:00:00'], [$service->billingCycle, $service->recurringAmount, $service->nextDueAt]);

        $this->actingAs($customer);
        $page = $this->get('/services/' . $service->id)->body();
        self::assertStringContainsString('name="billing_cycle"', $page);
        self::assertStringContainsString('€102.00', $page, 'The annual price is offered.');

        $this->post('/services/' . $service->id . '/cycle', ['billing_cycle' => 'semiannually']);
        self::assertNull($this->db->fetchValue('SELECT next_billing_cycle FROM nx_services WHERE id = ?', [$service->id]), 'Only offered cycles.');
        $this->post('/services/' . $service->id . '/cycle', ['billing_cycle' => 'annually']);
        $row = (array) $this->db->fetchOne('SELECT billing_cycle, next_billing_cycle, next_recurring_amount, recurring_amount FROM nx_services WHERE id = ?', [$service->id]);
        self::assertSame(['monthly', 'annually', 10200, 1000], [$row['billing_cycle'], $row['next_billing_cycle'], (int) $row['next_recurring_amount'], (int) $row['recurring_amount']], 'Nothing changes in the paid term.');
        self::assertStringContainsString('From the next renewal the service is billed', $this->get('/services/' . $service->id)->body());

        // A package change waits until the switch is taken back.
        $service = $this->app->container()->get(ServiceRepository::class)->find($service->id) ?? $service;
        try {
            $this->app->container()->get(ServiceChangeService::class)->request($service, $customer, $id, $customer->id, null);
            self::fail('A package change was accepted during a pending cycle switch.');
        } catch (ServiceChangeException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }

        // Someone else's service: not found.
        $this->actingAs($this->createUser());
        self::assertSame(404, $this->post('/services/' . $service->id . '/cycle', ['billing_cycle' => 'monthly'])->status());

        // The renewal is issued for one year at the annual price; the next due date follows the invoice.
        $renewal = $this->app->container()->get(InvoiceService::class)->createRenewal($service);
        $line = (array) $this->db->fetchOne('SELECT * FROM nx_invoice_items WHERE invoice_id = ?', [$renewal]);
        self::assertSame([10200, '2026-08-01 12:00:00', '2027-08-01 12:00:00'], [(int) $line['unit_amount'], $line['period_start'], $line['period_end']]);
        $row = (array) $this->db->fetchOne('SELECT billing_cycle, next_billing_cycle, recurring_amount FROM nx_services WHERE id = ?', [$service->id]);
        self::assertSame(['annually', null, 10200], [$row['billing_cycle'], $row['next_billing_cycle'], (int) $row['recurring_amount']]);
        $total = (int) $this->db->fetchValue('SELECT total FROM nx_invoices WHERE id = ?', [$renewal]);
        $this->app->container()->get(PaymentService::class)->recordPayment('manual', 'bank-renewal', (int) $renewal, $total, 'EUR');
        self::assertSame('2027-08-01 12:00:00', $this->db->fetchValue('SELECT next_due_at FROM nx_services WHERE id = ?', [$service->id]));
    }

    public function testCycleSwitchCanBeTakenBackAndIsBlockedByAnOpenRenewal(): void
    {
        $id = $this->product(1000);
        $customer = $this->createUser();
        $service = $this->payAndFulfil($this->app->container()->get(OrderService::class)->place($customer, $id, ['server_name' => 'Clan'], '127.0.0.1'));
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        $cycles = $this->app->container()->get(CycleChangeService::class);
        $service = $this->app->container()->get(ServiceRepository::class)->find($service->id) ?? $service;
        self::assertSame(2850, $cycles->request($service, 'quarterly', $customer->id, null));

        $this->actingAs($customer);
        $this->post('/services/' . $service->id . '/cycle/withdraw');
        self::assertNull($this->db->fetchValue('SELECT next_billing_cycle FROM nx_services WHERE id = ?', [$service->id]));

        $this->app->container()->get(InvoiceService::class)->createRenewal($this->app->container()->get(ServiceRepository::class)->find($service->id) ?? $service);
        $options = $cycles->options($this->app->container()->get(ServiceRepository::class)->find($service->id) ?? $service);
        self::assertSame('The next term has already been invoiced. Please pay the open renewal invoice first.', $options['blocker'] ?? null);
    }

    public function testAdminConfiguresCyclesAndThePriceHistoryIsKeptPerCycle(): void
    {
        $this->actingAs($this->createUser(Role::ADMIN));
        $form = [
            'category' => 'teamspeak', 'name' => 'TS Cycles', 'slug' => 'ts-cycles', 'price' => '10.00', 'setup_fee' => '0', 'currency' => 'EUR', 'billing_cycle' => 'monthly', 'tax_rate' => '19',
            'is_visible' => '1', 'is_active' => '1', 'auto_provision' => '0', 'config_slots' => '10',
            'cycle_annually' => '1', 'cycle_annually_discount' => '15', 'cycle_quarterly' => '0', 'cycle_quarterly_discount' => '5', 'cycle_monthly' => '1', 'cycle_monthly_discount' => '50',
        ];
        $this->post('/admin/products', ['cycle_annually_discount' => '95'] + $form);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_products WHERE slug = 'ts-cycles'"), 'At most 90 %.');
        $this->post('/admin/products', $form);
        $product = $this->app->container()->get(ProductRepository::class)->findBySlug('ts-cycles');
        self::assertNotNull($product);
        self::assertSame(['annually' => 1500], $product['billing_cycles'], 'Unticked cycles and the product cycle itself are not stored.');
        self::assertStringContainsString('name="cycle_annually" value="1" checked', $this->get('/admin/products/' . $product['id'] . '/edit')->body());

        $repository = $this->app->container()->get(ProductRepository::class);
        self::assertSame(10200, $repository->lowestPriceBefore((int) $product['id'], '2026-07-02 00:00:00', 30, 'annually'));
        $this->clock()->freeze(new DateTimeImmutable('2026-07-10 00:00:00'));
        $repository->update((int) $product['id'], ['billing_cycles' => ['annually' => 2000]]);
        $this->clock()->freeze(new DateTimeImmutable('2026-07-20 00:00:00'));
        $repository->update((int) $product['id'], ['billing_cycles' => ['annually' => 1000]]);
        // Within 30 days the annual price was 96.00 once (20 % off 120.00); the monthly price never changed.
        self::assertSame(9600, $repository->lowestPriceBefore((int) $product['id'], '2026-07-25 00:00:00', 30, 'annually'));
        self::assertSame(1000, $repository->lowestPriceBefore((int) $product['id'], '2026-07-25 00:00:00'));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_product_price_history WHERE product_id = ? AND billing_cycle IS NULL', [$product['id']]));
        self::assertSame(3, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_product_price_history WHERE product_id = ? AND billing_cycle = 'annually'", [$product['id']]));
    }

    public function testWebsiteAndStoreShowTheCyclesWithPerMonthPricesAndSavings(): void
    {
        $id = $this->product(1000, ['name' => 'TS Cycle', 'slug' => 'ts-cycle']);
        $page = $this->get('/products/teamspeak')->body();
        self::assertStringContainsString('data-cycle-select', $page);
        self::assertStringContainsString('action="/store/ts-cycle"', $page);
        self::assertStringContainsString('value="annually" data-price="€121.38"', $page, '102.00 + 19 % VAT');
        self::assertStringContainsString('€10.12 per month', $page, '121.38 / 12');
        self::assertStringContainsString('You save €21.42', $page, '142.80 − 121.38');

        // Guests choose the cycle on the website and find it again after signing in.
        $customer = $this->createUser();
        $this->assertRedirect($this->get('/store/ts-cycle', ['cycle' => 'annually']), '/login');
        $this->assertRedirect($this->post('/login', ['email' => $customer->email, 'password' => 'Valid-Password-123']), '/store/ts-cycle?cycle=annually');
        $order = $this->get('/store/ts-cycle', ['cycle' => 'annually'])->body();
        self::assertStringContainsString('value="annually" checked', $order);
        self::assertStringContainsString('€121.38', $order);
        self::assertStringContainsString('<input type="hidden" name="cycle" value="annually">', $order);
        self::assertStringContainsString('Save up to 15 % with a longer billing cycle', $this->get('/store')->body());

        // "Buy now" with the chosen cycle.
        $this->post('/store/ts-cycle', ['server_name' => 'Clan', 'accept_terms' => '1', 'cycle' => 'annually', 'price' => '1']);
        self::assertSame(['annually', 10200], [$this->db->fetchValue('SELECT billing_cycle FROM nx_order_items WHERE product_id = ?', [$id]), (int) $this->db->fetchValue('SELECT unit_price FROM nx_order_items WHERE product_id = ?', [$id])]);
    }
}
