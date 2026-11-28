<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use DateTimeImmutable;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Billing\InvoiceService;
use Nebrix\Billing\OrderService;
use Nebrix\Payment\PaymentService;
use Nebrix\Provisioning\FulfilOrderJob;
use Nebrix\Provisioning\RetryLaterException;
use Nebrix\Repository\ProductRepository;
use Nebrix\Service\Addon\AddonProvisioners;
use Nebrix\Service\Addon\AddonRepository;
use Nebrix\Service\Addon\AddonService;
use Nebrix\Service\Addon\DeprovisionAddonJob;
use Nebrix\Service\Addon\ProvisionAddonJob;
use Nebrix\Service\ServiceRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\FakeAddonProvisioner;

/**
 * Add-ons: booking with the order and later (prorated), automatic set-up
 * through a provisioner (fake) after payment, the staff queue for kinds
 * without one, renewal lines, cancellation at the end of the term,
 * removal with the service, hasActive() and the admin catalog.
 */
final class AddonTest extends FeatureTestCase
{
    private FakeAddonProvisioner $fake;
    private int $lxc;
    private int $teamspeak;
    private int $ipv4;
    private int $support;
    private int $backup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-07-01 00:00:00'));
        $this->fake = new FakeAddonProvisioner();
        $fake = $this->fake;
        $this->app->container()->set(AddonProvisioners::class, static fn (): AddonProvisioners => new AddonProvisioners([$fake]));

