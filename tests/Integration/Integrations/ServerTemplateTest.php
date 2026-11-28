<?php

declare(strict_types=1);

namespace Nebrix\Tests\Integration\Integrations;

use Nebrix\Auth\User;
use Nebrix\Billing\OrderException;
use Nebrix\Billing\OrderService;
use Nebrix\Provisioning\ProvisionResult;
use Nebrix\Provisioning\ProxmoxProvisioner;
use Nebrix\Provisioning\RetryLaterException;
use Nebrix\Provisioning\ServiceActionRunner;
use Nebrix\Provisioning\ServiceStateException;
use Nebrix\Repository\ProductRepository;
use Nebrix\Repository\UserRepository;
use Nebrix\Security\ValidationException;
use Nebrix\Server\SshKeys\SshKeyRepository;
use Nebrix\Server\SshKeys\SshPublicKey;
use Nebrix\Server\Templates\ServerTemplateRepository;
use Nebrix\Service\Service;
use Nebrix\Tests\Support\FakeHttp;
use Nebrix\Tests\Support\FakeProxmox;
use Nebrix\Tests\Support\SshKeyFixtures as Keys;

/**
 * Server templates and SSH keys for Proxmox orders and reinstalls (fake API).
 */
final class ServerTemplateTest extends IntegrationTestCase
{
    private FakeProxmox $pve;

