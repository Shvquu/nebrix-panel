<?php

declare(strict_types=1);

namespace Nebrix\Tests\Integration\Integrations;

use GuzzleHttp\Psr7\Response;
use Nebrix\Integration\IntegrationException;
use Nebrix\Provisioning\NextcloudProvisioner;
use Nebrix\Provisioning\PleskProvisioner;
use Nebrix\Provisioning\ProxmoxProvisioner;
use Nebrix\Provisioning\PterodactylProvisioner;
use Nebrix\Provisioning\TeamSpeakProvisioner;
use Nebrix\Service\ServiceRepository;
use Nebrix\Tests\Support\FakeHttp;
use Nebrix\Tests\Support\FakePlesk;

/**
 * Package changes (upgrade/downgrade) on each integration against fake APIs.
 */
final class PackageChangeTest extends IntegrationTestCase
{
    private int $serviceId = 0;

    public function testProxmoxVmChangesCpuMemoryAndOnlyGrowsTheDisk(): void
    {
        $id = $this->createIntegration('proxmox', ['base_url' => 'https://203.0.113.10:8006', 'token_id' => 'nebrix@pve!panel', 'verify_tls' => true], ['token_secret' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0']);
        $json = static fn (mixed $data): Response => new Response(200, [], json_encode(['data' => $data], JSON_THROW_ON_ERROR));
        $this->http
            ->on('GET', '/api2/json/nodes/pve1/qemu/10001/config', fn (): Response => $json(['description' => 'nebrix-service-' . $this->serviceId, 'scsi0' => 'local-lvm:vm-10001-disk-0,size=40G', 'net0' => 'virtio=BC:24:11:AA:BB:CC,bridge=vmbr0,rate=50']))
            ->on('POST', '/api2/json/nodes/pve1/qemu/10001/config', fn (): Response => $json(null))
            ->on('PUT', '/api2/json/nodes/pve1/qemu/10001/resize', fn (): Response => $json(null));
        $service = $this->createService($this->createUser(), 'vps', $id, ['cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 40, 'rate_mbps' => 50]);
        $this->serviceId = $service->id;
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['node' => 'pve1', 'vmid' => 10001, 'type' => 'qemu']);
        $service = $this->reload($service->id);
        $proxmox = $this->app->container()->get(ProxmoxProvisioner::class);

        self::assertSame('The disk cannot be made smaller.', $proxmox->packageChangeBlocker($service, ['disk_gb' => 20] + $service->config));
        $note = $proxmox->changePackage($service, ['cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 80, 'rate_mbps' => 0] + $service->config);
        self::assertStringContainsString('restart', (string) $note);
        $config = FakeHttp::form($this->http->requests('POST', '/api2/json/nodes/pve1/qemu/10001/config')[0]);
        self::assertSame(['4', '8192', 'virtio=BC:24:11:AA:BB:CC,bridge=vmbr0'], [$config['cores'], $config['memory'], $config['net0']], 'The rate limit is removed, the MAC address stays.');
        self::assertSame(['scsi0', '80G'], array_values(FakeHttp::form($this->http->requests('PUT', '/api2/json/nodes/pve1/qemu/10001/resize')[0])));

        // Sizes in other units are read correctly.
        self::assertSame([1, 2048, 20, null], [ProxmoxProvisioner::diskSizeGb('a,size=512M'), ProxmoxProvisioner::diskSizeGb('a,size=2T'), ProxmoxProvisioner::diskSizeGb('local:vm-1-disk-0,size=20G'), ProxmoxProvisioner::diskSizeGb('none')]);
    }

    public function testPterodactylKeepsTheAllocationAndSetsNewLimits(): void
    {
        $id = $this->createIntegration('pterodactyl', ['base_url' => 'https://203.0.113.20', 'verify_tls' => true], ['application_api_key' => 'ptla_' . str_repeat('a', 40)]);
        $this->http
            ->on('GET', '/api/application/servers/41', fn (): Response => new Response(200, ['Content-Type' => 'application/json'], json_encode(['object' => 'server', 'attributes' => ['id' => 41, 'allocation' => 77]], JSON_THROW_ON_ERROR)))
            ->on('PATCH', '/api/application/servers/41/build', fn (): Response => new Response(200, ['Content-Type' => 'application/json'], json_encode(['object' => 'server', 'attributes' => ['id' => 41]], JSON_THROW_ON_ERROR)));
        $service = $this->createService($this->createUser(), 'minecraft', $id, ['memory_mb' => 2048]);
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['server_id' => 41]);

        $this->app->container()->get(PterodactylProvisioner::class)->changePackage($this->reload($service->id), [
            'memory_mb' => 8192, 'swap_mb' => 0, 'disk_mb' => 40960, 'cpu_percent' => 400, 'io' => 500, 'databases' => 2, 'allocations' => 3, 'backups' => 5,
        ]);
        $body = json_decode((string) $this->http->requests('PATCH', '/api/application/servers/41/build')[0]->getBody(), true);
        self::assertSame(['allocation' => 77, 'memory' => 8192, 'disk' => 40960, 'cpu' => 400, 'feature_limits' => ['databases' => 2, 'allocations' => 3, 'backups' => 5]], [
            'allocation' => $body['allocation'], 'memory' => $body['memory'], 'disk' => $body['disk'], 'cpu' => $body['cpu'], 'feature_limits' => $body['feature_limits'],
        ]);
    }

    public function testTeamSpeakNextcloudAndPlesk(): void
    {
        // TeamSpeak: slots.
        $ts = $this->createIntegration('teamspeak', ['host' => '203.0.113.40', 'query_port' => 10011, 'query_user' => 'serveradmin', 'voice_host' => 'ts.example.com', 'port_min' => 9987, 'port_max' => 9989], ['query_password' => 'query-pass']);
        $this->teamspeak->servers[$this->teamspeak->nextSid++] = ['port' => 9987, 'name' => 'Clan', 'slots' => 32, 'status' => 'online', 'autostart' => 1];
        $tsService = $this->createService($this->createUser(), 'teamspeak', $ts, ['slots' => 32]);
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($tsService->id, ['sid' => 1]);
        $this->app->container()->get(TeamSpeakProvisioner::class)->changePackage($this->reload($tsService->id), ['slots' => 64]);
        self::assertSame(64, $this->teamspeak->servers[1]['slots']);

        // Nextcloud: quota.
        $nc = $this->createIntegration('nextcloud', ['base_url' => 'https://203.0.113.30', 'admin_user' => 'admin', 'user_prefix' => 'nx', 'verify_tls' => true], ['admin_password' => 'app-pass-123']);
        $this->http->on('PUT', '/ocs/v2.php/cloud/users/(?<id>[^/]+)', fn (): Response => new Response(200, [], json_encode(['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => 200, 'message' => 'OK'], 'data' => []]], JSON_THROW_ON_ERROR)));
        $ncService = $this->createService($this->createUser(), 'nextcloud', $nc, ['quota_gb' => 100]);
        $this->app->container()->get(ServiceRepository::class)->update($ncService->id, ['external_id' => 'nextcloud:nx' . $ncService->id]);
        $this->app->container()->get(NextcloudProvisioner::class)->changePackage($this->reload($ncService->id), ['quota_gb' => 250]);
        $request = $this->http->requests('PUT', '/ocs/v2.php/cloud/users/nx' . $ncService->id)[0];
        self::assertSame(['key' => 'quota', 'value' => '250 GB'], FakeHttp::form($request));

        // Plesk: service plan (looked up by name).
        $plesk = new FakePlesk();
        $plesk->register($this->http);
        $pleskId = $this->createIntegration('plesk', ['base_url' => 'https://203.0.113.60:8443', 'ip_address' => '203.0.113.61', 'verify_tls' => true], ['secret_key' => 'plesk-secret-key']);
        $plesk->webspaces[12] = ['name' => 'example-shop.de', 'owner_id' => 10, 'status' => 0, 'plan' => 'Web S', 'ftp_login' => 'nx1', 'ftp_password' => 'x', 'ip' => '203.0.113.61'];
        $web = $this->createService($this->createUser(), 'webhosting', $pleskId, ['plan' => 'Web S', '_options' => ['domain' => 'example-shop.de']]);
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($web->id, ['webspace_id' => 12, 'customer_id' => 10, 'domain' => 'example-shop.de']);
        $provisioner = $this->app->container()->get(PleskProvisioner::class);
        self::assertSame('The new product has no Plesk service plan.', $provisioner->packageChangeBlocker($this->reload($web->id), ['plan' => '']));
        $provisioner->changePackage($this->reload($web->id), ['plan' => 'Web M']);
        self::assertSame('Web M', $plesk->webspaces[12]['plan']);
        $this->expectException(IntegrationException::class);
        $provisioner->changePackage($this->reload($web->id), ['plan' => 'Unknown']);
    }
}
