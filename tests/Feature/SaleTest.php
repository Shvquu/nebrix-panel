<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use DateTimeImmutable;
use Nebrix\Auth\Role;
use Nebrix\Billing\OrderService;
use Nebrix\Billing\Sales\SaleService;
use Nebrix\Payment\PaymentService;
use Nebrix\Provisioning\ProvisioningService;
use Nebrix\Repository\ProductRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Global sales: scope, permanent or first billing period, never stacked
 * with a product promotion, banner, admin management.
 */
final class SaleTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-11-27 12:00:00'));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function product(string $category, int $price, array $config, array $extra = []): int
    {
        return $this->app->container()->get(ProductRepository::class)->create($extra + [
            'category' => $category, 'name' => 'P ' . random_int(1, 99999), 'slug' => 'p-' . random_int(1, 999999), 'price' => $price, 'setup_fee' => 0, 'currency' => 'EUR',
            'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => $config,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sale(array $data = []): int
    {
        return $this->app->container()->get(SaleService::class)->save(null, $data + [
            'name' => 'Black Friday', 'banner_text' => null, 'discount_bp' => 3000, 'permanent' => true, 'scope' => 'all', 'categories' => [], 'product_ids' => [],
            'starts_at' => '2026-11-27 00:00:00', 'ends_at' => '2026-11-30 23:00:00', 'show_banner' => true, 'is_active' => true,
        ], 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(int $orderId): array
    {
        return (array) $this->db->fetchOne('SELECT * FROM nx_order_items WHERE order_id = ?', [$orderId]);
    }

    public function testPermanentSaleForACategory(): void
    {
        $ts = $this->product('teamspeak', 1000, ['slots' => 32]);
        $cloud = $this->product('nextcloud', 1000, ['quota_gb' => 100]);
        $saleId = $this->sale(['scope' => 'categories', 'categories' => ['teamspeak']]);
        $customer = $this->createUser();
        $orders = $this->app->container()->get(OrderService::class);

        $result = $orders->place($customer, $ts, ['server_name' => 'Clan'], '127.0.0.1');
        $item = $this->item($result['order_id']);
        self::assertSame([300, 300, 3000, $saleId], [(int) $item['discount'], (int) $item['recurring_discount'], (int) $item['promo_bp'], (int) $item['sale_id']]);
        self::assertStringContainsString('Black Friday Promotion −30 %', (string) $item['description']);

        $other = $orders->place($customer, $cloud, [], '127.0.0.1');
        self::assertSame([0, null], [(int) $this->item($other['order_id'])['discount'], $this->item($other['order_id'])['sale_id']], 'Other categories are not part of the sale.');

        // Paid: the service renews at the sale price.
        $total = (int) $this->db->fetchValue('SELECT total FROM nx_invoices WHERE id = ?', [$result['invoice_id']]);
        $this->app->container()->get(PaymentService::class)->recordPayment('manual', 'bank-sale', $result['invoice_id'], $total, 'EUR');
        $this->app->container()->get(ProvisioningService::class)->fulfilOrder($result['order_id']);
        self::assertSame(700, (int) $this->db->fetchValue('SELECT recurring_amount FROM nx_services WHERE order_item_id = ?', [$item['id']]));

        // After the end, before the start or switched off: no discount.
        foreach ([['2026-11-30 23:00:00', true], ['2026-11-26 23:59:00', true], ['2026-11-28 00:00:00', false]] as [$at, $active]) {
            $this->clock()->freeze(new DateTimeImmutable($at));
            $this->db->update('nx_sales', ['is_active' => $active ? 1 : 0], ['id' => $saleId]);
            self::assertSame(0, $orders->quote($this->app->container()->get(ProductRepository::class)->find($ts) ?? [], null)['promo_discount'], $at);
        }
    }

    public function testFirstPeriodSaleAndProductPromotionNeverAddUp(): void
    {
        // Product promotion 25 % (permanent), sale 40 % for the first billing period only.
        $id = $this->product('teamspeak', 1000, ['slots' => 32], ['promo_bp' => 2500, 'promo_starts_at' => '2026-11-01 00:00:00', 'promo_ends_at' => '2026-12-31 00:00:00']);
        $this->sale(['discount_bp' => 4000, 'permanent' => false]);
        $orders = $this->app->container()->get(OrderService::class);
        $customer = $this->createUser();

        $quote = $orders->quote($this->app->container()->get(ProductRepository::class)->find($id) ?? [], null, $customer);
        self::assertSame([250, 150, 'Black Friday'], [$quote['promo_discount'], $quote['first_promo'], $quote['sale_name']], '40 % in the first period = 25 % permanent + 15 % once.');

        $result = $orders->place($customer, $id, ['server_name' => 'Clan'], '127.0.0.1');
        $item = $this->item($result['order_id']);
        self::assertSame([400, 250, 2500], [(int) $item['discount'], (int) $item['recurring_discount'], (int) $item['promo_bp']]);

        // A smaller sale does not replace the higher product promotion.
        $this->db->run('UPDATE nx_sales SET discount_bp = 1000');
        $other = $orders->place($customer, $id, ['server_name' => 'Two'], '127.0.0.1');
        self::assertSame([250, 250, null], [(int) $this->item($other['order_id'])['discount'], (int) $this->item($other['order_id'])['recurring_discount'], $this->item($other['order_id'])['sale_id']]);

        // A 100 % coupon plus a first-period sale never makes the payment negative.
        $this->db->run('UPDATE nx_sales SET discount_bp = 5000');
        $this->db->insert('nx_coupons', ['code' => 'FREE', 'type' => 'percent', 'value' => 10000, 'duration' => 'once', 'max_uses_per_customer' => 5, 'is_active' => 1, 'uses' => 0, 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);
        $free = $orders->place($customer, $id, ['server_name' => 'Free'], '127.0.0.1', 'FREE');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT total FROM nx_invoices WHERE id = ?', [$free['invoice_id']]));
        self::assertGreaterThanOrEqual(0, (int) $this->db->fetchValue('SELECT total FROM nx_orders WHERE id = ?', [$free['order_id']]));
    }

    public function testBannerOnTheWebsiteAndInTheShop(): void
    {
        $this->product('teamspeak', 1000, ['slots' => 32], ['features' => "Instant setup\n# Network\nOwn address"]);
        $this->product('teamspeak', 2000, ['slots' => 64]);
        $this->sale(['discount_bp' => 2000, 'permanent' => false, 'scope' => 'categories', 'categories' => ['teamspeak']]);

        $body = $this->get('/')->body();
        self::assertStringContainsString('class="site-salebar" data-countdown="2026-11-30T23:00:00Z"', $body);
        self::assertStringContainsString('Up to 20 % off selected products – in the first billing period', $body);

        $page = $this->get('/products/teamspeak')->body();
        self::assertStringContainsString('<h2>Black Friday</h2>', $page);
        self::assertStringContainsString('Order now and get 20 % off the first billing period!', $page);
        self::assertStringContainsString('For a short time, first billing period', $page);
        self::assertStringContainsString('€9.52', $page, '8.00 + VAT in the first month');
        self::assertStringContainsString('then €11.90', $page);
        // The last package is the wide card with the features in two columns.
        self::assertSame(1, preg_match_all('/class="site-plan-wide[ "]/', $page));
        self::assertMatchesRegularExpression('#site-plan-wide.*64 slots#s', $page);

        $this->db->run('UPDATE nx_sales SET banner_text = ?, show_banner = 1', ['Nur dieses Wochenende!']);
        self::assertStringContainsString('Nur dieses Wochenende!', $this->get('/')->body());
        $this->db->run('UPDATE nx_sales SET show_banner = 0');
        self::assertStringNotContainsString('site-salebar', $this->get('/')->body());

        $this->db->run('UPDATE nx_sales SET show_banner = 1');
        $this->actingAs($this->createUser());
        self::assertStringContainsString('Black Friday', $this->get('/store')->body());
    }

    public function testAdminManagesSales(): void
    {
        $this->app->container()->get(\Nebrix\Settings\SettingsRepository::class)->set('general.timezone', 'Europe/Berlin');
        $ts = $this->product('teamspeak', 1000, ['slots' => 32]);
        $this->actingAs($this->createUser());
        self::assertSame(403, $this->post('/admin/sales', ['name' => 'X'])->status());

        $this->actingAs($this->createUser(Role::ADMIN));
        self::assertSame(200, $this->get('/admin/sales')->status());
        $form = ['name' => 'Cyber Monday', 'percent' => '15', 'permanent' => '0', 'scope' => 'products', 'starts_at' => '2026-11-28T00:00', 'ends_at' => '2026-12-01T23:59', 'show_banner' => '1', 'is_active' => '1'];
        $this->post('/admin/sales', $form);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_sales'), 'Product scope without products.');
        $this->post('/admin/sales', ['percent' => '95'] + $form + ['product_ids' => [(string) $ts]]);
        $this->post('/admin/sales', ['ends_at' => '2026-11-27T00:00'] + $form + ['product_ids' => [(string) $ts]]);
        $this->post('/admin/sales', ['name' => '<b>x</b>'] + $form + ['product_ids' => [(string) $ts]]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_sales'), 'Over 90 %, end before start, markup in the name.');

        $response = $this->post('/admin/sales', $form + ['product_ids' => [(string) $ts]]);
        $sale = (array) $this->db->fetchOne('SELECT * FROM nx_sales');
        $this->assertRedirect($response, '/admin/sales/' . $sale['id']);
        self::assertSame(['Cyber Monday', 1500, 0, 'products', (string) $ts, '2026-11-27 23:00:00', '2026-12-01 22:59:00'], [$sale['name'], (int) $sale['discount_bp'], (int) $sale['permanent'], $sale['scope'], $sale['product_ids'], $sale['starts_at'], $sale['ends_at']]);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'sale.created'"));
        $list = $this->get('/admin/sales')->body();
        self::assertStringContainsString('Cyber Monday', $list);
        self::assertStringContainsString('planned', $list);
    }
}
