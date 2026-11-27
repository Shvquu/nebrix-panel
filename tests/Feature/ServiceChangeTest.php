<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use Nebrix\Api\ApiScope;
use Nebrix\Api\ApiTokenService;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Billing\InvoiceService;
use Nebrix\Core\Config;
use Nebrix\Http\Request;
use Nebrix\Integration\IntegrationLogger;
use Nebrix\Integration\IntegrationManager;
use Nebrix\Integration\IntegrationRepository;
use Nebrix\Payment\PaymentService;
use Nebrix\Repository\ProductRepository;
use Nebrix\Security\UrlGuard;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceChangeJob;
use Nebrix\Service\ServiceChangeService;
use Nebrix\Service\ServiceRepository;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\FakeHttp;
use Psr\Http\Client\ClientInterface;

/**
 * Upgrades (prorated invoice, change after payment) and downgrades (at the
 * end of the paid period) of a Proxmox container against a fake API.
 */
final class ServiceChangeTest extends FeatureTestCase
{
    private FakeHttp $http;
    private int $integration;

    /** @var array<string, string> current container settings */
    private array $guest = ['rootfs' => 'local-lvm:vm-20001-disk-0,size=10G', 'net0' => 'name=eth0,bridge=vmbr0,hwaddr=BC:24:11:00:00:01,ip=dhcp', 'cores' => '1', 'memory' => '1024'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-07-01 00:00:00'));
        $this->http = new FakeHttp();
        $container = $this->app->container();
        $container->set(ClientInterface::class, fn (): ClientInterface => $this->http->client());
        $container->set(IntegrationManager::class, fn ($c): IntegrationManager => new IntegrationManager(
            $c->get(IntegrationRepository::class),
            $c->get(ClientInterface::class),
            $c->get(UrlGuard::class),
            $c->get(IntegrationLogger::class),
            $c->get(Config::class),
        ));
        $this->integration = $container->get(IntegrationRepository::class)->create('proxmox', 'Cluster', true, ['base_url' => 'https://203.0.113.10:8006', 'token_id' => 'nebrix@pve!panel', 'verify_tls' => true], ['token_secret' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0']);
        $json = static fn (mixed $data): Response => new Response(200, [], json_encode(['data' => $data], JSON_THROW_ON_ERROR));
        $this->http
            ->on('GET', '/api2/json/nodes/pve1/lxc/20001/config', fn (): Response => $json($this->guest))
            ->on('PUT', '/api2/json/nodes/pve1/lxc/20001/config', function ($request) use ($json): Response {
                $this->guest = array_merge($this->guest, FakeHttp::form($request));

                return $json(null);
            })
            ->on('PUT', '/api2/json/nodes/pve1/lxc/20001/resize', function ($request) use ($json): Response {
                $this->guest['rootfs'] = 'local-lvm:vm-20001-disk-0,size=' . FakeHttp::form($request)['size'];

                return $json(null);
            });
    }

    /**
     * @param array<string, mixed> $resources
     */
    private function product(string $name, int $price, array $resources, ?string $group = 'lxc-fsn', ?int $stock = null): int
    {
        return $this->app->container()->get(ProductRepository::class)->create([
            'category' => 'lxc', 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)) . '-' . random_int(1, 99999), 'price' => $price, 'setup_fee' => 0,
            'currency' => 'EUR', 'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 1,
            'integration_id' => $this->integration, 'upgrade_group' => $group, 'stock' => $stock,
            'provisioning_config' => $resources + ['ostemplate' => 'local:vztmpl/debian-12.tar.zst', 'storage' => 'local-lvm', 'bridge' => 'vmbr0', 'rate_mbps' => 0, 'unprivileged' => true, 'vmid_start' => 20000],
        ]);
    }