        $products = $this->app->container()->get(ProductRepository::class);
        $this->lxc = $products->create([
            'category' => 'lxc', 'name' => 'LXC S', 'slug' => 'lxc-s', 'price' => 1000, 'setup_fee' => 0, 'currency' => 'EUR', 'billing_cycle' => 'monthly', 'billing_cycles' => ['annually' => 1500],
            'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0,
            'provisioning_config' => ['ostemplate' => 'local:vztmpl/debian-12.tar.zst', 'storage' => 'local-lvm', 'bridge' => 'vmbr0', 'cores' => 1, 'memory_mb' => 1024, 'disk_gb' => 10],
        ]);
        $this->teamspeak = $products->create([
            'category' => 'teamspeak', 'name' => 'TS', 'slug' => 'ts-addons', 'price' => 500, 'setup_fee' => 0, 'currency' => 'EUR', 'billing_cycle' => 'monthly',
            'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => ['slots' => 10],
        ]);
        $addons = $this->app->container()->get(AddonRepository::class);
        $this->ipv4 = $addons->save(null, ['name' => 'Additional IPv4', 'description' => 'One more address', 'kind' => 'ipv4', 'price' => 300, 'setup_fee' => 100, 'currency' => 'EUR', 'tax_rate_bp' => 1900, 'max_quantity' => 2, 'product_ids' => [], 'categories' => ['lxc'], 'is_active' => true], 1);
        $this->support = $addons->save(null, ['name' => 'Priority support', 'description' => '', 'kind' => 'priority_support', 'price' => 1000, 'setup_fee' => 0, 'currency' => 'EUR', 'tax_rate_bp' => 1900, 'max_quantity' => 1, 'product_ids' => [], 'categories' => ['teamspeak', 'lxc'], 'is_active' => true], 1);
        $this->backup = $addons->save(null, ['name' => 'Backup space', 'description' => '', 'kind' => 'backup_storage', 'price' => 500, 'setup_fee' => 0, 'currency' => 'EUR', 'tax_rate_bp' => 1900, 'max_quantity' => 1, 'product_ids' => [$this->lxc], 'categories' => [], 'is_active' => true], 1);
    }

    /**
     * @param array<int, string> $addons
     * @return array{order_id: int, invoice_id: int, paid: bool}
     */
    private function order(User $customer, int $productId, array $options, array $addons, ?string $cycle = null): array
    {
        return $this->app->container()->get(OrderService::class)->place($customer, $productId, $options, '127.0.0.1', null, $cycle, $addons);
    }

    private function pay(int $invoiceId): void
    {
        $total = (int) $this->db->fetchValue('SELECT total FROM nx_invoices WHERE id = ?', [$invoiceId]);
        self::assertTrue($this->app->container()->get(PaymentService::class)->recordPayment('manual', 'bank-' . $invoiceId, $invoiceId, $total, 'EUR')->isSuccessful());
    }

    /** Pays the order, runs the fulfilment job and activates the (manually provisioned) service. */
    private function fulfil(array $result, bool $activate = true): int
    {
        $this->pay($result['invoice_id']);
        $this->app->container()->get(FulfilOrderJob::class)->handle(['order_id' => $result['order_id']]);
        $serviceId = (int) $this->db->fetchValue('SELECT s.id FROM nx_services s JOIN nx_order_items i ON i.id = s.order_item_id WHERE i.order_id = ?', [$result['order_id']]);
        if ($activate) {
            $this->db->update('nx_services', ['status' => 'active', 'external_id' => 'ext-' . $serviceId], ['id' => $serviceId]);
        }

        return $serviceId;
    }

    private function runProvisionJobs(): void
    {
        foreach ($this->db->fetchAll("SELECT id, payload FROM nx_jobs WHERE type = ? AND status = 'pending' ORDER BY id", [AddonService::JOB_PROVISION]) as $job) {
            $this->db->run('DELETE FROM nx_jobs WHERE id = ?', [$job['id']]);
            $this->app->container()->get(ProvisionAddonJob::class)->handle((array) json_decode((string) $job['payload'], true));
        }
    }

    private function runDeprovisionJobs(): void
    {
        foreach ($this->db->fetchAll("SELECT id, payload FROM nx_jobs WHERE type = ? AND status = 'pending' ORDER BY id", [AddonService::JOB_DEPROVISION]) as $job) {
            $this->db->run('DELETE FROM nx_jobs WHERE id = ?', [$job['id']]);
            $this->app->container()->get(DeprovisionAddonJob::class)->handle((array) json_decode((string) $job['payload'], true));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function booked(int $serviceId): array
    {
        return (array) $this->db->fetchOne('SELECT * FROM nx_service_addons WHERE service_id = ? ORDER BY id DESC LIMIT 1', [$serviceId]);
    }

    public function testAddonOfAnOrderIsSetUpByItsProvisionerAfterPaymentAndRenewedAsOwnLine(): void
    {
        $customer = $this->createUser();
        $result = $this->order($customer, $this->lxc, ['hostname' => 'web1'], [$this->ipv4 => '2'], 'annually');
        $items = $this->db->fetchAll('SELECT * FROM nx_order_items WHERE order_id = ? ORDER BY id', [$result['order_id']]);
        self::assertCount(2, $items);
        // 2 × 3.00 per month × 12 months, setup fee 2 × 1.00.
        self::assertSame([null, $this->ipv4, (int) $items[0]['id'], 7200, 200, 'annually'], [$items[1]['product_id'], (int) $items[1]['addon_id'], (int) $items[1]['parent_item_id'], (int) $items[1]['unit_price'], (int) $items[1]['setup_fee'], $items[1]['billing_cycle']]);
        self::assertStringContainsString('Add-on Additional IPv4 × 2 (Annually) for web1', (string) $items[1]['description']);
        $invoice = (array) $this->db->fetchOne('SELECT * FROM nx_invoices WHERE id = ?', [$result['invoice_id']]);
        self::assertSame([17600, 1938 + 1406, 17600 + 3344], [(int) $invoice['subtotal'], (int) $invoice['tax'], (int) $invoice['total']]);
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_invoice_items WHERE invoice_id = ?', [$result['invoice_id']]));

        // Unpaid: nothing is set up.
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_service_addons'));
        $serviceId = $this->fulfil($result, false);
        $addon = $this->booked($serviceId);
        self::assertSame(['pending', 2, 7200, 'ipv4'], [$addon['status'], (int) $addon['quantity'], (int) $addon['recurring_amount'], $addon['kind']]);
        $this->app->container()->get(FulfilOrderJob::class)->handle(['order_id' => $result['order_id']]);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_service_addons'), 'Fulfilment is idempotent.');

        // The service is still being set up: the job waits.
        try {
            $this->app->container()->get(AddonService::class)->provision((int) $addon['id']);
            self::fail('The add-on was set up before its service.');
        } catch (RetryLaterException) {
            self::assertSame('auto', $this->booked($serviceId)['fulfilment']);
        }
        self::assertSame([], $this->fake->calls);

        $this->db->update('nx_services', ['status' => 'active', 'external_id' => 'ct-1'], ['id' => $serviceId]);
        $this->runProvisionJobs();
        $this->app->container()->get(AddonService::class)->provision((int) $addon['id']);
        self::assertSame([['provision', $serviceId, (int) $addon['id']]], $this->fake->calls, 'Provisioned exactly once.');
        $addon = $this->booked($serviceId);
        self::assertSame(['active', ['address' => '203.0.113.10']], [$addon['status'], json_decode((string) $addon['details'], true)]);
        self::assertTrue($this->mailQueued('addon_ready'));
        self::assertTrue($this->app->container()->get(AddonService::class)->hasActive($customer->id, 'ipv4'));

        // Renewal: the service and the add-on as separate lines.
        $service = $this->app->container()->get(ServiceRepository::class)->find($serviceId);
        self::assertNotNull($service);
        $renewal = (int) $this->app->container()->get(InvoiceService::class)->createRenewal($service);
        $lines = $this->db->fetchAll('SELECT * FROM nx_invoice_items WHERE invoice_id = ? ORDER BY id', [$renewal]);
        self::assertSame([10200, 7200], array_map(static fn (array $l): int => (int) $l['unit_amount'], $lines));
        self::assertStringContainsString('Add-on Additional IPv4 × 2', (string) $lines[1]['description']);
        self::assertSame([$lines[0]['period_start'], $lines[0]['period_end']], [$lines[1]['period_start'], $lines[1]['period_end']]);
        self::assertSame(17400 + 1938 + 1368, (int) $this->db->fetchValue('SELECT total FROM nx_invoices WHERE id = ?', [$renewal]));

        $this->actingAs($customer);
        $page = $this->get('/services/' . $serviceId)->body();
        self::assertStringContainsString('Additional IPv4', $page);
        self::assertStringContainsString('203.0.113.10', $page);
    }

    public function testAddonsInTheCartAndOnTheOrderPage(): void
    {
        $customer = $this->createUser();
        $this->actingAs($customer);
        $page = $this->get('/store/lxc-s')->body();
        self::assertStringContainsString('name="addons[' . $this->ipv4 . ']"', $page);
        self::assertStringContainsString('name="addons[' . $this->backup . ']"', $page);
        $teamspeak = $this->get('/store/ts-addons')->body();
        self::assertStringContainsString('name="addons[' . $this->support . ']"', $teamspeak);
        self::assertStringNotContainsString('name="addons[' . $this->backup . ']"', $teamspeak);

        $this->post('/cart/items', ['product_id' => (string) $this->lxc, 'hostname' => 'web1', 'addons' => [(string) $this->ipv4 => '3']]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_cart_items'), 'More than the maximum quantity.');
        $this->post('/cart/items', ['product_id' => (string) $this->teamspeak, 'server_name' => 'Clan', 'addons' => [(string) $this->backup => '1']]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_cart_items'), 'Not offered for this product.');
        $this->post('/cart/items', ['product_id' => (string) $this->lxc, 'hostname' => 'web1', 'addons' => [(string) $this->ipv4 => '1', (string) $this->backup => '0']]);
        $cart = $this->get('/cart')->body();
        self::assertStringContainsString('Add-on: Additional IPv4 × 1', $cart);
        preg_match('/name="total" value="(\d+)"/', $cart, $match);
        // 10.00 + 3.00 + 1.00 setup fee, plus 19 % VAT.
        self::assertSame(1400 + 190 + 76, (int) $match[1]);
        $this->post('/cart/checkout', ['accept_terms' => '1', 'total' => $match[1]]);
        $order = (int) $this->db->fetchValue('SELECT id FROM nx_orders WHERE user_id = ?', [$customer->id]);
        self::assertSame([null, $this->ipv4], array_map(static fn (array $i): ?int => $i['addon_id'] === null ? null : (int) $i['addon_id'], $this->db->fetchAll('SELECT addon_id FROM nx_order_items WHERE order_id = ? ORDER BY id', [$order])));
    }

    public function testAddonBookedLaterIsProratedAndManualKindsGoToTheStaffQueue(): void
    {
        $customer = $this->createUser();
        $serviceId = $this->fulfil($this->order($customer, $this->teamspeak, ['server_name' => 'Clan'], []));
        $this->clock()->freeze(new DateTimeImmutable('2026-07-17 00:00:00'));
        $this->actingAs($customer);
        $page = $this->get('/services/' . $serviceId)->body();
        self::assertStringContainsString('Book an add-on', $page);
        self::assertStringContainsString('Priority support', $page);
        self::assertStringNotContainsString('Backup space', $page, 'Only add-ons of this product.');

        $this->post('/services/' . $serviceId . '/addons', ['addon_id' => (string) $this->support, 'quantity' => '1']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_service_addons'), 'The booking with costs must be confirmed.');
        $this->post('/services/' . $serviceId . '/addons', ['addon_id' => (string) $this->backup, 'quantity' => '1', 'accept' => '1']);
        $this->post('/services/' . $serviceId . '/addons', ['addon_id' => (string) $this->support, 'quantity' => '2', 'accept' => '1']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_service_addons'), 'Not offered / above the maximum quantity.');

        $response = $this->post('/services/' . $serviceId . '/addons', ['addon_id' => (string) $this->support, 'quantity' => '1', 'accept' => '1', 'price' => '0']);
        $addon = $this->booked($serviceId);
        $invoice = (array) $this->db->fetchOne('SELECT * FROM nx_invoices WHERE id = ?', [$addon['invoice_id']]);
        $this->assertRedirect($response, '/invoices/' . $invoice['id']);
        // 10.00 per month, 15 of 31 days left (17 July to 1 August).
        self::assertSame(['addon', 'pending_payment', (int) round(1000 * 15 / 31)], [$invoice['type'], $addon['status'], (int) $invoice['subtotal']]);
        self::assertSame(1000, (int) $addon['recurring_amount']);
        self::assertFalse($this->app->container()->get(AddonService::class)->hasActive($customer->id, 'priority_support'), 'Not paid yet.');

        $this->pay((int) $invoice['id']);
        self::assertSame('pending', $this->booked($serviceId)['status']);
        $this->runProvisionJobs();
        self::assertSame(['pending', 'manual'], [$this->booked($serviceId)['status'], $this->booked($serviceId)['fulfilment']], 'No provisioner for this kind: staff set it up.');

        // Staff queue: finance may look, only services.manage may act.
        $this->actingAs($this->createUser(Role::FINANCE));
        self::assertStringContainsString('Priority support', $this->get('/admin/addon-queue')->body());
        self::assertSame(403, $this->post('/admin/addon-queue/' . $addon['id'] . '/done', ['details' => 'x'])->status());
        $this->actingAs($customer);
        self::assertSame(403, $this->get('/admin/addon-queue')->status());

        $this->actingAs($this->createUser(Role::SUPPORT));
        $this->post('/admin/addon-queue/' . $addon['id'] . '/done', ['details' => 'Hotline 0800 123']);
        self::assertSame(['active', ['info' => 'Hotline 0800 123']], [$this->booked($serviceId)['status'], json_decode((string) $this->booked($serviceId)['details'], true)]);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'addon.set_up_manually'"));
        self::assertStringNotContainsString('Priority support', $this->get('/admin/addon-queue')->body());

        $addons = $this->app->container()->get(AddonService::class);
        self::assertTrue($addons->hasActive($customer->id, 'priority_support'));
        self::assertFalse($addons->hasActive($this->createUser()->id, 'priority_support'));
        self::assertFalse($addons->hasActive($customer->id, 'ipv4'));
        $this->db->update('nx_services', ['status' => 'suspended'], ['id' => $serviceId]);
        self::assertFalse($addons->hasActive($customer->id, 'priority_support'), 'Suspended with its service.');
    }

    public function testBookingIsScopedToTheOwnerAndTheGroupPermission(): void
    {
        $owner = $this->createUser();
        $serviceId = $this->fulfil($this->order($owner, $this->teamspeak, ['server_name' => 'Clan'], []));
        $this->actingAs($this->createUser());
        self::assertSame(404, $this->post('/services/' . $serviceId . '/addons', ['addon_id' => (string) $this->support, 'quantity' => '1', 'accept' => '1'])->status());

        $group = $this->db->insert('nx_customer_groups', ['name' => 'Read only', 'discount_bp' => 0, 'can_order' => 1, 'can_manage_services' => 0, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->db->update('nx_users', ['customer_group_id' => $group], ['id' => $owner->id]);
        $this->actingAs($owner);
        self::assertSame(403, $this->post('/services/' . $serviceId . '/addons', ['addon_id' => (string) $this->support, 'quantity' => '1', 'accept' => '1'])->status());
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_service_addons'));
    }

    public function testCancellationAtTheEndOfTheTermAndRemovalByTheProvisioner(): void
    {
        $customer = $this->createUser();
        $serviceId = $this->fulfil($this->order($customer, $this->lxc, ['hostname' => 'web1'], [$this->ipv4 => '1']));
        $this->runProvisionJobs();
        $addon = $this->booked($serviceId);
        self::assertSame('active', $addon['status']);

        // An unpaid renewal with the add-on was already issued.
        $service = $this->app->container()->get(ServiceRepository::class)->find($serviceId);
        self::assertNotNull($service);
        $renewal = (int) $this->app->container()->get(InvoiceService::class)->createRenewal($service);
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_invoice_items WHERE invoice_id = ?', [$renewal]));

        $this->actingAs($customer);
        $confirm = $this->get('/services/' . $serviceId . '/addons/' . $addon['id'] . '/cancel')->body();
        self::assertStringContainsString('Ends on', $confirm);
        self::assertStringContainsString('01.08.2026', $confirm);
        $this->post('/services/' . $serviceId . '/addons/' . $addon['id'] . '/cancel');
        self::assertNull($this->booked($serviceId)['cancel_at'], 'The cancellation must be confirmed.');
        $this->post('/services/' . $serviceId . '/addons/' . $addon['id'] . '/cancel', ['confirm' => '1']);
        self::assertSame('2026-08-01 00:00:00', $this->booked($serviceId)['cancel_at'], 'End of the paid term.');
        self::assertTrue($this->mailQueued('addon_cancelled'));
        self::assertStringContainsString('2026-08-01', (string) $this->lastMail('addon_cancelled')['subject']);
        // The open renewal was issued again without the add-on.
        self::assertSame('cancelled', $this->db->fetchValue('SELECT status FROM nx_invoices WHERE id = ?', [$renewal]));
        $open = (int) $this->db->fetchValue("SELECT id FROM nx_invoices WHERE service_id = ? AND type = 'renewal' AND status = 'unpaid'", [$serviceId]);
        self::assertSame([1000], array_map(static fn (array $l): int => (int) $l['unit_amount'], $this->db->fetchAll('SELECT unit_amount FROM nx_invoice_items WHERE invoice_id = ?', [$open])));
        self::assertStringContainsString('Cancelled – ends on', $this->get('/services/' . $serviceId)->body());

        // Another customer cannot cancel it.
        $this->actingAs($this->createUser());
        self::assertSame(404, $this->get('/services/' . $serviceId . '/addons/' . $addon['id'] . '/cancel')->status());
        self::assertSame(404, $this->post('/services/' . $serviceId . '/addons/' . $addon['id'] . '/cancel', ['confirm' => '1'])->status());

        // Until the end date nothing is removed; then the provisioner removes it.
        $addons = $this->app->container()->get(AddonService::class);
        self::assertSame(0, $addons->process()['ended']);
        $this->clock()->freeze(new DateTimeImmutable('2026-08-01 00:01:00'));
        self::assertSame(1, $addons->process()['ended']);
        self::assertSame('removing', $this->booked($serviceId)['status']);
        $this->runDeprovisionJobs();
        self::assertSame('removed', $this->booked($serviceId)['status']);
        self::assertSame(['provision', 'deprovision'], array_column($this->fake->calls, 0));
        self::assertFalse($addons->hasActive($customer->id, 'ipv4'));
    }

    public function testCancellationCanBeTakenBackAndUnpaidBookingsAreWithdrawn(): void
    {
        $customer = $this->createUser();
        $serviceId = $this->fulfil($this->order($customer, $this->teamspeak, ['server_name' => 'Clan'], []));
        $this->actingAs($customer);
        $this->post('/services/' . $serviceId . '/addons', ['addon_id' => (string) $this->support, 'quantity' => '1', 'accept' => '1']);
        $booking = $this->booked($serviceId);
        self::assertSame('pending_payment', $booking['status']);
        // Withdrawing an unpaid booking cancels its invoice.
        $this->post('/services/' . $serviceId . '/addons/' . $booking['id'] . '/cancel', ['confirm' => '1']);
        self::assertSame(['cancelled', 'cancelled'], [$this->booked($serviceId)['status'], $this->db->fetchValue('SELECT status FROM nx_invoices WHERE id = ?', [$booking['invoice_id']])]);

        // A renewal issued before the booking was paid withdraws it as well.
        $this->post('/services/' . $serviceId . '/addons', ['addon_id' => (string) $this->support, 'quantity' => '1', 'accept' => '1']);
        $booking = $this->booked($serviceId);
        $service = $this->app->container()->get(ServiceRepository::class)->find($serviceId);
        self::assertNotNull($service);
        $this->app->container()->get(InvoiceService::class)->createRenewal($service);
        self::assertSame('cancelled', $this->booked($serviceId)['status']);
        self::assertStringContainsString('Please pay the open renewal invoice first.', $this->get('/services/' . $serviceId)->body(), 'No bookings while the next term is invoiced.');

        // A paid add-on: cancelled and taken back again.
        $this->db->run("UPDATE nx_invoices SET status = 'cancelled' WHERE service_id = ? AND type = 'renewal'", [$serviceId]);
        $this->post('/services/' . $serviceId . '/addons', ['addon_id' => (string) $this->support, 'quantity' => '1', 'accept' => '1']);
        $booking = $this->booked($serviceId);
        $this->pay((int) $booking['invoice_id']);
        $this->post('/services/' . $serviceId . '/addons/' . $booking['id'] . '/cancel', ['confirm' => '1']);
        self::assertNotNull($this->booked($serviceId)['cancel_at']);
        $this->post('/services/' . $serviceId . '/addons/' . $booking['id'] . '/cancel/withdraw');
        self::assertNull($this->booked($serviceId)['cancel_at']);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'addon.cancellation_withdrawn'"));

        // Only one unit can be booked; unpaid bookings of another service expire after 14 days.
        $this->post('/services/' . $serviceId . '/addons', ['addon_id' => (string) $this->support, 'quantity' => '1', 'accept' => '1']);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_service_addons WHERE status = 'pending_payment'"));
        $second = $this->fulfil($this->order($customer, $this->teamspeak, ['server_name' => 'Two'], []));
        $this->post('/services/' . $second . '/addons', ['addon_id' => (string) $this->support, 'quantity' => '1', 'accept' => '1']);
        self::assertSame('pending_payment', $this->booked($second)['status']);
        $this->clock()->freeze(new DateTimeImmutable('2026-07-16 00:00:00'));
        self::assertSame(1, $this->app->container()->get(AddonService::class)->process()['expired']);
        self::assertSame('cancelled', $this->booked($second)['status']);
    }

    public function testAddonsEndWithTheirServiceAndFailuresGoToStaff(): void
    {
        $customer = $this->createUser();
        $lxcService = $this->fulfil($this->order($customer, $this->lxc, ['hostname' => 'web1'], [$this->ipv4 => '1', $this->support => '1']));
        $this->fake->failWith = new \RuntimeException('No free address in the pool.');
        $job = $this->app->container()->get(ProvisionAddonJob::class);
        foreach ($this->db->fetchAll('SELECT id FROM nx_service_addons WHERE service_id = ? ORDER BY id', [$lxcService]) as $row) {
            try {
                $job->handle(['service_addon_id' => (int) $row['id']]);
            } catch (\RuntimeException $exception) {
                $job->failed(['service_addon_id' => (int) $row['id']], $exception->getMessage());
            }
        }
        $rows = $this->db->fetchAll('SELECT * FROM nx_service_addons WHERE service_id = ? ORDER BY id', [$lxcService]);
        self::assertSame([['failed', 'auto'], ['pending', 'manual']], array_map(static fn (array $r): array => [$r['status'], $r['fulfilment']], $rows));

        $admin = $this->createUser(Role::ADMIN);
        $this->actingAs($admin);
        $queue = $this->get('/admin/addon-queue')->body();
        self::assertStringContainsString('No free address in the pool.', $queue);
        self::assertStringContainsString('Retry automatic set-up', $queue);
        $servicePage = $this->get('/admin/services/' . $lxcService)->body();
        self::assertStringContainsString('Additional IPv4', $servicePage);
        self::assertStringContainsString('Priority support', $servicePage);
        $this->fake->failWith = null;
        $this->post('/admin/addon-queue/' . $rows[0]['id'] . '/retry');
        $this->runProvisionJobs();
        self::assertSame('active', $this->db->fetchValue('SELECT status FROM nx_service_addons WHERE id = ?', [$rows[0]['id']]));
        $this->post('/admin/addon-queue/' . $rows[1]['id'] . '/done');
        self::assertSame('active', $this->db->fetchValue('SELECT status FROM nx_service_addons WHERE id = ?', [$rows[1]['id']]));

        // The service is terminated: the automatic add-on is removed by its provisioner, the manual one by staff.
        $this->db->update('nx_services', ['status' => 'terminated'], ['id' => $lxcService]);
        self::assertSame(2, $this->app->container()->get(AddonService::class)->process()['ended']);
        $this->runDeprovisionJobs();
        self::assertSame(['removed', 'removing'], array_column($this->db->fetchAll('SELECT status FROM nx_service_addons WHERE service_id = ? ORDER BY id', [$lxcService]), 'status'));
        self::assertStringContainsString('to remove', $this->get('/admin/addon-queue')->body());
        $this->post('/admin/addon-queue/' . $rows[1]['id'] . '/removed', ['note' => 'Contract ended']);
        self::assertSame('removed', $this->db->fetchValue('SELECT status FROM nx_service_addons WHERE id = ?', [$rows[1]['id']]));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'addon.removed_manually'"));
        self::assertSame(0, $this->app->container()->get(AddonService::class)->process()['ended'], 'Idempotent.');
    }

    public function testAdminManagesTheAddonCatalog(): void
    {
        $this->actingAs($this->createUser(Role::SUPPORT));
        self::assertSame(200, $this->get('/admin/addons')->status());
        self::assertSame(403, $this->post('/admin/addons', ['name' => 'X'])->status(), 'products.manage is required.');

        $this->actingAs($this->createUser(Role::ADMIN));
        $form = ['name' => 'Snapshot slot', 'kind' => 'custom', 'price' => '2.50', 'setup_fee' => '0', 'currency' => 'EUR', 'tax_rate' => '19', 'max_quantity' => '3', 'categories' => ['vps'], 'is_active' => '1'];
        $this->post('/admin/addons', ['categories' => []] + $form);
        $this->post('/admin/addons', ['kind' => 'gold'] + $form);
        $this->post('/admin/addons', ['name' => '<i>x</i>'] + $form);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_addons WHERE name LIKE '%x%' OR name = 'Snapshot slot'"), 'Offered nowhere, unknown kind, markup.');

        $this->post('/admin/addons', $form);
        $row = (array) $this->db->fetchOne("SELECT * FROM nx_addons WHERE name = 'Snapshot slot'");
        self::assertSame(['custom', 250, 3, 'vps', 1900], [$row['kind'], (int) $row['price'], (int) $row['max_quantity'], $row['categories'], (int) $row['tax_rate_bp']]);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'addon.created'"));
        self::assertStringContainsString('Snapshot slot', $this->get('/admin/addons')->body());
        $this->post('/admin/addons/' . $row['id'], ['price' => '3.00', 'is_active' => '0'] + $form);
        self::assertSame([300, 0], [(int) $this->db->fetchValue('SELECT price FROM nx_addons WHERE id = ?', [$row['id']]), (int) $this->db->fetchValue('SELECT is_active FROM nx_addons WHERE id = ?', [$row['id']])]);
    }
}
