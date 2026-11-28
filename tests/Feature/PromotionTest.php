<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use DateTimeImmutable;
use Nebrix\Auth\Role;
use Nebrix\Billing\OrderService;
use Nebrix\Payment\PaymentService;
use Nebrix\Provisioning\ProvisioningService;
use Nebrix\Repository\ProductRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Promotions (permanent discount for orders within a period, previous
 * lowest price per § 11 PAngV), domain first-year prices and the TeamSpeak
 * slot configurator.
 */
final class PromotionTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-07-01 12:00:00'));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function product(string $category, int $price, array $config, array $extra = []): int
    {
        return $this->app->container()->get(ProductRepository::class)->create($extra + [
            'category' => $category, 'name' => 'P ' . random_int(1, 99999), 'slug' => 'p-' . random_int(1, 999999), 'price' => $price, 'setup_fee' => 0, 'currency' => 'EUR',
            'billing_cycle' => $category === 'domain' ? 'annually' : 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => $config,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(int $orderId): array
    {
        return (array) $this->db->fetchOne('SELECT * FROM nx_order_items WHERE order_id = ?', [$orderId]);
    }

    private function payAndFulfil(int $orderId, int $invoiceId): void
    {
        $total = (int) $this->db->fetchValue('SELECT total FROM nx_invoices WHERE id = ?', [$invoiceId]);
        self::assertTrue($this->app->container()->get(PaymentService::class)->recordPayment('manual', 'bank-' . $invoiceId, $invoiceId, $total, 'EUR')->isSuccessful());
        $this->app->container()->get(ProvisioningService::class)->fulfilOrder($orderId);
    }

    public function testPromotionAppliesToTheOrderAndEveryRenewal(): void
    {
        $id = $this->product('teamspeak', 1000, ['slots' => 32], ['promo_bp' => 2500, 'promo_starts_at' => '2026-06-30 00:00:00', 'promo_ends_at' => '2026-07-02 00:00:00']);
        $customer = $this->createUser();
        $orders = $this->app->container()->get(OrderService::class);

        $quote = $orders->quote($this->app->container()->get(ProductRepository::class)->find($id) ?? [], null, $customer);
        self::assertSame([2500, 250, 750 + 143], [$quote['promo_bp'], $quote['promo_discount'], $quote['total']]);

        $result = $orders->place($customer, $id, ['server_name' => 'Clan'], '127.0.0.1');
        $item = $this->item($result['order_id']);
        self::assertSame([1000, 250, 250, 2500], [(int) $item['unit_price'], (int) $item['discount'], (int) $item['recurring_discount'], (int) $item['promo_bp']]);
        self::assertStringContainsString('Promotion −25 %', (string) $item['description']);
        $this->payAndFulfil($result['order_id'], $result['invoice_id']);
        self::assertSame(750, (int) $this->db->fetchValue('SELECT recurring_amount FROM nx_services WHERE user_id = ?', [$customer->id]), 'Permanent: renewals keep the discount.');

        // After the end (and before the start) new orders pay the list price.
        $this->clock()->freeze(new DateTimeImmutable('2026-07-02 00:00:00'));
        $later = $orders->place($this->createUser(), $id, ['server_name' => 'Late'], '127.0.0.1');
        self::assertSame([0, null], [(int) $this->item($later['order_id'])['discount'], $this->item($later['order_id'])['promo_bp']]);
        $this->db->update('nx_products', ['promo_starts_at' => '2026-08-01 00:00:00', 'promo_ends_at' => '2026-08-31 00:00:00'], ['id' => $id]);
        self::assertSame(0, $orders->quote($this->app->container()->get(ProductRepository::class)->find($id) ?? [], null)['promo_discount']);
    }

    public function testPreviousLowestPriceComesFromThePriceHistory(): void
    {
        $products = $this->app->container()->get(ProductRepository::class);
        $this->clock()->freeze(new DateTimeImmutable('2026-05-01 00:00:00'));
        $id = $this->product('lxc', 1000, ['ostemplate' => 'local:vztmpl/d.tar.zst', 'storage' => 'local-lvm', 'bridge' => 'vmbr0', 'cores' => 1, 'memory_mb' => 1024, 'disk_gb' => 10]);
        $this->clock()->freeze(new DateTimeImmutable('2026-06-10 00:00:00'));
        $products->update($id, ['price' => 800]);
        $this->clock()->freeze(new DateTimeImmutable('2026-06-20 00:00:00'));
        $products->update($id, ['price' => 1200]);
        $products->update($id, ['name' => 'Renamed']);

        self::assertSame(800, $products->lowestPriceBefore($id, '2026-06-25 00:00:00'), 'Within 30 days the price was 8.00 once.');
        self::assertSame(1000, $products->lowestPriceBefore($id, '2026-06-01 00:00:00'));
        self::assertSame(1200, $products->lowestPriceBefore($id, '2026-08-01 00:00:00'), '30 days after the last change only the current price counts.');
        self::assertSame(3, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_product_price_history WHERE product_id = ?', [$id]), 'Only price changes are recorded.');

        // 25 % off 12.00 = 9.00 > 8.00: no reduction may be announced; the price is still shown.
        $this->db->update('nx_products', ['promo_bp' => 2500, 'promo_starts_at' => '2026-06-25 00:00:00', 'promo_ends_at' => '2026-07-10 00:00:00'], ['id' => $id]);
        $this->clock()->freeze(new DateTimeImmutable('2026-07-01 00:00:00'));
        $page = $this->get('/products/vserver')->body();
        self::assertStringContainsString('€10.71', $page, '9.00 + 19 % VAT');
        self::assertStringNotContainsString('instead of', $page);

        // 50 % off 12.00 = 6.00 < 8.00: the previous price is the lowest of the 30 days (8.00 → 9.52).
        $this->db->update('nx_products', ['promo_bp' => 5000], ['id' => $id]);
        $page = $this->get('/products/vserver')->body();
        self::assertStringContainsString('€7.14', $page);
        self::assertMatchesRegularExpression('/instead of <s>€9\.52<\/s>/', $page);
        self::assertStringContainsString('You save €2.38', $page);
        self::assertStringContainsString('Order now and get 50 % off for the whole term!', $page);
    }

    public function testAdminSavesPromotionAndProductLine(): void
    {
        $this->actingAs($this->createUser(Role::ADMIN));
        $this->settingsSet('general.timezone', 'Europe/Berlin');
        $form = [
            'category' => 'teamspeak', 'name' => 'TS', 'slug' => 'ts-promo', 'price' => '2.00', 'setup_fee' => '0', 'currency' => 'EUR', 'billing_cycle' => 'monthly', 'tax_rate' => '19',
            'is_visible' => '1', 'is_active' => '1', 'auto_provision' => '0', 'config_slots' => '10', 'website_line' => 'TeamSpeak 3', 'promo_percent' => '25',
        ];
        $this->post('/admin/products', $form);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_products WHERE slug = 'ts-promo'"), 'A promotion needs an end.');

        $this->post('/admin/products', ['promo_ends_at' => '2026-07-31T23:59'] + $form);
        $row = (array) $this->db->fetchOne("SELECT website_line, promo_bp, promo_starts_at, promo_ends_at FROM nx_products WHERE slug = 'ts-promo'");
        self::assertSame(['TeamSpeak 3', 2500, '2026-07-01 12:00:00', '2026-07-31 21:59:00'], [$row['website_line'], (int) $row['promo_bp'], $row['promo_starts_at'], $row['promo_ends_at']], 'Start = now; times entered in the panel time zone.');
    }

    public function testDomainFirstYearPricesForRegistrationAndTransfer(): void
    {
        $id = $this->product('domain', 300, ['tld' => 'de', 'register_price' => '1.50', 'transfer_price' => '5.00']);
        $orders = $this->app->container()->get(OrderService::class);
        $product = $this->app->container()->get(ProductRepository::class)->find($id) ?? [];
        self::assertSame(150 + 29, $orders->quote($product, null)['total']);
        self::assertSame(500 + 95, $orders->quote($product, null, null, null, [], true)['total']);

        $customer = $this->createUser();
        $registration = $orders->place($customer, $id, ['domain' => 'mein-projekt.de'], '127.0.0.1');
        $item = $this->item($registration['order_id']);
        self::assertSame([300, 0, 150, 0], [(int) $item['unit_price'], (int) $item['setup_fee'], (int) $item['discount'], (int) $item['recurring_discount']]);
        self::assertStringContainsString('Registration', (string) $item['description']);

        $transfer = $orders->place($customer, $id, ['domain' => 'umzug.de', 'auth_code' => 'Abc-123-Xyz'], '127.0.0.1');
        $item = $this->item($transfer['order_id']);
        self::assertSame([300, 200, 0, 0], [(int) $item['unit_price'], (int) $item['setup_fee'], (int) $item['discount'], (int) $item['recurring_discount']]);
        self::assertSame(500 + 95, (int) $this->db->fetchValue('SELECT total FROM nx_invoices WHERE id = ?', [$transfer['invoice_id']]));

        $this->payAndFulfil($registration['order_id'], $registration['invoice_id']);
        self::assertSame(300, (int) $this->db->fetchValue("SELECT recurring_amount FROM nx_services WHERE name = 'mein-projekt.de'"), 'Renewals cost the product price.');
    }

    public function testTeamSpeakSlotConfigurator(): void
    {
        $id = $this->product('teamspeak', 60, ['slots' => 5, 'configurator' => true, 'cfg_max_slots' => 250, 'cfg_slot_step' => 5, 'cfg_price_slot_step' => '0.50']);
        $customer = $this->createUser();
        $orders = $this->app->container()->get(OrderService::class);
        $this->expectExceptionOnInvalidSlots($customer, $id, '7');

        // 5 → 50 slots: 9 steps × 0.50 = 4.50 + 0.60
        $result = $orders->place($customer, $id, ['server_name' => 'Clan', 'slots' => '50'], '127.0.0.1');
        $item = $this->item($result['order_id']);
        self::assertSame(510, (int) $item['unit_price']);
        self::assertStringContainsString('50 slots', (string) $item['description']);
        $this->payAndFulfil($result['order_id'], $result['invoice_id']);
        $config = json_decode((string) $this->db->fetchValue('SELECT config FROM nx_services WHERE user_id = ?', [$customer->id]), true);
        self::assertSame(50, $config['slots']);

        $this->actingAs($customer);
        $page = $this->get('/store/' . $this->db->fetchValue('SELECT slug FROM nx_products WHERE id = ?', [$id]), ['slots' => '100'])->body();
        self::assertStringContainsString('name="slots" min="5" max="250" step="5" value="100"', $page);
    }

    private function expectExceptionOnInvalidSlots(\Nebrix\Auth\User $customer, int $id, string $slots): void
    {
        try {
            $this->app->container()->get(OrderService::class)->place($customer, $id, ['server_name' => 'Clan', 'slots' => $slots], '127.0.0.1');
            self::fail('Slots outside the steps were accepted.');
        } catch (\Nebrix\Security\ValidationException) {
            self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_orders WHERE user_id = ?', [$customer->id]));
        }
    }

    private function settingsSet(string $key, mixed $value): void
    {
        $this->app->container()->get(\Nebrix\Settings\SettingsRepository::class)->set($key, $value);
    }
}
