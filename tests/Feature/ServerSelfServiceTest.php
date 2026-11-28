<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use GuzzleHttp\ClientInterface;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Core\Config;
use Nebrix\Integration\IntegrationLogger;
use Nebrix\Integration\IntegrationManager;
use Nebrix\Integration\IntegrationRepository;
use Nebrix\Repository\ProductRepository;
use Nebrix\Security\UrlGuard;
use Nebrix\Server\AdditionalIpv4Job;
use Nebrix\Server\SshKeys\SshKeyRepository;
use Nebrix\Server\SshKeys\SshPublicKey;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;
use Nebrix\Network\IpPoolService;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\FakeHttp;
use Nebrix\Tests\Support\FakeProxmox;
use Nebrix\Tests\Support\FakeRobot;
use Nebrix\Tests\Support\SshKeyFixtures as Keys;

/**
 * Server self-service pages through full HTTP requests: ownership (IDOR),
 * permissions, rate limits and the CSP-safe markup. Remote systems are fakes.
 */
final class ServerSelfServiceTest extends FeatureTestCase
{
    private FakeHttp $http;

    private FakeProxmox $pve;

    private int $proxmoxId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp();
        $this->pve = new FakeProxmox();
        $this->pve->register($this->http);
        $container = $this->app->container();
        $container->set(ClientInterface::class, fn (): ClientInterface => $this->http->client());
        $container->set(IntegrationManager::class, fn ($c): IntegrationManager => new IntegrationManager($c->get(IntegrationRepository::class), $c->get(ClientInterface::class), $c->get(UrlGuard::class), $c->get(IntegrationLogger::class), $c->get(Config::class)));
        $this->proxmoxId = $container->get(IntegrationRepository::class)->create('proxmox', 'Cluster', true, ['base_url' => 'https://203.0.113.10:8006', 'token_id' => 'nebrix@pve!panel', 'verify_tls' => true], ['token_secret' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function server(User $owner, string $category = 'lxc', array $config = ['max_snapshots' => 3]): Service
    {
        $services = $this->app->container()->get(ServiceRepository::class);
        $id = $services->create([
            'user_id' => $owner->id, 'integration_id' => $this->proxmoxId, 'category' => $category, 'name' => 'web01', 'status' => Service::STATUS_ACTIVE,
            'billing_cycle' => 'monthly', 'recurring_amount' => 500, 'tax_rate_bp' => 1900, 'currency' => 'EUR', 'next_due_at' => '2026-12-28 00:00:00',
            'config' => $config + ['node' => 'pve1', 'vmid_start' => 20000, 'bridge' => 'vmbr0', '_options' => ['hostname' => 'web01']],
        ]);
        $type = $category === 'lxc' ? 'lxc' : 'qemu';
        $services->mergeRemote($id, ['node' => 'pve1', 'vmid' => 20000 + $id, 'type' => $type, 'step' => 'done']);
        $this->pve->addGuest(20000 + $id, $type, $id);

        return $services->find($id) ?? throw new \RuntimeException('missing');
    }

    private static function assertNoInlineCode(string $html): void
    {
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html, 'No inline scripts.');
        self::assertDoesNotMatchRegularExpression('/\sstyle=|\son[a-z]+=/i', $html, 'No inline styles or event handlers.');
    }

    public function testSnapshotsPageAndRequests(): void
    {
        $owner = $this->createUser();
        $service = $this->server($owner);
        $vmid = 20000 + $service->id;
        $this->pve->snapshots[$vmid] = [['name' => 'initial', 'description' => 'Fresh install', 'snaptime' => 1764300000]];
        $this->actingAs($owner);

        $page = $this->get('/services/' . $service->id . '/snapshots');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('initial', $page->body());
        self::assertStringContainsString('Fresh install', $page->body());
        self::assertNoInlineCode($page->body());
        self::assertStringContainsString('href="/services/' . $service->id . '/snapshots"', $this->get('/services/' . $service->id)->body());

        $this->assertRedirect($this->post('/services/' . $service->id . '/snapshots', ['name' => 'before-update', 'description' => 'PHP 8.4']), '/services/' . $service->id . '/snapshots');
        $task = $this->db->fetchOne('SELECT * FROM nx_server_snapshot_tasks WHERE service_id = ?', [$service->id]);
        self::assertSame(['create', 'before-update', 'PHP 8.4', 'queued', (string) $owner->id], [$task['action'], $task['snapshot'], $task['description'], $task['status'], (string) $task['requested_by']]);
        self::assertStringContainsString('A snapshot task is still running.', $this->get('/services/' . $service->id . '/snapshots')->body());
        $this->db->update('nx_server_snapshot_tasks', ['status' => 'done'], ['id' => $task['id']]);

        // Rolling back needs the explicit confirmation.
        $this->post('/services/' . $service->id . '/snapshots/rollback', ['snapshot' => 'initial']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_server_snapshot_tasks WHERE service_id = ?', [$service->id]));
        self::assertStringContainsString('Confirm that the current state of the server will be overwritten.', $this->get('/services/' . $service->id . '/snapshots')->body());
        $this->post('/services/' . $service->id . '/snapshots/rollback', ['snapshot' => 'initial', 'confirm' => '1']);
        self::assertSame('rollback', $this->db->fetchValue('SELECT action FROM nx_server_snapshot_tasks WHERE service_id = ? ORDER BY id DESC LIMIT 1', [$service->id]));

        // Other customers get 404 and cannot queue anything.
        $this->actingAs($this->createUser());
        self::assertSame(404, $this->get('/services/' . $service->id . '/snapshots')->status());
        self::assertSame(404, $this->post('/services/' . $service->id . '/snapshots/delete', ['snapshot' => 'initial'])->status());
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_server_snapshot_tasks WHERE service_id = ?', [$service->id]));

        // Without a snapshot limit on the product the page does not exist.
        $plain = $this->server($owner, 'lxc', []);
        $this->actingAs($owner);
        self::assertSame(404, $this->get('/services/' . $plain->id . '/snapshots')->status());
    }

    public function testGraphsAreServedToTheOwnerAndStaffOnly(): void
    {
        $owner = $this->createUser();
        $service = $this->server($owner, 'vps', []);
        $this->pve->rrd[20000 + $service->id] = [['time' => 1764300000, 'cpu' => 0.1, 'mem' => 1024, 'maxmem' => 4096, 'netin' => 10, 'netout' => 20, 'diskread' => 0, 'diskwrite' => 1]];
        $this->actingAs($owner);

        $page = $this->get('/services/' . $service->id . '/graphs')->body();
        self::assertStringContainsString('data-url="/services/' . $service->id . '/graphs.json"', $page);
        self::assertMatchesRegularExpression('#<script src="/assets/js/server-graphs\.js\?v=[0-9a-f]+" defer></script>#', $page);
        self::assertNoInlineCode($page);

        $json = $this->get('/services/' . $service->id . '/graphs.json', ['timeframe' => 'day']);
        self::assertSame(200, $json->status());
        $data = json_decode($json->body(), true);
        self::assertSame('day', $data['timeframe']);
        self::assertEquals(10.0, $data['points'][0]['cpu']);
        self::assertSame(422, $this->get('/services/' . $service->id . '/graphs.json', ['timeframe' => 'decade'])->status());

        $this->actingAs($this->createUser());
        self::assertSame(404, $this->get('/services/' . $service->id . '/graphs')->status());
        self::assertSame(404, $this->get('/services/' . $service->id . '/graphs.json')->status());

        $this->actingAs($this->createUser(Role::FINANCE));
        self::assertSame(200, $this->get('/admin/services/' . $service->id . '/graphs.json', ['timeframe' => 'hour'])->status());
        self::assertStringContainsString('/admin/services/' . $service->id . '/graphs', $this->get('/admin/services/' . $service->id)->body());
        $this->actingAs($this->createUser());
        self::assertNotSame(200, $this->get('/admin/services/' . $service->id . '/graphs.json')->status());
    }

    public function testReverseDnsPageForDedicatedServers(): void
    {
        $robot = new FakeRobot();
        $robot->register($this->http);
        $robotId = $this->app->container()->get(IntegrationRepository::class)->create('hetzner_robot', 'Robot', true, [
            'base_url' => 'https://203.0.113.50', 'username' => '#ws+AbCdEf', 'pool_prefix' => 'nebrix-free', 'allow_orders' => false, 'order_test_mode' => true, 'verify_tls' => true,
        ], ['password' => 'robot-pass']);
        $owner = $this->createUser();
        $services = $this->app->container()->get(ServiceRepository::class);
        $id = $services->create(['user_id' => $owner->id, 'integration_id' => $robotId, 'category' => 'dedicated', 'name' => 'root1', 'status' => 'active', 'billing_cycle' => 'monthly', 'recurring_amount' => 5000, 'tax_rate_bp' => 1900, 'currency' => 'EUR', 'config' => ['mode' => 'pool']]);
        $services->mergeRemote($id, ['server_number' => 700, 'server_ip' => FakeRobot::ip(700), 'step' => 'done']);
        $robot->addServer(700, 'nebrix-service-' . $id);
        $this->http->on('GET', '/rdns/(?<ip>[0-9.]+)', ['error' => ['status' => 404, 'code' => 'RDNS_NOT_FOUND', 'message' => 'x']], 404);
        $this->actingAs($owner);

        $page = $this->get('/services/' . $id . '/rdns');
        self::assertSame(200, $page->status());
        self::assertStringContainsString(FakeRobot::ip(700), $page->body());
        self::assertStringContainsString('2a01:4f8:1:700::/64', $page->body(), 'The server\'s IPv6 subnet.');

        $this->assertRedirect($this->post('/services/' . $id . '/rdns', ['ip' => FakeRobot::ip(700), 'ptr' => 'root1.example.com']), '/services/' . $id . '/rdns');
        self::assertSame([FakeRobot::ip(700) => 'root1.example.com'], $robot->rdns);
        $this->post('/services/' . $id . '/rdns', ['ip' => '198.51.100.99', 'ptr' => 'evil.example.com']);
        self::assertStringContainsString('This address does not belong to your service.', $this->get('/services/' . $id . '/rdns')->body());
        self::assertArrayNotHasKey('198.51.100.99', $robot->rdns);

        $this->actingAs($this->createUser());
        self::assertSame(404, $this->get('/services/' . $id . '/rdns')->status());
        self::assertSame(404, $this->post('/services/' . $id . '/rdns', ['ip' => FakeRobot::ip(700), 'ptr' => 'hijack.example.com'])->status());
        self::assertSame('root1.example.com', $robot->rdns[FakeRobot::ip(700)]);
    }

    public function testStaffAddAndRemoveAdditionalAddresses(): void
    {
        $pools = $this->app->container()->get(IpPoolService::class);
        $poolId = $pools->createPool('Pool', '203.0.113.32/29', '203.0.113.33', null, null);
        $owner = $this->createUser();
        $service = $this->server($owner, 'lxc', ['ip_pool_id' => $poolId]);

        $this->actingAs($this->createUser(Role::FINANCE));
        self::assertSame(403, $this->post('/admin/services/' . $service->id . '/ipv4')->status(), 'services.view alone is not enough.');

        $admin = $this->createUser(Role::ADMIN);
        $this->actingAs($admin);
        $this->assertRedirect($this->post('/admin/services/' . $service->id . '/ipv4'), '/admin/services/' . $service->id);
        $job = $this->db->fetchOne("SELECT * FROM nx_jobs WHERE type = 'service.ipv4'");
        self::assertNotNull($job);
        $this->app->container()->get(AdditionalIpv4Job::class)->handle((array) json_decode((string) $job['payload'], true));
        $address = $pools->additionalAddresses($service->id)[0];
        self::assertSame('203.0.113.34', $address['address']);
        self::assertSame('name=eth1,bridge=vmbr0,ip=203.0.113.34/29', $this->pve->guests[20000 + $service->id]['config']['net1']);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'service.ipv4_add_requested' AND actor_id = ?", [$admin->id]));

        $adminPage = $this->get('/admin/services/' . $service->id)->body();
        self::assertStringContainsString('203.0.113.34/29', $adminPage);
        $this->actingAs($owner);
        self::assertStringContainsString('203.0.113.34', $this->get('/services/' . $service->id)->body(), 'Shown to the customer.');

        // Add-on addresses are removed through the add-on system, not by hand.
        $pools->allocateAdditional($poolId, $service->id, 'addon:12');
        $addonAddress = $pools->additionalAddresses($service->id)[1];
        $this->actingAs($admin);
        $this->post('/admin/services/' . $service->id . '/ipv4/' . $addonAddress['id'] . '/remove');
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_jobs WHERE type = 'service.ipv4' AND JSON_EXTRACT(payload, '$.operation') = 'detach'"));
        self::assertSame(404, $this->post('/admin/services/' . $service->id . '/ipv4/999999/remove')->status());

        $this->post('/admin/services/' . $service->id . '/ipv4/' . $address['id'] . '/remove');
        $detach = $this->db->fetchOne("SELECT * FROM nx_jobs WHERE type = 'service.ipv4' AND JSON_EXTRACT(payload, '$.operation') = 'detach'");
        self::assertNotNull($detach);
        $this->app->container()->get(AdditionalIpv4Job::class)->handle((array) json_decode((string) $detach['payload'], true));
        self::assertArrayNotHasKey('net1', $this->pve->guests[20000 + $service->id]['config']);
        self::assertSame('free', $this->db->fetchValue("SELECT status FROM nx_ip_addresses WHERE address = '203.0.113.34'"));
    }

    public function testTemplatesAreManagedByProductManagersAndOfferedOnTheOrderPage(): void
    {
        $productId = $this->app->container()->get(ProductRepository::class)->create([
            'category' => 'lxc', 'name' => 'LXC S', 'slug' => 'lxc-s', 'price' => 500, 'setup_fee' => 0, 'currency' => 'EUR', 'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900,
            'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 1, 'integration_id' => $this->proxmoxId,
            'provisioning_config' => ['ostemplate' => 'local:vztmpl/debian-12.tar.zst', 'storage' => 'local-lvm', 'vmid_start' => 20000],
        ]);

        $this->actingAs($this->createUser(Role::SUPPORT));
        self::assertSame(200, $this->get('/admin/server-templates')->status());
        self::assertSame(403, $this->get('/admin/server-templates/create')->status());
        self::assertSame(403, $this->post('/admin/server-templates', ['name' => 'X'])->status());

        $admin = $this->createUser(Role::ADMIN);
        $this->actingAs($admin);
        $form = ['integration_id' => (string) $this->proxmoxId, 'name' => 'Debian 12 with Docker', 'description' => 'Docker CE preinstalled', 'icon' => 'box', 'type' => 'lxc', 'source' => 'local:vztmpl/debian-docker.tar.zst', 'ssh_keys' => '1', 'is_active' => '1', 'products' => [(string) $productId]];
        $this->post('/admin/server-templates', ['source' => '9000'] + $form);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_server_templates'), 'A VM ID is not an LXC template.');
        $this->assertRedirect($this->post('/admin/server-templates', $form), '/admin/server-templates');
        $templateId = (int) $this->db->fetchValue('SELECT id FROM nx_server_templates');
        self::assertSame([$productId], array_map('intval', array_column($this->db->fetchAll('SELECT product_id FROM nx_server_template_products WHERE template_id = ?', [$templateId]), 'product_id')));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'server_template.created' AND actor_id = ?", [$admin->id]));
        self::assertStringContainsString('Debian 12 with Docker', $this->get('/admin/server-templates')->body());
        self::assertSame(200, $this->get('/admin/server-templates/create')->status());
        $edit = $this->get('/admin/server-templates/' . $templateId);
        self::assertSame(200, $edit->status());
        self::assertStringContainsString('value="local:vztmpl/debian-docker.tar.zst"', $edit->body());
        self::assertMatchesRegularExpression('/name="products\[\]" value="' . $productId . '" checked/', $edit->body());
        self::assertNoInlineCode($edit->body());

        // The customer sees the template and their own keys only.
        $customer = $this->createUser();
        $own = $this->app->container()->get(SshKeyRepository::class)->add($customer->id, 'Laptop', SshPublicKey::parse(Keys::ED25519));
        $this->app->container()->get(SshKeyRepository::class)->add($this->createUser()->id, 'Foreign key', SshPublicKey::parse(Keys::ED25519_B));
        $this->actingAs($customer);
        $order = $this->get('/store/lxc-s')->body();
        self::assertStringContainsString('name="os_template" value="' . $templateId . '"', $order);
        self::assertStringContainsString('Docker CE preinstalled', $order);
        self::assertStringContainsString('name="ssh_keys[]" value="' . $own . '"', $order);
        self::assertStringNotContainsString('Foreign key', $order);
        self::assertNoInlineCode($order);

        $this->post('/store/lxc-s', ['hostname' => 'docker1', 'os_template' => (string) $templateId, 'ssh_keys' => [(string) $own], 'accept_terms' => '1']);
        $options = json_decode((string) $this->db->fetchValue('SELECT i.options FROM nx_order_items i JOIN nx_orders o ON o.id = i.order_id WHERE o.user_id = ?', [$customer->id]), true);
        self::assertSame(['hostname' => 'docker1', 'os_template' => $templateId, 'ssh_keys' => [$own]], $options);

        // Deleting the template (admin).
        $this->actingAs($admin);
        $this->post('/admin/server-templates/' . $templateId . '/delete');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_server_template_products'));
    }

    public function testIpPoolReverseDnsSettingNeedsAHetznerIntegration(): void
    {
        $pools = $this->app->container()->get(IpPoolService::class);
        $poolId = $pools->createPool('Pool', '203.0.113.32/29', '203.0.113.33', null, null);
        $robotId = $this->app->container()->get(IntegrationRepository::class)->create('hetzner_robot', 'Robot', true, ['base_url' => 'https://203.0.113.50', 'username' => '#ws+A', 'pool_prefix' => 'nebrix-free', 'verify_tls' => true], ['password' => 'x']);

        $this->actingAs($this->createUser(Role::SUPPORT));
        self::assertSame(403, $this->post('/admin/ip-pools/' . $poolId . '/rdns', ['rdns_integration_id' => (string) $robotId])->status());

        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/ip-pools/' . $poolId . '/rdns', ['rdns_integration_id' => (string) $this->proxmoxId]);
        self::assertNull($pools->find($poolId)['rdns_integration_id'] ?? null, 'Only Hetzner Robot integrations.');
        $this->post('/admin/ip-pools/' . $poolId . '/rdns', ['rdns_integration_id' => (string) $robotId]);
        self::assertSame($robotId, (int) ($pools->find($poolId)['rdns_integration_id'] ?? 0));
        self::assertStringContainsString('Reverse DNS through Hetzner Robot', $this->get('/admin/ip-pools/' . $poolId)->body());
        self::assertStringContainsString('name="rdns_integration_id"', $this->get('/admin/ip-pools/create')->body());

        // Creating a pool with the setting.
        $this->post('/admin/ip-pools', ['name' => 'Linked', 'network' => '198.51.100.0/29', 'gateway' => '198.51.100.1', 'rdns_integration_id' => (string) $robotId]);
        self::assertSame($robotId, (int) $this->db->fetchValue("SELECT rdns_integration_id FROM nx_ip_pools WHERE name = 'Linked'"));
    }
}
