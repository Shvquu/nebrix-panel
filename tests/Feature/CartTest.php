<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use DateTimeImmutable;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Billing\Bundles\BundleService;
use Nebrix\Billing\Sales\SaleService;
use Nebrix\Payment\PaymentService;
use Nebrix\Provisioning\ProvisioningService;
use Nebrix\Repository\ProductRepository;
use Nebrix\Repository\UserRepository;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Cart: several items with their cycle and options, one order and one
 * invoice at checkout, coupon on cart level, stock for all items at once,
 * re-pricing on checkout, tampered prices and IDs, bundles and their
 * interplay with promotions, sales, group discounts and coupons.
 */
final class CartTest extends FeatureTestCase
{
    private int $teamspeak;
    private int $dedicated;
    private int $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-07-01 12:00:00'));
        $this->teamspeak = $this->product('teamspeak', 'ts-cart', 1000, ['slots' => 32], ['billing_cycles' => ['annually' => 1500]]);
        $this->dedicated = $this->product('dedicated', 'dedi-cart', 2000, [], ['setup_fee' => 500]);
        $this->domain = $this->product('domain', 'domain-de-cart', 1200, ['tld' => 'de'], ['billing_cycle' => 'annually']);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $extra
     */
    private function product(string $category, string $slug, int $price, array $config, array $extra = []): int
    {
        return $this->app->container()->get(ProductRepository::class)->create($extra + [
            'category' => $category, 'name' => ucfirst($slug), 'slug' => $slug, 'price' => $price, 'setup_fee' => 0, 'currency' => 'EUR',
            'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => $config,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function add(array $data): \Nebrix\Http\Response
    {
        return $this->post('/cart/items', $data);
    }

    private function cartTotal(): int
    {
        $page = $this->get('/cart')->body();
        self::assertSame(1, preg_match('/name="total" value="(\d+)"/', $page, $match), 'The cart can be ordered.');

        return (int) $match[1];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(int $orderId): array
    {
        return $this->db->fetchAll('SELECT * FROM nx_order_items WHERE order_id = ? ORDER BY id', [$orderId]);
    }

    private function bundle(array $data = []): int
    {
        return $this->app->container()->get(BundleService::class)->save(null, $data + [
            'name' => 'Server + domain', 'description' => null, 'components' => [['product_id' => $this->dedicated], ['category' => 'domain']],
            'discount_type' => 'percent', 'discount_value' => 1000, 'currency' => null, 'permanent' => true, 'starts_at' => null, 'ends_at' => null, 'is_active' => true,
        ], 1);
    }

    public function testCheckoutCreatesOneOrderWithSeveralItemsAndOneInvoice(): void
    {
        $customer = $this->createUser();
        $this->actingAs($customer);
        $this->assertRedirect($this->add(['product_id' => (string) $this->teamspeak, 'server_name' => 'Clan', 'cycle' => 'annually']), '/cart');
        $this->assertRedirect($this->add(['product_id' => (string) $this->dedicated, 'hostname' => 'dedi1', 'price' => '1', 'unit_price' => '1', 'setup_fee' => '0']), '/cart');

        $page = $this->get('/cart')->body();
        self::assertStringContainsString('Ts-cart', $page);
        self::assertStringContainsString('Dedi-cart', $page);
        self::assertStringContainsString('€151.13', $page, '102.00 annual + 20.00 + 5.00 setup, plus 19 % VAT.');
        self::assertSame(15113, $this->cartTotal());

        $this->post('/cart/checkout', ['total' => '15113']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_orders WHERE user_id = ?', [$customer->id]), 'The terms must be accepted.');

        $response = $this->post('/cart/checkout', ['accept_terms' => '1', 'total' => '15113']);
        $order = (array) $this->db->fetchOne('SELECT * FROM nx_orders WHERE user_id = ?', [$customer->id]);
        $invoice = (array) $this->db->fetchOne('SELECT * FROM nx_invoices WHERE order_id = ?', [$order['id']]);
        $this->assertRedirect($response, '/invoices/' . $invoice['id']);
        self::assertSame([12700, 2413, 15113], [(int) $order['subtotal'], (int) $order['tax'], (int) $order['total']]);
        self::assertSame(15113, (int) $invoice['total']);
        $items = $this->items((int) $order['id']);
        self::assertSame([['annually', 10200, 0], ['monthly', 2000, 500]], array_map(static fn (array $i): array => [$i['billing_cycle'], (int) $i['unit_price'], (int) $i['setup_fee']], $items));
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_invoice_items WHERE invoice_id = ?', [$invoice['id']]));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_cart_items'), 'The cart is empty after the checkout.');
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'order.placed' AND target_id = ?", [$order['id']]));

        // Paid: one service per item in its own cycle.
        $this->app->container()->get(PaymentService::class)->recordPayment('manual', 'bank-cart', (int) $invoice['id'], 15113, 'EUR');
        $this->app->container()->get(ProvisioningService::class)->fulfilOrder((int) $order['id']);
        self::assertSame([['annually', 10200], ['monthly', 2000]], array_map(static fn (array $s): array => [$s['billing_cycle'], (int) $s['recurring_amount']], $this->db->fetchAll('SELECT billing_cycle, recurring_amount FROM nx_services WHERE user_id = ? ORDER BY id', [$customer->id])));
    }

    public function testPricesAreCalculatedAgainAndForeignItemsCannotBeTouched(): void
    {
        $customer = $this->createUser();
        $this->actingAs($customer);
        $hidden = $this->product('teamspeak', 'ts-hidden', 100, ['slots' => 5], ['is_visible' => 0]);
        $this->add(['product_id' => (string) $hidden, 'server_name' => 'X']);
        $this->add(['product_id' => '999999', 'server_name' => 'X']);
        $this->add(['product_id' => (string) $this->teamspeak, 'server_name' => 'Bad name <b>']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_cart_items'), 'Hidden, unknown and invalid items are refused.');

        $this->add(['product_id' => (string) $this->teamspeak, 'server_name' => 'Clan']);
        $item = (int) $this->db->fetchValue('SELECT id FROM nx_cart_items');
        $shown = $this->cartTotal();
        self::assertSame(1190, $shown);

        // The price changes after the customer saw it: the order is refused, the new total shown.
        $this->app->container()->get(ProductRepository::class)->update($this->teamspeak, ['price' => 1500]);
        $this->post('/cart/checkout', ['accept_terms' => '1', 'total' => (string) $shown]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_orders WHERE user_id = ?', [$customer->id]));
        self::assertStringContainsString('The prices in your cart have changed. Please check the new total.', $this->get('/cart')->body());
        $this->post('/cart/checkout', ['accept_terms' => '1', 'total' => '1']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_orders WHERE user_id = ?', [$customer->id]), 'A tampered total is no price.');
        $this->post('/cart/checkout', ['accept_terms' => '1', 'total' => (string) $this->cartTotal()]);
        self::assertSame(1785, (int) $this->db->fetchValue('SELECT total FROM nx_orders WHERE user_id = ?', [$customer->id]));

        // Another customer cannot see, change or remove the items of this cart.
        $this->add(['product_id' => (string) $this->teamspeak, 'server_name' => 'Mine']);
        $item = (int) $this->db->fetchValue('SELECT id FROM nx_cart_items');
        $this->actingAs($this->createUser());
        self::assertSame(404, $this->post('/cart/items/' . $item, ['server_name' => 'Taken', 'product_id' => (string) $this->teamspeak])->status());
        self::assertSame(404, $this->post('/cart/items/' . $item . '/remove')->status());
        self::assertSame(404, $this->get('/store/ts-cart', ['cart_item' => (string) $item])->status());
        self::assertStringContainsString('Your cart is empty.', $this->get('/cart')->body());
        self::assertSame('Mine', json_decode((string) $this->db->fetchValue('SELECT options FROM nx_cart_items WHERE id = ?', [$item]), true)['server_name']);
    }

    public function testItemsAreChangedAndRemoved(): void
    {
        $this->app->container()->get(SettingsRepository::class)->set('orders.cart_max_items', 2);
        $customer = $this->createUser();
        $this->actingAs($customer);
        $this->add(['product_id' => (string) $this->teamspeak, 'server_name' => 'Clan']);
        $this->add(['product_id' => (string) $this->dedicated, 'hostname' => 'dedi1']);
        $this->add(['product_id' => (string) $this->dedicated, 'hostname' => 'dedi2']);
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_cart_items'), 'The cart is full.');

        $item = (int) $this->db->fetchValue('SELECT id FROM nx_cart_items WHERE product_id = ?', [$this->teamspeak]);
        $page = $this->get('/store/ts-cart', ['cart_item' => (string) $item])->body();
        self::assertStringContainsString('You are changing an item of your cart.', $page);
        self::assertStringContainsString('value="Clan"', $page);
        self::assertStringContainsString('action="/cart/items/' . $item . '"', $page);
        $this->assertRedirect($this->post('/cart/items/' . $item, ['product_id' => (string) $this->teamspeak, 'server_name' => 'Renamed', 'cycle' => 'annually']), '/cart');
        $row = (array) $this->db->fetchOne('SELECT * FROM nx_cart_items WHERE id = ?', [$item]);
        self::assertSame(['annually', 'Renamed'], [$row['billing_cycle'], json_decode((string) $row['options'], true)['server_name']]);
        $this->post('/cart/items/' . $item, ['product_id' => (string) $this->teamspeak, 'server_name' => 'Renamed', 'cycle' => 'semiannually']);
        self::assertSame('annually', $this->db->fetchValue('SELECT billing_cycle FROM nx_cart_items WHERE id = ?', [$item]), 'Only offered cycles.');

        $this->assertRedirect($this->post('/cart/items/' . $item . '/remove'), '/cart');
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_cart_items'));
    }

    public function testStockIsCheckedAndTakenForAllItemsAtOnce(): void
    {
        $limited = $this->product('teamspeak', 'ts-limited', 500, ['slots' => 5], ['stock' => 1]);
        $first = $this->createUser();
        $second = $this->createUser();
        $this->actingAs($second);
        $this->add(['product_id' => (string) $limited, 'server_name' => 'Second']);

        $this->actingAs($first);
        $this->add(['product_id' => (string) $limited, 'server_name' => 'One']);
        $this->add(['product_id' => (string) $limited, 'server_name' => 'Two']);
        $this->add(['product_id' => (string) $this->dedicated, 'hostname' => 'dedi1']);
        self::assertStringContainsString('This product is sold out.', $this->get('/cart')->body(), 'The second unit is marked.');
        $this->post('/cart/checkout', ['accept_terms' => '1', 'total' => '0']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_orders'), 'No order with one unit missing.');
        self::assertSame(1, (int) $this->db->fetchValue('SELECT stock FROM nx_products WHERE id = ?', [$limited]), 'Nothing was reserved.');

        $this->post('/cart/items/' . $this->db->fetchValue("SELECT id FROM nx_cart_items WHERE options LIKE '%Two%'") . '/remove');
        $this->post('/cart/checkout', ['accept_terms' => '1', 'total' => (string) $this->cartTotal()]);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_orders WHERE user_id = ?', [$first->id]));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT stock FROM nx_products WHERE id = ?', [$limited]));

        // The other customer had the last unit in the cart as well: their checkout fails, the cart stays.
        $this->actingAs($second);
        self::assertStringContainsString('This product is sold out.', $this->get('/cart')->body());
        $this->post('/cart/checkout', ['accept_terms' => '1', 'total' => '595']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_orders WHERE user_id = ?', [$second->id]));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_cart_items i JOIN nx_carts c ON c.id = i.cart_id WHERE c.user_id = ?', [$second->id]));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT stock FROM nx_products WHERE id = ?', [$limited]));
    }

    public function testCouponOnCartLevel(): void
    {
        $now = '2026-01-01 00:00:00';
        $this->db->insert('nx_coupons', ['code' => 'DEDI10', 'type' => 'percent', 'value' => 1000, 'duration' => 'forever', 'product_ids' => (string) $this->dedicated, 'max_uses_per_customer' => 1, 'is_active' => 1, 'uses' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('nx_coupons', ['code' => 'FIVE', 'type' => 'fixed', 'value' => 500, 'currency' => 'EUR', 'duration' => 'once', 'max_uses_per_customer' => 1, 'is_active' => 1, 'uses' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $customer = $this->createUser();
        $this->actingAs($customer);
        $this->add(['product_id' => (string) $this->teamspeak, 'server_name' => 'Clan']);
        $this->add(['product_id' => (string) $this->dedicated, 'hostname' => 'dedi1']);

        $this->post('/cart/coupon', ['coupon' => 'nope']);
        self::assertStringContainsString('This coupon code is not valid.', $this->get('/cart')->body());
        $this->post('/cart/coupon', ['coupon' => ' dedi10 ']);
        $page = $this->get('/cart')->body();
        self::assertStringContainsString('Coupon DEDI10 applied', $page);
        // 10 % of the dedicated server and its setup fee only: 2.50.
        $this->post('/cart/checkout', ['accept_terms' => '1', 'total' => (string) $this->cartTotal()]);
        $order = (array) $this->db->fetchOne('SELECT * FROM nx_orders WHERE user_id = ?', [$customer->id]);
        self::assertSame(['DEDI10', 250], [$order['coupon_code'], (int) $order['coupon_discount']]);
        $items = $this->items((int) $order['id']);
        self::assertSame([[0, 0], [250, 200]], array_map(static fn (array $i): array => [(int) $i['discount'], (int) $i['recurring_discount']], $items));
        self::assertSame([1, 1], [(int) $this->db->fetchValue("SELECT uses FROM nx_coupons WHERE code = 'DEDI10'"), (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_coupon_redemptions')]);

        // A fixed amount is granted once: on the most expensive item.
        $this->add(['product_id' => (string) $this->teamspeak, 'server_name' => 'Two', 'cycle' => 'annually']);
        $this->add(['product_id' => (string) $this->dedicated, 'hostname' => 'dedi2']);
        $this->post('/cart/coupon', ['coupon' => 'FIVE']);
        $this->post('/cart/checkout', ['accept_terms' => '1', 'total' => (string) $this->cartTotal()]);
        $order = (array) $this->db->fetchOne("SELECT * FROM nx_orders WHERE user_id = ? AND coupon_code = 'FIVE'", [$customer->id]);
        self::assertSame([[500, 0], [0, 0]], array_map(static fn (array $i): array => [(int) $i['discount'], (int) $i['recurring_discount']], $this->items((int) $order['id'])));

        // Used up for this customer: the cart says so and the checkout refuses it.
        $this->add(['product_id' => (string) $this->dedicated, 'hostname' => 'dedi3']);
        $this->post('/cart/coupon', ['coupon' => 'FIVE']);
        self::assertStringContainsString('You have already used this coupon code.', $this->get('/cart')->body());
        $this->post('/cart/checkout', ['accept_terms' => '1', 'total' => '2975']);
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_orders WHERE user_id = ?', [$customer->id]));
    }

    public function testBundleIsAppliedAutomaticallyAndRenewedWhenPermanent(): void
    {
        $bundle = $this->bundle();
        $customer = $this->createUser();
        $this->actingAs($customer);
        $this->add(['product_id' => (string) $this->dedicated, 'hostname' => 'dedi1']);
        self::assertStringNotContainsString('Bundle Server + domain', $this->get('/cart')->body(), 'Incomplete bundles do not apply.');
        $this->add(['product_id' => (string) $this->domain, 'domain' => 'mein-projekt.de']);
        $page = $this->get('/cart')->body();
        self::assertStringContainsString('Bundle Server + domain', $page);
        self::assertStringContainsString('−€3.20', $page, '10 % of 20.00 and 12.00.');

        $this->post('/cart/checkout', ['accept_terms' => '1', 'total' => (string) $this->cartTotal()]);
        $order = (array) $this->db->fetchOne('SELECT * FROM nx_orders WHERE user_id = ?', [$customer->id]);
        self::assertSame([$bundle, 'Server + domain', 320], [(int) $order['bundle_id'], $order['bundle_name'], (int) $order['bundle_discount']]);
        $items = $this->items((int) $order['id']);
        self::assertSame([[200, 200, 200], [120, 120, 120]], array_map(static fn (array $i): array => [(int) $i['bundle_discount'], (int) $i['discount'], (int) $i['recurring_discount']], $items));
        self::assertStringContainsString('Bundle Server + domain', (string) $items[0]['description']);
        $invoice = (int) $this->db->fetchValue('SELECT id FROM nx_invoices WHERE order_id = ?', [$order['id']]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_invoice_items WHERE invoice_id = ? AND unit_amount < 0', [$invoice]), 'No negative invoice lines (XRechnung).');
        $this->app->container()->get(PaymentService::class)->recordPayment('manual', 'bank-bundle', $invoice, (int) $order['total'], 'EUR');
        $this->app->container()->get(ProvisioningService::class)->fulfilOrder((int) $order['id']);
        self::assertSame([1800, 1080], array_map('intval', array_column($this->db->fetchAll('SELECT recurring_amount FROM nx_services WHERE user_id = ? ORDER BY id', [$customer->id]), 'recurring_amount')));
    }

    public function testBundlesNeverStackWithPromotionsAndSales(): void
    {
        $this->bundle();
        $orders = fn (User $user) => $this->db->fetchAll('SELECT oi.* FROM nx_order_items oi JOIN nx_orders o ON o.id = oi.order_id WHERE o.user_id = ? ORDER BY oi.id', [$user->id]);

        // A product promotion of 30 % (6.00) saves more than the bundle (3.20): the promotion stays.
        $this->db->update('nx_products', ['promo_bp' => 3000, 'promo_starts_at' => '2026-06-01 00:00:00', 'promo_ends_at' => '2026-08-01 00:00:00'], ['id' => $this->dedicated]);
        $first = $this->createUser();
        $this->actingAs($first);
        $this->add(['product_id' => (string) $this->dedicated, 'hostname' => 'dedi1']);
        $this->add(['product_id' => (string) $this->domain, 'domain' => 'eins.de']);
        $this->post('/cart/checkout', ['accept_terms' => '1', 'total' => (string) $this->cartTotal()]);
        self::assertNull($this->db->fetchValue('SELECT bundle_id FROM nx_orders WHERE user_id = ?', [$first->id]));
        self::assertSame([[600, 3000, 0], [0, null, 0]], array_map(static fn (array $i): array => [(int) $i['discount'], $i['promo_bp'] === null ? null : (int) $i['promo_bp'], (int) $i['bundle_discount']], $orders($first)));

        // A global sale of 5 % (1.60 on both) saves less: the bundle replaces it on these items.
        $this->db->update('nx_products', ['promo_bp' => null, 'promo_starts_at' => null, 'promo_ends_at' => null], ['id' => $this->dedicated]);
        $this->app->container()->get(SaleService::class)->save(null, [
            'name' => 'Summer', 'banner_text' => null, 'discount_bp' => 500, 'permanent' => true, 'scope' => 'all', 'categories' => [], 'product_ids' => [],
            'starts_at' => '2026-06-01 00:00:00', 'ends_at' => '2026-08-01 00:00:00', 'show_banner' => false, 'is_active' => true,
        ], 1);
        // Group discount 10 % and a coupon apply on top of the bundle price.
        $this->db->insert('nx_coupons', ['code' => 'EXTRA', 'type' => 'percent', 'value' => 1000, 'duration' => 'once', 'max_uses_per_customer' => 1, 'is_active' => 1, 'uses' => 0, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $group = $this->db->insert('nx_customer_groups', ['name' => 'Resellers', 'discount_bp' => 1000, 'can_order' => 1, 'can_manage_services' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $second = $this->createUser();
        $this->db->update('nx_users', ['customer_group_id' => $group], ['id' => $second->id]);
        $this->actingAs($second);
        $this->add(['product_id' => (string) $this->dedicated, 'hostname' => 'dedi2']);
        $this->add(['product_id' => (string) $this->domain, 'domain' => 'zwei.de']);
        $this->post('/cart/coupon', ['coupon' => 'EXTRA']);
        $this->post('/cart/checkout', ['accept_terms' => '1', 'total' => (string) $this->cartTotal()]);
        $items = $orders($second);
        // Server: 20.00 − 2.00 bundle − 1.80 group (10 % of 18.00) − 2.12 coupon (10 % of 16.20 + 5.00 setup) = 19.08 incl. setup fee.
        self::assertSame([[200, null, null, 200 + 180 + 212, 200 + 180], [120, null, null, 120 + 108 + 97, 120 + 108]], array_map(static fn (array $i): array => [(int) $i['bundle_discount'], $i['promo_bp'], $i['sale_id'], (int) $i['discount'], (int) $i['recurring_discount']], $items));

        // A first-period bundle with a fixed amount: split by price, not renewed.
        $this->db->run('UPDATE nx_bundles SET is_active = 0');
        $this->db->run('UPDATE nx_sales SET is_active = 0');
        $this->bundle(['name' => 'Starter', 'discount_type' => 'fixed', 'discount_value' => 500, 'currency' => 'EUR', 'permanent' => false]);
        $third = $this->createUser();
        $this->actingAs($third);
        $this->add(['product_id' => (string) $this->dedicated, 'hostname' => 'dedi3']);
        $this->add(['product_id' => (string) $this->domain, 'domain' => 'drei.de']);
        $this->post('/cart/checkout', ['accept_terms' => '1', 'total' => (string) $this->cartTotal()]);
        self::assertSame([[313, 313, 0], [187, 187, 0]], array_map(static fn (array $i): array => [(int) $i['bundle_discount'], (int) $i['discount'], (int) $i['recurring_discount']], $orders($third)));
        self::assertSame(500, (int) $this->db->fetchValue('SELECT bundle_discount FROM nx_orders WHERE user_id = ?', [$third->id]));
    }

    public function testAdminManagesBundles(): void
    {
        $this->actingAs($this->createUser());
        self::assertSame(403, $this->get('/admin/bundles')->status());
        self::assertSame(403, $this->post('/admin/bundles', ['name' => 'X'])->status());

        $this->actingAs($this->createUser(Role::ADMIN));
        self::assertSame(200, $this->get('/admin/bundles/create')->status());
        $form = ['name' => 'Server + domain', 'discount_type' => 'percent', 'amount' => '10', 'permanent' => '1', 'is_active' => '1', 'product_ids' => [(string) $this->dedicated], 'categories' => ['domain']];
        $this->post('/admin/bundles', ['product_ids' => [(string) $this->dedicated], 'categories' => []] + $form);
        $this->post('/admin/bundles', ['amount' => '95'] + $form);
        $this->post('/admin/bundles', ['name' => '<b>x</b>'] + $form);
        $this->post('/admin/bundles', ['discount_type' => 'fixed', 'currency' => ''] + $form);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_bundles'), 'One component, over 90 %, markup, fixed without currency.');

        $response = $this->post('/admin/bundles', $form);
        $bundle = (array) $this->db->fetchOne('SELECT * FROM nx_bundles');
        $this->assertRedirect($response, '/admin/bundles/' . $bundle['id']);
        self::assertSame(['percent', 1000, 1], [$bundle['discount_type'], (int) $bundle['discount_value'], (int) $bundle['permanent']]);
        self::assertSame([['product_id' => $this->dedicated], ['category' => 'domain']], json_decode((string) $bundle['components'], true));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'bundle.created'"));
        $list = $this->get('/admin/bundles')->body();
        self::assertStringContainsString('Server + domain', $list);
        self::assertStringContainsString('Dedi-cart + Domain', $list);

        $this->post('/admin/bundles/' . $bundle['id'], ['discount_type' => 'fixed', 'amount' => '5.00', 'currency' => 'EUR'] + $form);
        $saved = (array) $this->db->fetchOne('SELECT discount_type, discount_value, currency FROM nx_bundles WHERE id = ?', [$bundle['id']]);
        self::assertSame(['fixed', 500, 'EUR'], [$saved['discount_type'], (int) $saved['discount_value'], $saved['currency']]);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'bundle.updated'"));
    }
}
