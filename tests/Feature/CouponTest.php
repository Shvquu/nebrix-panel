<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use Nebrix\Api\ApiScope;
use Nebrix\Api\ApiTokenService;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Billing\Coupons\CouponService;
use Nebrix\Billing\InvoiceService;
use Nebrix\Billing\OrderException;
use Nebrix\Billing\OrderService;
use Nebrix\Http\Request;
use Nebrix\Provisioning\ProvisioningService;
use Nebrix\Repository\ProductRepository;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Coupon codes: admin management, price preview, order, limits, release on
 * cancellation, discounts on renewals, API.
 */
final class CouponTest extends FeatureTestCase
{
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->productId = $this->product('root-s', 1000, 500);
    }

    private function product(string $slug, int $price, int $setupFee = 0, string $currency = 'EUR'): int
    {
        return $this->app->container()->get(ProductRepository::class)->create([
            'category' => 'dedicated', 'name' => 'Root ' . $slug, 'slug' => $slug, 'price' => $price, 'setup_fee' => $setupFee, 'currency' => $currency,
            'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => [],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function coupon(string $code, array $overrides = []): int
    {
        return $this->app->container()->get(CouponService::class)->save(null, $overrides + [
            'code' => $code, 'type' => 'percent', 'value' => 1000, 'currency' => null, 'duration' => 'once', 'product_ids' => [],
            'valid_from' => null, 'valid_until' => null, 'max_uses' => null, 'max_uses_per_customer' => 1, 'new_customers_only' => false, 'is_active' => true,
        ], 1);
    }

    private function order(User $customer, ?string $code, ?int $productId = null): array
    {
        return $this->app->container()->get(OrderService::class)->place($customer, $productId ?? $this->productId, ['hostname' => 'srv' . random_int(1, 9999)], '127.0.0.1', $code);
    }

    public function testAdminCreatesACouponAndACustomerRedeemsIt(): void
    {
        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/coupons', ['code' => ' welcome 10 ', 'type' => 'percent', 'amount' => '10', 'currency' => 'EUR', 'duration' => 'once', 'max_uses_per_customer' => '1', 'is_active' => '1']);
        $coupon = (array) $this->db->fetchOne('SELECT * FROM nx_coupons');
        self::assertSame(['WELCOME10', 'percent', 1000, 1], [$coupon['code'], $coupon['type'], (int) $coupon['value'], (int) $coupon['is_active']]);
        $this->post('/admin/coupons', ['code' => 'welcome10', 'type' => 'percent', 'amount' => '5', 'duration' => 'once', 'max_uses_per_customer' => '1']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_coupons'), 'Codes are unique.');
        self::assertStringContainsString('WELCOME10', $this->get('/admin/coupons')->body());

        $customer = $this->createUser();
        $this->actingAs($customer);
        $page = $this->get('/store/root-s', ['coupon' => 'welcome10'])->body();
        self::assertStringContainsString('Coupon WELCOME10 applied', $page);
        self::assertStringContainsString('−€1.50', $page, '10 % of price and setup fee (15.00 €).');
        self::assertStringContainsString('name="coupon" value="WELCOME10"', $page);
        self::assertStringContainsString('This coupon code is not valid.', $this->get('/store/root-s', ['coupon' => 'NOPE'])->body());

        $this->post('/store/root-s', ['hostname' => 'web1', 'accept_terms' => '1', 'coupon' => 'WELCOME10']);
        $order = (array) $this->db->fetchOne('SELECT * FROM nx_orders WHERE user_id = ?', [$customer->id]);
        self::assertSame(['WELCOME10', 150, 1500, 150, 1350], [$order['coupon_code'], (int) $order['coupon_discount'], (int) $order['subtotal'], (int) $order['discount'], (int) $order['total'] - (int) $order['tax']]);
        $line = (array) $this->db->fetchOne('SELECT description, unit_amount FROM nx_invoice_items i JOIN nx_invoices v ON v.id = i.invoice_id WHERE v.order_id = ?', [$order['id']]);
        self::assertSame(1350, (int) $line['unit_amount']);
        self::assertStringContainsString('Coupon WELCOME10', (string) $line['description']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT uses FROM nx_coupons'));

        // Once per customer; cancelling the unpaid order gives the use back.
        $this->post('/store/root-s', ['hostname' => 'web2', 'accept_terms' => '1', 'coupon' => 'WELCOME10']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_orders WHERE user_id = ?', [$customer->id]));
        self::assertStringContainsString('You have already used this coupon code.', $this->get('/store/root-s')->body());
        $this->app->container()->get(OrderService::class)->cancel((int) $order['id']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT uses FROM nx_coupons'));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_coupon_redemptions'));
        $this->order($customer, 'welcome10');
        self::assertSame(1, (int) $this->db->fetchValue('SELECT uses FROM nx_coupons'));
    }

    public function testRulesAreEnforced(): void
    {
        $customer = $this->createUser();
        $check = function (string $code, string $message, ?int $productId = null) use ($customer): void {
            try {
                $this->order($customer, $code, $productId);
                self::fail('Coupon ' . $code . ' was accepted.');
            } catch (OrderException $exception) {
                self::assertSame($message, $exception->getMessage(), $code);
            }
        };
        $now = $this->clock()->now();
        $this->coupon('EXPIRED', ['valid_until' => $now->modify('-1 day')->format('Y-m-d H:i:s')]);
        $this->coupon('LATER', ['valid_from' => $now->modify('+1 day')->format('Y-m-d H:i:s')]);
        $this->coupon('OFF', ['is_active' => false]);
        $this->coupon('OTHER', ['product_ids' => [$this->product('other', 500)]]);
        $this->coupon('USD5', ['type' => 'fixed', 'value' => 500, 'currency' => 'USD']);
        $this->coupon('ONCE', ['max_uses' => 1]);
        $this->coupon('NEW', ['new_customers_only' => true]);

        $check('EXPIRED', 'This coupon code has expired.');
        $check('LATER', 'This coupon code is not valid yet.');
        $check('OFF', 'This coupon code is not valid.');
        $check('OTHER', 'This coupon code is not valid for this product.');
        $check('USD5', 'This coupon code is not valid for this product.');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_orders'), 'Rejected codes create no order.');

        $this->order($customer, 'ONCE');
        $other = $this->createUser();
        try {
            $this->order($other, 'ONCE');
            self::fail('The total limit was exceeded.');
        } catch (OrderException $exception) {
            self::assertSame('This coupon code has been used up.', $exception->getMessage());
        }
        $check('NEW', 'This coupon code is only valid for new customers.');
        $this->order($other, 'NEW');

        // 100 %: the order is free and settled at once.
        $this->coupon('FREE', ['value' => 10000]);
        $free = $this->order($this->createUser(), 'FREE');
        self::assertTrue($free['paid']);
    }

    public function testForeverCouponsLowerEveryRenewal(): void
    {
        $customer = $this->createUser();
        $this->coupon('LOYAL', ['type' => 'fixed', 'value' => 200, 'currency' => 'EUR', 'duration' => 'forever']);
        $this->coupon('START', ['value' => 5000, 'duration' => 'once']);

        $loyal = $this->order($customer, 'LOYAL');
        $start = $this->order($this->createUser(), 'START');
        $provisioning = $this->app->container()->get(ProvisioningService::class);
        foreach ([$loyal, $start] as $result) {
            $this->db->update('nx_orders', ['status' => 'paid'], ['id' => $result['order_id']]);
            $provisioning->fulfilOrder($result['order_id']);
        }
        $amount = fn (array $result): int => (int) $this->db->fetchValue('SELECT s.recurring_amount FROM nx_services s JOIN nx_order_items i ON i.id = s.order_item_id WHERE i.order_id = ?', [$result['order_id']]);
        self::assertSame(800, $amount($loyal), '2.00 € off every renewal.');
        self::assertSame(1000, $amount($start), 'A one-time coupon does not lower renewals.');

        $service = $this->app->container()->get(\Nebrix\Service\ServiceRepository::class)->find((int) $this->db->fetchValue('SELECT s.id FROM nx_services s JOIN nx_order_items i ON i.id = s.order_item_id WHERE i.order_id = ?', [$loyal['order_id']]));
        self::assertNotNull($service);
        $renewal = $this->app->container()->get(InvoiceService::class)->createRenewal($service);
        self::assertSame(800, (int) $this->db->fetchValue('SELECT subtotal FROM nx_invoices WHERE id = ?', [$renewal]));
    }

    public function testApiOrdersAcceptCouponsAndPreviewChecksAreLimited(): void
    {
        $this->app->container()->get(SettingsRepository::class)->set('api.enabled', true);
        $customer = $this->createUser();
        $this->coupon('API10');
        $token = $this->app->container()->get(ApiTokenService::class)->create($customer, 'Shop', [ApiScope::ORDERS_CREATE], 30, [], null)['secret'];
        $call = fn (array $body) => $this->app->handle(Request::create('POST', '/api/v1/orders', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'], (string) json_encode($body)));

        $bad = $call(['product_id' => $this->productId, 'accept_terms' => true, 'options' => ['hostname' => 'api1'], 'coupon' => 'WRONG']);
        self::assertSame(422, $bad->status());
        self::assertSame('coupon_invalid', json_decode($bad->body(), true)['error']['code']);
        $ok = $call(['product_id' => $this->productId, 'accept_terms' => true, 'options' => ['hostname' => 'api2'], 'coupon' => 'api10']);
        self::assertSame(201, $ok->status());
        self::assertSame(['API10', 150], [json_decode($ok->body(), true)['data']['order']['coupon_code'], json_decode($ok->body(), true)['data']['order']['coupon_discount']]);

        $this->actingAs($customer);
        for ($i = 0; $i < 20; $i++) {
            $this->get('/store/root-s', ['coupon' => 'GUESS' . $i]);
        }
        self::assertSame(429, $this->get('/store/root-s', ['coupon' => 'GUESS20'])->status(), 'Codes cannot be guessed by trying.');
    }
}