    private function service(User $owner, int $productId, int $amount): Service
    {
        $product = $this->app->container()->get(ProductRepository::class)->find($productId);
        self::assertNotNull($product);
        $services = $this->app->container()->get(ServiceRepository::class);
        $id = $services->create([
            'user_id' => $owner->id, 'product_id' => $productId, 'integration_id' => $this->integration, 'category' => 'lxc', 'name' => 'web01',
            'status' => Service::STATUS_ACTIVE, 'billing_cycle' => 'monthly', 'recurring_amount' => $amount, 'tax_rate_bp' => 1900, 'currency' => 'EUR',
            // Paid until 16 July: half of the period from 16 June is left.
            'next_due_at' => '2026-07-16 00:00:00',
            'config' => $product['provisioning_config'] + ['ip_pool_id' => 7, '_options' => ['hostname' => 'web01']],
        ]);
        $services->mergeRemote($id, ['node' => 'pve1', 'vmid' => 20001, 'type' => 'lxc', 'step' => 'done']);

        return $services->find($id) ?? throw new \RuntimeException('Service missing.');
    }

    private function runChangeJobs(): void
    {
        foreach ($this->db->fetchAll("SELECT `id`, `payload` FROM `nx_jobs` WHERE `type` = ? AND `status` = 'pending' ORDER BY `id`", [InvoiceService::JOB_APPLY_CHANGE]) as $job) {
            $this->db->run('DELETE FROM `nx_jobs` WHERE `id` = ?', [$job['id']]);
            $this->app->container()->get(ServiceChangeJob::class)->handle((array) json_decode((string) $job['payload'], true));
        }
    }

    private function change(int $serviceId): array
    {
        return (array) $this->db->fetchOne('SELECT * FROM `nx_service_changes` WHERE `service_id` = ? ORDER BY `id` DESC LIMIT 1', [$serviceId]);
    }

