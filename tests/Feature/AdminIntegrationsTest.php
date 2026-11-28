<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use GuzzleHttp\ClientInterface;
use Nebrix\Auth\Role;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\FakeHttp;

final class AdminIntegrationsTest extends FeatureTestCase
{
    private FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = (new FakeHttp())
            ->on('GET', '/status.php', ['installed' => true, 'maintenance' => false, 'versionstring' => '30.0.0'])
            ->on('GET', '/ocs/v2.php/cloud/users', ['ocs' => ['meta' => ['statuscode' => 200, 'message' => 'OK'], 'data' => ['users' => []]]]);
        $this->app->container()->set(ClientInterface::class, fn (): ClientInterface => $this->http->client());
    }

    /**
     * @return array<string, string>
     */
    private function nextcloudForm(array $overrides = []): array
    {
        return $overrides + [
            'type' => 'nextcloud', 'name' => 'Cloud FRA', 'is_enabled' => '1',
            'config_base_url' => 'https://203.0.113.30', 'config_admin_user' => 'admin', 'config_user_prefix' => 'nx', 'config_verify_tls' => '1',
            'secret_admin_password' => 'very-secret-app-password',
        ];
    }

    public function testCreateTestAndSecretsAreWriteOnly(): void
    {
        $this->actingAs($this->createUser(Role::ADMIN));

        $response = $this->post('/admin/integrations', $this->nextcloudForm());
        $id = (int) $this->db->fetchValue("SELECT id FROM nx_integrations WHERE name = 'Cloud FRA'");
        $this->assertRedirect($response, '/admin/integrations/' . $id);
        self::assertSame('healthy', $this->db->fetchValue('SELECT health_status FROM nx_integrations WHERE id = ?', [$id]));

        foreach (['/admin/integrations', '/admin/integrations/' . $id, '/admin/integrations/' . $id . '/edit'] as $page) {
            $body = $this->get($page)->body();
            self::assertStringNotContainsString('very-secret-app-password', $body, $page);
        }

        // Saving without a new secret keeps the stored one.
        $this->post('/admin/integrations/' . $id, $this->nextcloudForm(['secret_admin_password' => '', 'name' => 'Cloud FRA 1']));
        $this->post('/admin/integrations/' . $id . '/test');
        self::assertSame('Basic ' . base64_encode('admin:very-secret-app-password'), $this->http->last()->getHeaderLine('Authorization'));

        $audit = (string) $this->db->fetchValue("SELECT details FROM nx_audit_log WHERE action = 'integration.updated'");
        self::assertStringNotContainsString('very-secret-app-password', $audit);
    }

    public function testSsrfTargetsAreRejectedWhenSaving(): void
    {
        $this->actingAs($this->createUser(Role::ADMIN));

        foreach (['https://169.254.169.254', 'https://127.0.0.1', 'http://203.0.113.30', 'https://user:pw@203.0.113.30'] as $url) {
            $this->post('/admin/integrations', $this->nextcloudForm(['config_base_url' => $url, 'name' => 'x' . md5($url)]));
        }

        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_integrations'));
        self::assertSame([], $this->http->history);
    }

    public function testRequiredSecretsOnCreate(): void
    {
        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/integrations', $this->nextcloudForm(['secret_admin_password' => '']));

        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_integrations'));
    }

    public function testSupportCanViewButNotManage(): void
    {
        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/integrations', $this->nextcloudForm());
        $id = (int) $this->db->fetchValue('SELECT id FROM nx_integrations');

        $this->actingAs($this->createUser(Role::SUPPORT));
        self::assertSame(200, $this->get('/admin/integrations/' . $id)->status());
        self::assertSame(403, $this->get('/admin/integrations/' . $id . '/edit')->status());
        self::assertSame(403, $this->post('/admin/integrations/' . $id, $this->nextcloudForm(['config_base_url' => 'https://203.0.113.99']))->status());
        self::assertSame(403, $this->post('/admin/integrations/' . $id . '/delete')->status());

        $this->actingAs($this->createUser(Role::FINANCE));
        self::assertSame(403, $this->get('/admin/integrations')->status());
    }

    public function testIntegrationWithActiveServicesCannotBeDeleted(): void
    {
        $admin = $this->createUser(Role::ADMIN);
        $this->actingAs($admin);
        $this->post('/admin/integrations', $this->nextcloudForm());
        $id = (int) $this->db->fetchValue('SELECT id FROM nx_integrations');
        $this->db->insert('nx_services', ['user_id' => $this->createUser()->id, 'integration_id' => $id, 'category' => 'nextcloud', 'name' => 'x', 'status' => 'active', 'billing_cycle' => 'monthly', 'currency' => 'EUR', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);

        $this->post('/admin/integrations/' . $id . '/delete');

        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_integrations WHERE id = ?', [$id]));
    }

    public function testHetznerWipeQueueCanBeRetriedAndFilledByManagersOnly(): void
    {
        $robot = new \Nebrix\Tests\Support\FakeRobot();
        $robot->register($this->http);
        $robot->addServer(700, 'nebrix-wipe-700');
        $robot->addServer(701, 'nebrix-free-701');
        $integrations = $this->app->container()->get(\Nebrix\Integration\IntegrationRepository::class);
        $id = $integrations->create('hetzner_robot', 'Robot', true, [
            'base_url' => 'https://203.0.113.50', 'username' => '#ws+AbCdEf', 'pool_prefix' => 'nebrix-free', 'allow_orders' => false,
            'order_test_mode' => true, 'cancel_on_terminate' => false, 'auto_wipe' => true, 'wipe_method' => 'full', 'verify_tls' => true,
        ], ['password' => 'robot-pass']);
        $this->db->insert('nx_hetzner_wipes', [
            'integration_id' => $id, 'server_number' => 699, 'status' => 'failed', 'method' => 'full', 'attempts' => 3,
            'error' => 'The rescue system could not be reached after several resets.', 'created_at' => '2026-11-28 09:00:00', 'updated_at' => '2026-11-28 09:00:00',
        ]);
        $wipeId = (int) $this->db->fetchValue('SELECT `id` FROM `nx_hetzner_wipes` WHERE `server_number` = 699');

        // Support may look, not change.
        $this->actingAs($this->createUser(Role::SUPPORT));
        $page = $this->get('/admin/integrations/' . $id)->body();
        self::assertStringContainsString('Disk wiping', $page);
        self::assertStringContainsString('could not be reached after several resets', $page);
        self::assertStringNotContainsString('/wipes/' . $wipeId . '/retry', $page);
        self::assertSame(403, $this->post('/admin/integrations/' . $id . '/wipes/' . $wipeId . '/retry')->status());
        self::assertSame(403, $this->post('/admin/integrations/' . $id . '/wipes')->status());

        $this->actingAs($this->createUser(Role::ADMIN));
        self::assertStringContainsString('/wipes/' . $wipeId . '/retry', $this->get('/admin/integrations/' . $id)->body());
        $this->post('/admin/integrations/' . $id . '/wipes/' . $wipeId . '/retry');
        self::assertSame(['pending', 0], [$this->db->fetchValue('SELECT `status` FROM `nx_hetzner_wipes` WHERE `id` = ?', [$wipeId]), (int) $this->db->fetchValue('SELECT `attempts` FROM `nx_hetzner_wipes` WHERE `id` = ?', [$wipeId])]);
        self::assertStringContainsString('Only failed wipes', $this->followRedirect($this->post('/admin/integrations/' . $id . '/wipes/' . $wipeId . '/retry'))->body());

        // Servers waiting under the wipe name are queued, pool servers are not.
        $this->post('/admin/integrations/' . $id . '/wipes');
        self::assertSame([700], array_map('intval', array_column($this->db->fetchAll('SELECT `server_number` FROM `nx_hetzner_wipes` WHERE `server_number` >= 700'), 'server_number')));

        // A wipe of another integration cannot be addressed through this one.
        $other = $integrations->create('hetzner_robot', 'Robot 2', true, ['base_url' => 'https://203.0.113.51', 'username' => '#ws+Other', 'pool_prefix' => 'nebrix-free', 'auto_wipe' => true, 'wipe_method' => 'quick', 'verify_tls' => true], ['password' => 'robot-pass-2']);
        self::assertSame(404, $this->post('/admin/integrations/' . $other . '/wipes/' . $wipeId . '/retry')->status());
    }

    public function testProxmoxNodeAccessNeedsHostKeysAndGetsAPanelKey(): void
    {
        $integrations = $this->app->container()->get(\Nebrix\Integration\IntegrationRepository::class);
        $id = $integrations->create('proxmox', 'Cluster', true, ['base_url' => 'https://203.0.113.10:8006', 'token_id' => 'nebrix@pve!panel', 'verify_tls' => true], ['token_secret' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0']);
        $this->actingAs($this->createUser(Role::ADMIN));
        $form = ['name' => 'Cluster', 'is_enabled' => '1', 'config_base_url' => 'https://203.0.113.10:8006', 'config_token_id' => 'nebrix@pve!panel', 'config_verify_tls' => '1', 'config_lxc_ssh_user' => 'root'];
        $hostKey = '203.0.113.11 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBxKkqsQwyM4Ln1Fxocek342noZ8nQglRlAwXVIL1+qP';

        self::assertStringContainsString('Off: containers get a new root password only with a reinstall', $this->get('/admin/integrations/' . $id)->body());
        foreach ([
            [['config_lxc_ssh_nodes' => 'pve1=203.0.113.11'], 'Enter the SSH host keys'],
            [['config_lxc_ssh_nodes' => 'pve1 203.0.113.11', 'config_lxc_ssh_host_keys' => $hostKey], 'node=address'],
            [['config_lxc_ssh_nodes' => 'pve1=169.254.169.254', 'config_lxc_ssh_host_keys' => $hostKey], 'blocked network range'],
            [['config_lxc_ssh_nodes' => 'pve1=203.0.113.11', 'config_lxc_ssh_host_keys' => 'garbage'], 'not an SSH host key'],
            [['config_lxc_ssh_nodes' => 'pve1=203.0.113.11', 'config_lxc_ssh_host_keys' => $hostKey, 'secret_lxc_ssh_key' => '-----BEGIN OPENSSH PRIVATE KEY-----broken'], 'unencrypted private SSH key'],
        ] as [$input, $message]) {
            $page = $this->followRedirect($this->post('/admin/integrations/' . $id, $input + $form))->body();
            self::assertStringContainsString($message, html_entity_decode($page), $message);
        }
        self::assertSame('', (string) $integrations->find($id)?->config('lxc_ssh_nodes', ''));

        $this->post('/admin/integrations/' . $id, ['config_lxc_ssh_nodes' => 'pve1=203.0.113.11', 'config_lxc_ssh_host_keys' => $hostKey] + $form);
        $integration = $integrations->find($id);
        self::assertNotNull($integration);
        self::assertStringContainsString('OPENSSH PRIVATE KEY', $integration->secret('lxc_ssh_key'), 'The panel created its key.');
        self::assertSame('0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0', $integration->secret('token_secret'), 'The API secret is kept.');
        $page = html_entity_decode($this->get('/admin/integrations/' . $id)->body());
        self::assertStringContainsString('restrict,command="/usr/local/sbin/nebrix-lxc-passwd" ssh-ed25519 ', $page);
        self::assertStringNotContainsString('PRIVATE KEY', $page);
        self::assertStringContainsString('<textarea id="f-secret_lxc_ssh_key"', $this->get('/admin/integrations/' . $id . '/edit')->body());

        // A pasted key replaces the generated one.
        $own = \Nebrix\Integration\Ssh\SecureShell::generateKey('own');
        $this->post('/admin/integrations/' . $id, ['config_lxc_ssh_nodes' => 'pve1=203.0.113.11', 'config_lxc_ssh_host_keys' => $hostKey, 'secret_lxc_ssh_key' => $own['private']] + $form);
        self::assertSame(trim($own['private']), trim((string) $integrations->find($id)?->secret('lxc_ssh_key')));
    }

    public function testPagesRender(): void
    {
        $this->actingAs($this->createUser(Role::SUPER_ADMIN));
        $this->post('/admin/integrations', $this->nextcloudForm());
        $id = (int) $this->db->fetchValue('SELECT id FROM nx_integrations');

        foreach (['/admin/integrations', '/admin/integrations/create', '/admin/integrations/create?type=proxmox', '/admin/integrations/create?type=teamspeak', '/admin/integrations/create?type=pterodactyl', '/admin/integrations/' . $id, '/admin/integrations/' . $id . '/edit', '/admin/integrations/' . $id . '/resources'] as $page) {
            self::assertSame(200, $this->get($page)->status(), $page);
        }
    }
}
