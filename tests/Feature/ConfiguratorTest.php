<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use Nebrix\Auth\Role;
use Nebrix\Billing\OrderService;
use Nebrix\Catalog\ProductConfigurator;
use Nebrix\Payment\PaymentService;
use Nebrix\Repository\ProductRepository;
use Nebrix\Security\ValidationException;
use Nebrix\Tests\FeatureTestCase;

/**
 * Server configurator: customers choose cores, memory and disk of KVM/LXC
 * products; the price is always calculated on the server.
 */
final class ConfiguratorTest extends FeatureTestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function product(array $config = [], string $category = 'lxc'): int
    {
        return $this->app->container()->get(ProductRepository::class)->create([
            'category' => $category, 'name' => 'Flex ' . strtoupper($category), 'slug' => 'flex-' . $category . '-' . random_int(1, 99999), 'price' => 300, 'setup_fee' => 0, 'currency' => 'EUR',
            'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0,
            'provisioning_config' => $config + [
                'ostemplate' => 'local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst', 'storage' => 'local-lvm', 'bridge' => 'vmbr0',
                'cores' => 1, 'memory_mb' => 1024, 'swap_mb' => 512, 'disk_gb' => 10,
                'configurator' => true, 'cfg_max_cores' => 8, 'cfg_price_core' => '2.00', 'cfg_max_memory_gb' => 16, 'cfg_price_memory_gb' => '1.50',
                'cfg_max_disk_gb' => 200, 'cfg_disk_step_gb' => 10, 'cfg_price_disk_step' => '0.50',
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id): array
    {
        return $this->app->container()->get(ProductRepository::class)->find($id) ?? throw new \RuntimeException('missing');
    }

    public function testRangesPriceAndValidation(): void
    {
        $product = $this->find($this->product());
        self::assertSame([
            'cores' => ['min' => 1, 'max' => 8, 'step' => 1, 'price' => 200],
            'memory_gb' => ['min' => 1, 'max' => 16, 'step' => 1, 'price' => 150],
            'disk_gb' => ['min' => 10, 'max' => 200, 'step' => 10, 'price' => 50],
        ], ProductConfigurator::ranges($product));

        // 3.00 + 3 × 2.00 + 7 × 1.50 + 4 × 0.50 = 21.50
        self::assertSame(['cores' => 4, 'memory_gb' => 8, 'disk_gb' => 50, 'price' => 2150], ProductConfigurator::resolve($product, ['cores' => '4', 'memory_gb' => '8', 'disk_gb' => '50']));
        self::assertSame(['cores' => 1, 'memory_gb' => 1, 'disk_gb' => 10, 'price' => 300], ProductConfigurator::resolve($product, []), 'Nothing chosen = the included minimum.');

        foreach ([['cores' => '9'], ['cores' => '0'], ['disk_gb' => '55'], ['memory_gb' => '2.5'], ['cores' => 'abc'], ['cores' => ['4']], ['disk_gb' => '-10']] as $invalid) {
            try {
                ProductConfigurator::resolve($product, $invalid);
                self::fail('Accepted ' . json_encode($invalid));
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        // Previews clamp instead.
        self::assertSame(['cores' => 8, 'memory_gb' => 1, 'disk_gb' => 50, 'price' => 300 + 7 * 200 + 4 * 50], ProductConfigurator::resolve($product, ['cores' => '99', 'memory_gb' => 'x', 'disk_gb' => '55'], false));

        // Not configurable: off, other categories, inconsistent settings.
        self::assertNull(ProductConfigurator::ranges($this->find($this->product(['configurator' => false]))));
        self::assertNull(ProductConfigurator::ranges(['category' => 'teamspeak', 'currency' => 'EUR', 'provisioning_config' => ['configurator' => true]]));
        self::assertNull(ProductConfigurator::ranges($this->find($this->product(['memory_mb' => 1536]))));
        self::assertNotEmpty(ProductConfigurator::configErrors(['configurator' => true, 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 20]), 'No resource can be changed.');
        self::assertArrayHasKey('cfg_max_disk_gb', ProductConfigurator::configErrors(['configurator' => true, 'cores' => 1, 'memory_mb' => 1024, 'disk_gb' => 10, 'cfg_max_disk_gb' => 25, 'cfg_disk_step_gb' => 10, 'cfg_price_disk_step' => '1']));
        self::assertArrayHasKey('cfg_price_core', ProductConfigurator::configErrors(['configurator' => true, 'cores' => 1, 'memory_mb' => 1024, 'disk_gb' => 10, 'cfg_max_cores' => 4]));
    }

    public function testCustomerOrdersAConfigurationAndTheServiceGetsIt(): void
    {
        $id = $this->product();
        $slug = (string) $this->find($id)['slug'];
        $customer = $this->createUser();
        $this->actingAs($customer);

        $page = $this->get('/store/' . $slug, ['cores' => '4', 'memory_gb' => '8', 'disk_gb' => '50'])->body();
        self::assertStringContainsString('€21.50', $page, 'Server-side price of the configuration.');
        self::assertStringContainsString('€25.59', $page, '21.50 + 19 % VAT');
        self::assertStringContainsString('type="range" name="cores" min="1" max="8" step="1" value="4"', $page);
        self::assertStringContainsString('<input type="hidden" name="disk_gb" value="50">', $page);
        self::assertStringContainsString('name="cores" min="1" max="8" step="1" value="8"', $this->get('/store/' . $slug, ['cores' => '999'])->body(), 'Out-of-range values are clamped for the preview.');

        // A manipulated value is refused when ordering; nothing is stored.
        $this->post('/store/' . $slug, ['hostname' => 'flex1', 'cores' => '9', 'memory_gb' => '8', 'disk_gb' => '50', 'accept_terms' => '1']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_orders WHERE user_id = ?', [$customer->id]));

        $response = $this->post('/store/' . $slug, ['hostname' => 'flex1', 'cores' => '4', 'memory_gb' => '8', 'disk_gb' => '50', 'accept_terms' => '1']);
        self::assertTrue($response->isRedirect());
        $item = $this->db->fetchOne('SELECT oi.* FROM nx_order_items oi JOIN nx_orders o ON o.id = oi.order_id WHERE o.user_id = ?', [$customer->id]);
        self::assertNotNull($item);
        self::assertSame(2150, (int) $item['unit_price']);
        self::assertStringContainsString('4 vCores, 8 GB RAM, 50 GB disk', (string) $item['description']);
        self::assertSame(['hostname' => 'flex1', 'cores' => 4, 'memory_gb' => 8, 'disk_gb' => 50], json_decode((string) $item['options'], true));
        $invoice = $this->db->fetchOne('SELECT id, total FROM nx_invoices WHERE order_id = ?', [(int) $item['order_id']]);
        self::assertNotNull($invoice);
        self::assertSame(2150 + 409, (int) $invoice['total']);

        // Paid: the service is created with the ordered resources and renews at the configured price.
        self::assertTrue($this->app->container()->get(PaymentService::class)->recordPayment('manual', 'bank-cfg', (int) $invoice['id'], (int) $invoice['total'], 'EUR')->isSuccessful());
        $this->app->container()->get(\Nebrix\Provisioning\ProvisioningService::class)->fulfilOrder((int) $item['order_id']);
        $service = $this->db->fetchOne('SELECT config, recurring_amount FROM nx_services WHERE user_id = ?', [$customer->id]);
        self::assertNotNull($service);
        $config = json_decode((string) $service['config'], true);
        self::assertSame(4, $config['cores']);
        self::assertSame(8192, $config['memory_mb']);
        self::assertSame(50, $config['disk_gb']);
        self::assertSame(512, $config['swap_mb'], 'Other values come from the product.');
        self::assertSame(2150, (int) $service['recurring_amount']);

        // A package between the configurator's minimum (3.00) and the configured price (21.50)
        // is a downgrade: only the price of the configured server counts.
        $package = $this->app->container()->get(ProductRepository::class)->create([
            'category' => 'lxc', 'name' => 'Package M', 'slug' => 'package-m-' . random_int(1, 99999), 'price' => 1299, 'setup_fee' => 0, 'currency' => 'EUR', 'billing_cycle' => 'monthly',
            'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => ['cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50],
        ]);
        $serviceId = (int) $this->db->fetchValue('SELECT id FROM nx_services WHERE user_id = ?', [$customer->id]);
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $serviceId]);
        $changes = $this->app->container()->get(\Nebrix\Service\ServiceChangeService::class);
        $targets = $changes->adminTargets($this->app->container()->get(\Nebrix\Service\ServiceRepository::class)->find($serviceId) ?? throw new \RuntimeException('missing'), $customer);
        $target = array_values(array_filter($targets, static fn (array $t): bool => (int) $t['product']['id'] === $package))[0] ?? null;
        self::assertNotNull($target);
        self::assertSame(\Nebrix\Service\ServiceChangeService::DOWNGRADE, $target['direction']);
        self::assertSame(0, $target['charge']);
    }

    public function testApiExposesRangesAndOrdersWithConfiguration(): void
    {
        $id = $this->product([], 'vps');
        $customer = $this->createUser();
        $offer = $this->app->container()->get(OrderService::class)->quote($this->find($id), null, $customer, null, ['cores' => '2']);
        self::assertSame(500, $offer['price']);
        self::assertSame(['cores' => 2, 'memory_gb' => 1, 'disk_gb' => 10, 'price' => 500], $offer['configuration']);

        $result = $this->app->container()->get(OrderService::class)->place($customer, $id, ['hostname' => 'kvm1', 'cores' => '2', 'memory_gb' => '2', 'disk_gb' => '10'], '127.0.0.1');
        self::assertSame(500 + 150, (int) $this->db->fetchValue('SELECT unit_price FROM nx_order_items WHERE order_id = ?', [$result['order_id']]));

        $resource = \Nebrix\Api\Resource::product($this->find($id), $offer);
        self::assertSame(['min' => 1, 'max' => 8, 'step' => 1, 'price' => 200], $resource['configurator']['cores']);
        self::assertContains('disk_gb', array_column($resource['order_options'], 'name'));
    }

    public function testAdminFormRejectsInconsistentConfiguratorSettings(): void
    {
        $this->actingAs($this->createUser(Role::ADMIN));
        $form = [
            'category' => 'lxc', 'name' => 'Flex', 'slug' => 'flex-admin', 'price' => '3.00', 'setup_fee' => '0', 'currency' => 'EUR', 'billing_cycle' => 'monthly', 'tax_rate' => '19',
            'is_visible' => '1', 'is_active' => '1', 'auto_provision' => '0',
            'config_ostemplate' => 'local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst', 'config_storage' => 'local-lvm', 'config_cores' => '1', 'config_memory_mb' => '1536',
            'config_swap_mb' => '512', 'config_disk_gb' => '10', 'config_bridge' => 'vmbr0', 'config_rate_mbps' => '0', 'config_vmid_start' => '20000', 'config_unprivileged' => '1',
            'config_configurator' => '1', 'config_cfg_max_cores' => '8', 'config_cfg_price_core' => '2.00', 'config_cfg_disk_step_gb' => '10',
        ];
        $this->post('/admin/products', $form);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_products WHERE slug = 'flex-admin'"), '1536 MB is not whole GB.');

        $this->post('/admin/products', ['config_memory_mb' => '2048'] + $form);
        $config = json_decode((string) $this->db->fetchValue("SELECT provisioning_config FROM nx_products WHERE slug = 'flex-admin'"), true);
        self::assertTrue($config['configurator']);
        self::assertSame(8, $config['cfg_max_cores']);
    }
}