    private int $integrationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->integrationId = $this->createIntegration('proxmox', ['base_url' => 'https://203.0.113.10:8006', 'token_id' => 'nebrix@pve!panel', 'verify_tls' => true], ['token_secret' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0']);
        $this->pve = new FakeProxmox();
        $this->pve->register($this->http);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function product(string $category, array $config): int
    {
        return $this->app->container()->get(ProductRepository::class)->create([
            'category' => $category, 'name' => strtoupper($category) . ' ' . bin2hex(random_bytes(2)), 'slug' => $category . '-' . bin2hex(random_bytes(3)), 'price' => 500, 'setup_fee' => 0,
            'currency' => 'EUR', 'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 1,
            'integration_id' => $this->integrationId, 'provisioning_config' => $config,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<int> $products
     */
    private function template(array $data, array $products): int
    {
        return $this->app->container()->get(ServerTemplateRepository::class)->save(null, $data + [
            'integration_id' => $this->integrationId, 'description' => null, 'icon' => 'server', 'cloud_init_user' => null, 'ssh_keys' => 1, 'sort_order' => 0, 'is_active' => 1,
        ], $products);
    }

    private function key(User $user, string $line): int
    {
        return $this->app->container()->get(SshKeyRepository::class)->add($user->id, 'key', SshPublicKey::parse($line));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function service(User $owner, int $productId, string $category, array $options): Service
    {
        $product = $this->app->container()->get(ProductRepository::class)->find($productId);
        self::assertNotNull($product);
        $service = $this->createService($owner, $category, $this->integrationId, $product['provisioning_config'] + ['_options' => $options + ['hostname' => 'web1']]);
        $this->db->update('nx_services', ['product_id' => $productId], ['id' => $service->id]);

        return $this->reload($service->id);
    }

    private function provisionToEnd(Service $service): ProvisionResult
    {
        $owner = $this->app->container()->get(UserRepository::class)->find($service->userId);
        self::assertNotNull($owner);
        for ($i = 0; $i < 12; $i++) {
            try {
                return $this->app->container()->get(ProxmoxProvisioner::class)->provision($this->reload($service->id), $owner);
            } catch (RetryLaterException) {
                $this->pve->finishTasks();
            }
        }
        self::fail('Provisioning did not finish.');
    }

    private function vpsConfig(): array
    {
        return ['template_vmid' => 9000, 'storage' => 'local-lvm', 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 20, 'bridge' => 'vmbr0', 'rate_mbps' => 0, 'vmid_start' => 10000, 'node' => 'pve1'];
    }

    private function lxcConfig(): array
    {
        return ['ostemplate' => 'local:vztmpl/debian-12.tar.zst', 'storage' => 'local-lvm', 'cores' => 1, 'memory_mb' => 512, 'swap_mb' => 0, 'disk_gb' => 8, 'bridge' => 'vmbr0', 'rate_mbps' => 0, 'unprivileged' => true, 'vmid_start' => 20000, 'node' => 'pve1'];
    }

    public function testKvmServerIsClonedFromTheChosenTemplateWithTheOwnersKeys(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $first = $this->key($owner, Keys::ED25519);
        $second = $this->key($owner, Keys::RSA2048);
        $foreign = $this->key($other, Keys::ED25519_B);
        $productId = $this->product('vps', $this->vpsConfig());
        $templateId = $this->template(['name' => 'Debian 12 with Docker', 'type' => 'kvm', 'source' => '9100', 'cloud_init_user' => 'debian'], [$productId]);

        // The foreign key ID in the options (e.g. a manipulated order) is never installed.
        $service = $this->service($owner, $productId, 'vps', ['os_template' => $templateId, 'ssh_keys' => [$first, $second, $foreign]]);
        $result = $this->provisionToEnd($service);

        self::assertCount(1, $this->http->requests('POST', '/api2/json/nodes/pve1/qemu/9100/clone'), 'Cloned from the template, not the product template 9000.');
        self::assertSame([], $this->http->requests('POST', '/api2/json/nodes/pve1/qemu/9000/clone'));
        $configure = $this->http->requests('POST', '/api2/json/nodes/pve1/qemu/' . (10000 + $service->id) . '/config')[0];
        $form = FakeHttp::form($configure);
        self::assertSame('debian', $form['ciuser']);
        self::assertSame(rawurlencode(Keys::ED25519 . "\n" . Keys::RSA2048), $form['sshkeys'], 'URL-encoded keys of the owner only.');
        self::assertStringContainsString('sshkeys=ssh-ed25519%2520AAAA', (string) $configure->getBody(), 'Encoded twice on the wire: once for Proxmox, once by the form encoding.');
        self::assertStringNotContainsString('AAAAC3NzaC1lZDI1NTE5AAAAIAMQ', (string) $configure->getBody());
        self::assertSame('debian', $result->credentials['username']);
        self::assertSame($form['cipassword'], $result->credentials['password']);

        $remote = $this->reload($service->id)->remote;
        self::assertSame($templateId, $remote['template_id']);
        self::assertSame('9100', $remote['template']);
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        self::assertSame('Debian 12 with Docker', $this->app->container()->get(ProxmoxProvisioner::class)->status($this->reload($service->id))['connection']['Operating system']);
        self::assertNotNull($this->db->fetchValue('SELECT last_used_at FROM nx_ssh_keys WHERE id = ?', [$first]));
        self::assertNull($this->db->fetchValue('SELECT last_used_at FROM nx_ssh_keys WHERE id = ?', [$foreign]));
    }

    public function testLxcContainerGetsTheTemplateAndPublicKeys(): void
    {
        $owner = $this->createUser();
        $key = $this->key($owner, Keys::ECDSA256);
        $productId = $this->product('lxc', $this->lxcConfig());
        $templateId = $this->template(['name' => 'Ubuntu 24.04', 'type' => 'lxc', 'source' => 'local:vztmpl/ubuntu-24.04.tar.zst'], [$productId]);

        $service = $this->service($owner, $productId, 'lxc', ['os_template' => $templateId, 'ssh_keys' => [$key]]);
        $result = $this->provisionToEnd($service);

        $form = FakeHttp::form($this->http->requests('POST', '/api2/json/nodes/pve1/lxc')[0]);
        self::assertSame('local:vztmpl/ubuntu-24.04.tar.zst', $form['ostemplate']);
        self::assertSame(Keys::ECDSA256, $form['ssh-public-keys'], 'Plain authorized_keys line for containers.');
        self::assertSame('root', $result->credentials['username']);
    }

    public function testTemplatesThatAreNotOfferedOrWithoutKeySupportAreHandledSafely(): void
    {
        $owner = $this->createUser();
        $key = $this->key($owner, Keys::ED25519);
        $productId = $this->product('lxc', $this->lxcConfig());
        $otherProduct = $this->product('lxc', $this->lxcConfig());
        $offeredElsewhere = $this->template(['name' => 'Other', 'type' => 'lxc', 'source' => 'local:vztmpl/alpine.tar.zst'], [$otherProduct]);
        $noKeys = $this->template(['name' => 'Appliance', 'type' => 'lxc', 'source' => 'local:vztmpl/appliance.tar.zst', 'ssh_keys' => 0], [$productId]);

        // A template of another product: the product's own template is used.
        $first = $this->service($owner, $productId, 'lxc', ['os_template' => $offeredElsewhere]);
        $this->provisionToEnd($first);
        self::assertSame('local:vztmpl/debian-12.tar.zst', FakeHttp::form($this->http->requests('POST', '/api2/json/nodes/pve1/lxc')[0])['ostemplate']);
        self::assertArrayNotHasKey('template_id', $this->reload($first->id)->remote);

        // A template without SSH key support: no keys are sent.
        $second = $this->service($owner, $productId, 'lxc', ['os_template' => $noKeys, 'ssh_keys' => [$key]]);
        $this->provisionToEnd($second);
        $form = FakeHttp::form($this->http->requests('POST', '/api2/json/nodes/pve1/lxc')[1]);
        self::assertSame('local:vztmpl/appliance.tar.zst', $form['ostemplate']);
        self::assertArrayNotHasKey('ssh-public-keys', $form);
    }

    public function testReinstallOffersTemplatesAndOnlyTheOwnersKeys(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $key = $this->key($owner, Keys::ED25519);
        $foreign = $this->key($other, Keys::ED25519_B);
        $productId = $this->product('lxc', $this->lxcConfig() + ['reinstall_templates' => 'local:vztmpl/rocky-9.tar.zst|Rocky Linux 9']);
        $templateId = $this->template(['name' => 'Ubuntu 24.04', 'type' => 'lxc', 'source' => 'local:vztmpl/ubuntu-24.04.tar.zst'], [$productId]);
        $inactive = $this->template(['name' => 'Old', 'type' => 'lxc', 'source' => 'local:vztmpl/old.tar.zst', 'is_active' => 0], [$productId]);
        $service = $this->service($owner, $productId, 'lxc', []);
        $this->provisionToEnd($service);
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        $runner = $this->app->container()->get(ServiceActionRunner::class);

        $fields = $runner->available($this->reload($service->id))['reinstall']->fields;
        self::assertSame([
            'local:vztmpl/debian-12.tar.zst' => 'Default image',
            'local:vztmpl/rocky-9.tar.zst' => 'Rocky Linux 9',
            't:' . $templateId => 'Ubuntu 24.04',
        ], $fields['template']['options'], 'Existing reinstall list kept, inactive templates hidden.');
        self::assertArrayNotHasKey('t:' . $inactive, $fields['template']['options']);
        self::assertSame([$key], array_keys($fields['ssh_keys']['options']), 'Only the owner\'s keys are offered.');

        // A key of another customer is refused (not offered).
        try {
            $runner->run($this->reload($service->id), 'reinstall', ['template' => 't:' . $templateId, 'ssh_keys' => [(string) $foreign]], null, null);
            self::fail('A foreign key was accepted.');
        } catch (ValidationException) {
        }
        self::assertSame('active', $this->reload($service->id)->status);

        // While a snapshot task runs the reinstall is refused and the service stays active.
        $taskId = $this->db->insert('nx_server_snapshot_tasks', ['service_id' => $service->id, 'action' => 'create', 'snapshot' => 'before', 'status' => 'running', 'created_at' => '2026-11-28 10:00:00', 'updated_at' => '2026-11-28 10:00:00']);
        try {
            $runner->run($this->reload($service->id), 'reinstall', ['template' => 't:' . $templateId], null, null);
            self::fail('Reinstall started during a snapshot task.');
        } catch (ServiceStateException $exception) {
            self::assertStringContainsString('snapshot task is still running', $exception->getMessage());
        }
        self::assertSame('active', $this->reload($service->id)->status);
        $this->db->update('nx_server_snapshot_tasks', ['status' => 'done'], ['id' => $taskId]);

        $runner->run($this->reload($service->id), 'reinstall', ['template' => 't:' . $templateId, 'ssh_keys' => [(string) $key]], $owner->id, '198.51.100.7');
        self::assertSame('provisioning', $this->reload($service->id)->status);
        $this->provisionToEnd($service);
        $creates = $this->http->requests('POST', '/api2/json/nodes/pve1/lxc');
        self::assertCount(2, $creates);
        $form = FakeHttp::form($creates[1]);
        self::assertSame('local:vztmpl/ubuntu-24.04.tar.zst', $form['ostemplate']);
        self::assertSame(Keys::ED25519, $form['ssh-public-keys']);
        self::assertSame($templateId, $this->reload($service->id)->remote['template_id']);
        $audit = json_decode((string) $this->db->fetchValue("SELECT details FROM nx_audit_log WHERE action = 'service.reinstall_requested' AND target_id = ? ORDER BY id DESC LIMIT 1", [$service->id]), true);
        self::assertSame((string) $key, $audit['ssh_keys'] ?? null, 'Key list in the audit log (no array-to-string error).');

        // Back to the legacy default: the template ID is forgotten.
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        $runner->run($this->reload($service->id), 'reinstall', ['template' => 'local:vztmpl/rocky-9.tar.zst'], null, null);
        $this->provisionToEnd($service);
        self::assertArrayNotHasKey('template_id', $this->reload($service->id)->remote);
        self::assertArrayNotHasKey('ssh-public-keys', FakeHttp::form($this->http->requests('POST', '/api2/json/nodes/pve1/lxc')[2]));
    }

    public function testOrderCheckAcceptsOnlyOfferedTemplatesAndOwnKeys(): void
    {
        $customer = $this->createUser();
        $other = $this->createUser();
        $own = $this->key($customer, Keys::ED25519);
        $foreign = $this->key($other, Keys::ED25519_B);
        $productId = $this->product('vps', $this->vpsConfig());
        $offered = $this->template(['name' => 'Debian', 'type' => 'kvm', 'source' => '9100'], [$productId]);
        $noKeys = $this->template(['name' => 'Windows', 'type' => 'kvm', 'source' => '9200', 'ssh_keys' => 0], [$productId]);
        $notOffered = $this->template(['name' => 'Elsewhere', 'type' => 'kvm', 'source' => '9300'], []);
        $orders = $this->app->container()->get(OrderService::class);

        $cases = [
            'template of no product' => [['hostname' => 'web1', 'os_template' => (string) $notOffered], 'Choose one of the offered operating systems.'],
            'foreign key' => [['hostname' => 'web1', 'os_template' => (string) $offered, 'ssh_keys' => [(string) $own, (string) $foreign]], 'Choose one of your SSH keys.'],
            'keys without support' => [['hostname' => 'web1', 'os_template' => (string) $noKeys, 'ssh_keys' => [(string) $own]], 'does not support SSH keys'],
        ];
        foreach ($cases as $name => [$options, $message]) {
            try {
                $orders->place($customer, $productId, $options, '198.51.100.7');
                self::fail('Order accepted: ' . $name);
            } catch (OrderException $exception) {
                self::assertStringContainsString($message, $exception->getMessage(), $name);
            }
        }

        $result = $orders->place($customer, $productId, ['hostname' => 'web1', 'os_template' => (string) $offered, 'ssh_keys' => [(string) $own]], '198.51.100.7');
        $options = json_decode((string) $this->db->fetchValue('SELECT options FROM nx_order_items WHERE order_id = ?', [$result['order_id']]), true);
        self::assertSame(['hostname' => 'web1', 'os_template' => $offered, 'ssh_keys' => [$own]], $options);

        // No choice: nothing extra is stored.
        $plain = $orders->place($customer, $productId, ['hostname' => 'web2'], '198.51.100.7');
        self::assertSame(['hostname' => 'web2'], json_decode((string) $this->db->fetchValue('SELECT options FROM nx_order_items WHERE order_id = ?', [$plain['order_id']]), true));
    }
}