    public function testUpgradeIsInvoicedProratedAndAppliedAfterPayment(): void
    {
        $small = $this->product('LXC S', 1000, ['cores' => 1, 'memory_mb' => 1024, 'swap_mb' => 512, 'disk_gb' => 10]);
        $medium = $this->product('LXC M', 2000, ['cores' => 2, 'memory_mb' => 2048, 'swap_mb' => 1024, 'disk_gb' => 20]);
        $tiny = $this->product('LXC XS', 500, ['cores' => 1, 'memory_mb' => 512, 'swap_mb' => 256, 'disk_gb' => 5]);
        $this->product('LXC Other group', 3000, ['cores' => 4, 'memory_mb' => 4096, 'swap_mb' => 0, 'disk_gb' => 40], 'other');
        $customer = $this->createUser();
        $service = $this->service($customer, $small, 1000);

        $this->actingAs($customer);
        self::assertStringContainsString('/services/' . $service->id . '/package', $this->get('/services/' . $service->id)->body());
        $page = $this->get('/services/' . $service->id . '/package')->body();
        self::assertStringContainsString('LXC M', $page);
        self::assertStringContainsString('€5.95', $page, '15 of 30 days × 10.00 € + 19 % VAT.');
        self::assertStringContainsString('The disk cannot be made smaller.', $page, 'XS has a smaller disk.');
        self::assertStringNotContainsString('Other group', $page);

        $response = $this->post('/services/' . $service->id . '/package', ['product_id' => (string) $medium, 'accept' => '1']);
        $change = $this->change($service->id);
        self::assertSame(['upgrade', 'pending_payment', 500, 2000], [$change['direction'], $change['status'], (int) $change['charge'], (int) $change['new_recurring']]);
        $this->assertRedirect($response, '/invoices/' . $change['invoice_id']);
        $invoice = (array) $this->db->fetchOne('SELECT * FROM `nx_invoices` WHERE `id` = ?', [$change['invoice_id']]);
        self::assertSame(['upgrade', 500, 95, 595], [$invoice['type'], (int) $invoice['subtotal'], (int) $invoice['tax'], (int) $invoice['total']]);
        self::assertStringContainsString('LXC S → LXC M', (string) $this->db->fetchValue('SELECT `description` FROM `nx_invoice_items` WHERE `invoice_id` = ?', [$invoice['id']]));

        // One change at a time; other packages cannot be requested meanwhile.
        $this->post('/services/' . $service->id . '/package', ['product_id' => (string) $tiny, 'accept' => '1']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_service_changes`'));

        // Not paid: nothing changes. Paid: the queue changes the container.
        $this->runChangeJobs();
        self::assertSame('1', $this->guest['cores']);
        self::assertTrue($this->app->container()->get(PaymentService::class)->recordPayment('manual', 'bank-up', (int) $invoice['id'], 595, 'EUR')->isSuccessful());
        $this->runChangeJobs();
        self::assertSame(['2', '2048', '1024'], [$this->guest['cores'], $this->guest['memory'], $this->guest['swap']]);
        self::assertStringContainsString('size=20G', $this->guest['rootfs']);
        self::assertSame('name=eth0,bridge=vmbr0,hwaddr=BC:24:11:00:00:01,ip=dhcp', $this->guest['net0'], 'The network line (MAC address) is not touched.');

        $updated = $this->app->container()->get(ServiceRepository::class)->find($service->id);
        self::assertNotNull($updated);
        self::assertSame([$medium, 2000, 2, 20, 7, 'web01'], [$updated->productId, $updated->recurringAmount, (int) $updated->config('cores'), (int) $updated->config('disk_gb'), (int) $updated->config('ip_pool_id'), $updated->option('hostname')]);
        self::assertSame('completed', $this->change($service->id)['status']);
        self::assertSame($customer->email, $this->lastMail('service_package_changed')['to'] ?? null);

        // The next renewal uses the new price.
        $this->clock()->freeze(new DateTimeImmutable('2026-07-10 00:00:00'));
        $renewal = $this->app->container()->get(InvoiceService::class)->createRenewal($updated);
        self::assertSame(2000, (int) $this->db->fetchValue('SELECT `subtotal` FROM `nx_invoices` WHERE `id` = ?', [$renewal]));
    }

    public function testRenewalPaidWhileTheUpgradeWaitsIsInvoicedAfterwards(): void
    {
        $small = $this->product('LXC S', 1000, ['cores' => 1, 'memory_mb' => 1024, 'swap_mb' => 512, 'disk_gb' => 10]);
        $medium = $this->product('LXC M', 2000, ['cores' => 2, 'memory_mb' => 2048, 'swap_mb' => 512, 'disk_gb' => 10]);
        $customer = $this->createUser();
        $service = $this->service($customer, $small, 1000);
        $result = $this->app->container()->get(ServiceChangeService::class)->request($service, $customer, $medium, $customer->id, null);
        $payments = $this->app->container()->get(PaymentService::class);

        // The renewal (old price) is created and paid before the upgrade invoice.
        $this->clock()->freeze(new DateTimeImmutable('2026-07-10 00:00:00'));
        $renewal = (int) $this->app->container()->get(InvoiceService::class)->createRenewal($this->app->container()->get(ServiceRepository::class)->find($service->id) ?? $service);
        $payments->recordPayment('manual', 'bank-renewal', $renewal, (int) $this->db->fetchValue('SELECT `total` FROM `nx_invoices` WHERE `id` = ?', [$renewal]), 'EUR');
        $payments->recordPayment('manual', 'bank-upgrade', (int) $result['invoice_id'], (int) $this->db->fetchValue('SELECT `total` FROM `nx_invoices` WHERE `id` = ?', [$result['invoice_id']]), 'EUR');
        $this->runChangeJobs();

        // The extra month (16 July – 16 August) is invoiced with the difference of 10.00 €.
        $followUp = (array) $this->db->fetchOne("SELECT * FROM `nx_invoices` WHERE `service_id` = ? AND `type` = 'upgrade' AND `id` <> ? ORDER BY `id` DESC LIMIT 1", [$service->id, $result['invoice_id']]);
        self::assertSame([1000, 'unpaid'], [(int) $followUp['subtotal'], $followUp['status']]);
        self::assertStringContainsString('already paid', (string) $this->db->fetchValue('SELECT `description` FROM `nx_invoice_items` WHERE `invoice_id` = ?', [$followUp['id']]));
        $payments->recordPayment('manual', 'bank-followup', (int) $followUp['id'], (int) $followUp['total'], 'EUR');
        $this->runChangeJobs();
        self::assertSame('completed', $this->change($service->id)['status']);
    }

    public function testDowngradeTakesEffectAtTheEndOfThePaidPeriod(): void
    {
        $large = $this->product('LXC L', 2000, ['cores' => 4, 'memory_mb' => 4096, 'swap_mb' => 512, 'disk_gb' => 10]);
        $small = $this->product('LXC S', 1000, ['cores' => 1, 'memory_mb' => 1024, 'swap_mb' => 512, 'disk_gb' => 10], 'lxc-fsn', 1);
        $customer = $this->createUser();
        $service = $this->service($customer, $large, 2000);
        $changes = $this->app->container()->get(ServiceChangeService::class);

        $result = $changes->request($service, $customer, $small, $customer->id, '127.0.0.1');
        self::assertSame(['scheduled', null], [$result['status'], $result['invoice_id']]);
        self::assertSame(['downgrade', '2026-07-16 00:00:00', 0], [$this->change($service->id)['direction'], $this->change($service->id)['effective_at'], (int) $this->db->fetchValue('SELECT `stock` FROM `nx_products` WHERE `id` = ?', [$small])]);
        self::assertSame(1000, (int) $this->db->fetchValue('SELECT `recurring_amount` FROM `nx_services` WHERE `id` = ?', [$service->id]), 'The next renewal costs the new price.');

        // Withdrawn: price and stock as before; scheduled again.
        $changes->withdraw($service, $customer->id, null);
        self::assertSame([2000, 1], [(int) $this->db->fetchValue('SELECT `recurring_amount` FROM `nx_services` WHERE `id` = ?', [$service->id]), (int) $this->db->fetchValue('SELECT `stock` FROM `nx_products` WHERE `id` = ?', [$small])]);
        $changes->request($service, $customer, $small, $customer->id, null);

        // Once the renewal at the new price is issued, the downgrade can no longer be withdrawn.
        $this->clock()->freeze(new DateTimeImmutable('2026-07-10 00:00:00'));
        $fresh = $this->app->container()->get(ServiceRepository::class)->find($service->id);
        self::assertNotNull($fresh);
        $renewal = $this->app->container()->get(InvoiceService::class)->createRenewal($fresh);
        self::assertSame(1000, (int) $this->db->fetchValue('SELECT `subtotal` FROM `nx_invoices` WHERE `id` = ?', [$renewal]));
        try {
            $changes->withdraw($fresh, $customer->id, null);
            self::fail('The downgrade was withdrawn after the renewal.');
        } catch (\Nebrix\Service\ServiceChangeException $exception) {
            self::assertStringContainsString('already been invoiced', $exception->getMessage());
        }

        self::assertSame(['started' => 0, 'expired' => 0], $changes->process());
        $this->clock()->freeze(new DateTimeImmutable('2026-07-16 00:10:00'));
        self::assertSame(1, $changes->process()['started']);
        $this->runChangeJobs();
        self::assertSame(['1', '1024'], [$this->guest['cores'], $this->guest['memory']]);
        self::assertCount(0, $this->http->requests('PUT', '/api2/json/nodes/pve1/lxc/20001/resize'), 'Same disk size: no resize.');
        self::assertSame($small, (int) $this->db->fetchValue('SELECT `product_id` FROM `nx_services` WHERE `id` = ?', [$service->id]));
    }

    public function testRulesPermissionsExpiryAndApi(): void
    {
        $small = $this->product('LXC S', 1000, ['cores' => 1, 'memory_mb' => 1024, 'swap_mb' => 512, 'disk_gb' => 10]);
        $medium = $this->product('LXC M', 2000, ['cores' => 2, 'memory_mb' => 2048, 'swap_mb' => 512, 'disk_gb' => 20], 'lxc-fsn', 5);
        $customer = $this->createUser();
        $service = $this->service($customer, $small, 1000);
        $changes = $this->app->container()->get(ServiceChangeService::class);

        // Another customer cannot see or change the service.
        $this->actingAs($this->createUser());
        self::assertSame(404, $this->get('/services/' . $service->id . '/package')->status());
        self::assertSame(404, $this->post('/services/' . $service->id . '/package', ['product_id' => (string) $medium, 'accept' => '1'])->status());

        // Customer groups without "manage services" may only look.
        $groupId = $this->db->insert('nx_customer_groups', ['name' => 'Read only', 'discount_bp' => 0, 'can_manage_services' => 0, 'created_at' => $this->clock()->nowString(), 'updated_at' => $this->clock()->nowString()]);
        $this->db->update('nx_users', ['customer_group_id' => $groupId], ['id' => $customer->id]);
        $limited = $this->app->container()->get(\Nebrix\Repository\UserRepository::class)->find($customer->id);
        self::assertNotNull($limited);
        $this->actingAs($limited);
        self::assertSame(403, $this->post('/services/' . $service->id . '/package', ['product_id' => (string) $medium, 'accept' => '1'])->status());
        $this->db->update('nx_users', ['customer_group_id' => null], ['id' => $customer->id]);

        // An open renewal or a cancellation blocks changes.
        $this->db->update('nx_services', ['cancel_at' => '2026-07-16 00:00:00'], ['id' => $service->id]);
        $this->assertBlocked($changes, $service->id, $customer, $medium, 'cancelled');
        $this->db->update('nx_services', ['cancel_at' => null], ['id' => $service->id]);

        // API: options and an upgrade; the unpaid invoice is cancelled after 14 days.
        $this->app->container()->get(SettingsRepository::class)->set('api.enabled', true);
        $token = $this->app->container()->get(ApiTokenService::class)->create($customer, 'Shop', [ApiScope::SERVICES_READ, ApiScope::SERVICES_MANAGE], 30, [], null)['secret'];
        $api = fn (string $method, string $path, array $body = []) => $this->app->handle(Request::create($method, '/api/v1' . $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(8))], $body !== [] ? (string) json_encode($body) : ''));
        $options = json_decode($api('GET', '/services/' . $service->id . '/package')->body(), true)['data'];
        self::assertSame([[$medium, 'upgrade', 500, true]], array_map(static fn (array $p): array => [$p['product_id'], $p['direction'], $p['charge_net'], $p['available']], $options['packages']));
        $created = $api('POST', '/services/' . $service->id . '/package', ['product_id' => $medium]);
        self::assertSame(201, $created->status());
        $invoiceId = json_decode($created->body(), true)['data']['invoice_id'];
        self::assertSame(4, (int) $this->db->fetchValue('SELECT `stock` FROM `nx_products` WHERE `id` = ?', [$medium]), 'One unit is reserved.');
        self::assertSame(422, $api('POST', '/services/' . $service->id . '/package', ['product_id' => $medium])->status());

        $this->clock()->freeze(new DateTimeImmutable('2026-07-15 01:00:00'));
        self::assertSame(1, $changes->process()['expired']);
        self::assertSame(['cancelled', 'cancelled'], [(string) $this->db->fetchValue('SELECT `status` FROM `nx_invoices` WHERE `id` = ?', [$invoiceId]), $this->change($service->id)['status']]);
        self::assertSame(5, (int) $this->db->fetchValue('SELECT `stock` FROM `nx_products` WHERE `id` = ?', [$medium]), 'The reserved unit is released.');

        // Staff: support may not change packages; admins may change at once without invoice.
        $this->actingAs($this->createUser(Role::SUPPORT));
        self::assertSame(403, $this->post('/admin/services/' . $service->id . '/package', ['product_id' => (string) $medium, 'mode' => 'free'])->status());
        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/services/' . $service->id . '/package', ['product_id' => (string) $medium, 'mode' => 'free']);
        self::assertSame(['queued', 0, null], [$this->change($service->id)['status'], (int) $this->change($service->id)['charge'], $this->change($service->id)['invoice_id']]);
        $this->runChangeJobs();
        self::assertSame('2', $this->guest['cores']);
        self::assertStringContainsString('LXC S → LXC M', $this->get('/admin/services/' . $service->id)->body());
    }

    private function assertBlocked(ServiceChangeService $changes, int $serviceId, User $customer, int $productId, string $message): void
    {
        $service = $this->app->container()->get(ServiceRepository::class)->find($serviceId);
        self::assertNotNull($service);
        try {
            $changes->request($service, $customer, $productId, $customer->id, null);
            self::fail('The change was accepted.');
        } catch (\Nebrix\Service\ServiceChangeException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}
