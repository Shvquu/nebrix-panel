<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use DateTimeImmutable;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Billing\FraudCheck;
use Nebrix\Billing\OrderRepository;
use Nebrix\Provisioning\FulfilOrderJob;
use Nebrix\Queue\PermanentJobFailure;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Fraud check of paid orders before they are set up.
 */
final class FraudCheckTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-06-25 12:00:00'));
        $this->app->container()->get(SettingsRepository::class)->set('fraud.enabled', true);
    }

    private function order(User $user, string $ip = '198.51.100.7', int $total = 1190, string $status = 'pending'): int
    {
        [$id] = $this->app->container()->get(OrderRepository::class)->create(
            ['number' => 'ORD-' . bin2hex(random_bytes(3)), 'user_id' => $user->id, 'status' => $status, 'currency' => 'EUR', 'subtotal' => $total, 'tax' => 0, 'total' => $total, 'ip' => $ip],
            [['description' => 'LXC Small', 'unit_price' => $total, 'tax_rate_bp' => 0, 'billing_cycle' => 'monthly']],
        );

        return $id;
    }

    /** Runs the fulfilment job: true when it went on to set the order up. */
    private function fulfils(int $orderId): bool
    {
        try {
            $this->app->container()->get(FulfilOrderJob::class)->handle(['order_id' => $orderId]);

            return false;
        } catch (PermanentJobFailure) {
            return true; // reached the fulfilment (these test orders are not paid)
        }
    }

    private function fraudStatus(int $orderId): ?string
    {
        $value = $this->db->fetchValue('SELECT fraud_status FROM nx_orders WHERE id = ?', [$orderId]);

        return is_string($value) ? $value : null;
    }

    public function testInconspicuousOrdersPassAndThrowAwayAddressesAreHeld(): void
    {
        $clean = $this->order($this->createUser(attributes: ['country' => 'DE']));
        self::assertTrue($this->fulfils($clean));
        self::assertSame('clear', $this->fraudStatus($clean));

        $suspicious = $this->order($this->createUser(attributes: ['email' => 'buyer@mailinator.com', 'country' => 'DE']), total: 50000);
        self::assertFalse($this->fulfils($suspicious), 'Held: not set up.');
        self::assertSame('review', $this->fraudStatus($suspicious));
        self::assertTrue($this->mailQueued('order_review'));
        $reasons = array_column(json_decode((string) $this->db->fetchValue('SELECT fraud_reasons FROM nx_orders WHERE id = ?', [$suspicious]), true), 'rule');
        self::assertSame(['disposable_email', 'new_account_high_value'], $reasons);
        self::assertFalse($this->fulfils($suspicious), 'Stays held on retries.');
    }

    public function testStaffApproveOrRejectHeldOrders(): void
    {
        $customer = $this->createUser(attributes: ['email' => 'x@sub.yopmail.com', 'country' => 'DE']);
        $first = $this->order($customer, total: 50000);
        $second = $this->order($customer, total: 50000);
        $this->fulfils($first);
        $this->fulfils($second);

        $this->actingAs($customer);
        self::assertSame(403, $this->get('/admin/orders/review')->status());
        $this->actingAs($this->createUser(Role::SUPPORT));
        self::assertSame(200, $this->get('/admin/orders/review')->status());
        self::assertSame(403, $this->post('/admin/orders/review/' . $first . '/approve')->status(), 'Viewing is not deciding.');

        $this->actingAs($this->createUser(Role::ADMIN));
        $page = $this->get('/admin/orders/review')->body();
        self::assertStringContainsString('yopmail.com', $page);
        $this->post('/admin/orders/review/' . $first . '/approve');
        self::assertSame('approved', $this->fraudStatus($first));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_jobs WHERE unique_key = ?', ['order.fulfil:' . $first . ':approved']));
        self::assertTrue($this->fulfils($first), 'Approved orders are set up.');

        $this->post('/admin/orders/review/' . $second . '/reject');
        self::assertSame('rejected', $this->fraudStatus($second));
        self::assertFalse($this->fulfils($second));
        $this->post('/admin/orders/review/' . $second . '/approve');
        self::assertSame('rejected', $this->fraudStatus($second), 'Decisions are final.');

        // An earlier rejection counts for the next order of the customer.
        $third = $this->order($customer);
        self::assertContains('previously_rejected', array_column($this->app->container()->get(FraudCheck::class)->assess((array) $this->db->fetchOne('SELECT * FROM nx_orders WHERE id = ?', [$third]))['reasons'], 'rule'));
    }

    public function testSharedIpChargebacksAndPaymentCountry(): void
    {
        $ip = '203.0.113.50';
        $fraudster = $this->createUser(attributes: ['country' => 'DE']);
        $this->order($fraudster, $ip);
        $this->db->insert('nx_transactions', ['user_id' => $fraudster->id, 'gateway' => 'stripe', 'type' => 'dispute', 'status' => 'completed', 'reference' => 'dp_1', 'amount' => 1190, 'currency' => 'EUR', 'created_at' => '2026-06-20 10:00:00', 'updated_at' => '2026-06-20 10:00:00']);
        $this->order($this->createUser(attributes: ['country' => 'DE']), $ip);

        $customer = $this->createUser(attributes: ['country' => 'DE']);
        $orderId = $this->order($customer, $ip);
        $this->db->insert('nx_invoices', ['number' => 'INV-F-1', 'user_id' => $customer->id, 'order_id' => $orderId, 'type' => 'order', 'status' => 'paid', 'currency' => 'EUR', 'subtotal' => 1190, 'tax' => 0, 'total' => 1190, 'tax_country' => 'DE', 'payment_country' => 'NG', 'due_at' => '2026-06-25 12:00:00', 'created_at' => '2026-06-25 12:00:00', 'updated_at' => '2026-06-25 12:00:00']);

        $result = $this->app->container()->get(FraudCheck::class)->assess((array) $this->db->fetchOne('SELECT * FROM nx_orders WHERE id = ?', [$orderId]));
        self::assertSame(['payment_country_mismatch', 'shared_ip', 'ip_with_chargebacks'], array_column($result['reasons'], 'rule'));
        self::assertSame(80, $result['score']);
        self::assertFalse($this->fulfils($orderId));
    }

    public function testCustomDomainsAndTheSwitch(): void
    {
        $settings = $this->app->container()->get(SettingsRepository::class);
        $settings->set('fraud.blocked_email_domains', "example-trash.test\nother.test");
        $settings->set('fraud.review_score', 40);
        $order = $this->order($this->createUser(attributes: ['email' => 'a@mx.example-trash.test', 'country' => 'DE']));
        self::assertFalse($this->fulfils($order));

        $settings->set('fraud.enabled', false);
        $other = $this->order($this->createUser(attributes: ['email' => 'b@other.test', 'country' => 'DE']));
        self::assertTrue($this->fulfils($other));
        self::assertNull($this->fraudStatus($other), 'Not assessed while switched off.');
    }
}
