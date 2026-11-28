<?php

declare(strict_types=1);

namespace Nebrix\Tests\Integration\Integrations;

use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\Proxmox\ProxmoxIpv4Addon;
use Nebrix\Network\IpPoolService;
use Nebrix\Network\PoolExhaustedException;
use Nebrix\Provisioning\ProxmoxProvisioner;
use Nebrix\Provisioning\RetryLaterException;
use Nebrix\Repository\UserRepository;
use Nebrix\Service\Addon\AddonProvisioner;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;
use Nebrix\Tests\Support\FakeHttp;
use Nebrix\Tests\Support\FakeProxmox;

/**
 * Add-on "ipv4": additional addresses from the product's IP pool on Proxmox
 * guests (fake API).
 */
final class AdditionalIpv4Test extends IntegrationTestCase
{
    private FakeProxmox $pve;

    private int $integrationId;

    private int $poolId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->integrationId = $this->createIntegration('proxmox', ['base_url' => 'https://203.0.113.10:8006', 'token_id' => 'nebrix@pve!panel', 'verify_tls' => true], ['token_secret' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0']);
        $this->pve = new FakeProxmox();
        $this->pve->register($this->http);
        // 203.0.113.33–.38 usable, .33 is the gateway: five addresses.
        $this->poolId = $this->app->container()->get(IpPoolService::class)->createPool('Extra', '203.0.113.32/29', '203.0.113.33', '1.1.1.1', null);
    }

    private function addon(): ProxmoxIpv4Addon
    {
        return $this->app->container()->get(ProxmoxIpv4Addon::class);
    }

    /** @return array<string, mixed> */
    private function row(int $serviceId): array
    {
        return (array) $this->db->fetchOne('SELECT * FROM nx_services WHERE id = ?', [$serviceId]);
    }

    private function server(string $category, bool $created = true): Service
    {
        $config = $category === 'lxc'
            ? ['ostemplate' => 'local:vztmpl/debian-12.tar.zst', 'storage' => 'local-lvm', 'disk_gb' => 8, 'bridge' => 'vmbr1', 'rate_mbps' => 50, 'vmid_start' => 20000]
            : ['template_vmid' => 9000, 'storage' => 'local-lvm', 'disk_gb' => 20, 'bridge' => 'vmbr1', 'rate_mbps' => 0, 'vmid_start' => 10000];
        $service = $this->createService($this->createUser(), $category, $this->integrationId, $config + ['node' => 'pve1', 'ip_pool_id' => $this->poolId, '_options' => ['hostname' => 'web1']]);
        if ($created) {
            $vmid = ($category === 'lxc' ? 20000 : 10000) + $service->id;
            $type = $category === 'lxc' ? 'lxc' : 'qemu';
            $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['node' => 'pve1', 'vmid' => $vmid, 'type' => $type, 'step' => 'done']);
            $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
            $this->pve->addGuest($vmid, $type, $service->id);
        }

        return $this->reload($service->id);
    }

    public function testContainerGetsAnAdditionalInterfaceIdempotently(): void
    {
        $service = $this->server('lxc');
        $vmid = 20000 + $service->id;
        $addon = $this->addon();
        self::assertInstanceOf(AddonProvisioner::class, $addon);
        self::assertTrue($addon->supports('ipv4', $this->row($service->id)));
        self::assertFalse($addon->supports('backup', $this->row($service->id)));

        $details = $addon->provision($this->row($service->id), ['id' => 41, 'kind' => 'ipv4', 'quantity' => 1]);
        self::assertSame(['address' => '203.0.113.34', 'gateway' => '203.0.113.33', 'netmask' => '255.255.255.248'], $details);
        $puts = $this->http->requests('PUT', '/api2/json/nodes/pve1/lxc/' . $vmid . '/config');
        self::assertCount(1, $puts);
        self::assertSame(['net1' => 'name=eth1,bridge=vmbr1,ip=203.0.113.34/29,rate=50'], FakeHttp::form($puts[0]));

        // A retried job: same address, no second change.
        self::assertSame($details, $addon->provision($this->row($service->id), ['id' => 41, 'kind' => 'ipv4']));
        self::assertCount(1, $this->http->requests('PUT', '/api2/json/nodes/pve1/lxc/' . $vmid . '/config'));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_ip_addresses WHERE service_id = ? AND addon_key = 'addon:41'", [$service->id]));

        // A second add-on takes the next address and interface.
        self::assertSame('203.0.113.35', $addon->provision($this->row($service->id), ['id' => 42])['address']);
        self::assertSame('name=eth2,bridge=vmbr1,ip=203.0.113.35/29,rate=50', $this->pve->guests[$vmid]['config']['net2']);

        // The service page lists both additional addresses.
        self::assertSame(['203.0.113.34', '203.0.113.35'], array_column($this->app->container()->get(IpPoolService::class)->additionalAddresses($service->id), 'address'));

        $addon->deprovision($this->row($service->id), ['id' => 41, 'details' => $details]);
        $last = $this->http->requests('PUT', '/api2/json/nodes/pve1/lxc/' . $vmid . '/config');
        self::assertSame(['delete' => 'net1'], FakeHttp::form(end($last)));
        self::assertSame('free', $this->db->fetchValue("SELECT status FROM nx_ip_addresses WHERE address = '203.0.113.34'"));
        self::assertNull($this->db->fetchValue("SELECT addon_key FROM nx_ip_addresses WHERE address = '203.0.113.34'"));
        $addon->deprovision($this->row($service->id), ['id' => 41]);
        self::assertCount(count($last), $this->http->requests('PUT', '/api2/json/nodes/pve1/lxc/' . $vmid . '/config'), 'Deprovisioning twice changes nothing.');
    }

    public function testVirtualMachineGetsANicAndCloudInitConfiguration(): void
    {
        $service = $this->server('vps');
        $vmid = 10000 + $service->id;
        // An interface the administrator added by hand is left alone.
        $this->pve->guests[$vmid]['config']['net1'] = 'virtio=BC:24:11:00:00:09,bridge=vmbr9';

        $details = $this->addon()->provision($this->row($service->id), ['id' => 7]);
        $posts = $this->http->requests('POST', '/api2/json/nodes/pve1/qemu/' . $vmid . '/config');
        self::assertCount(1, $posts);
        self::assertSame(['net2' => 'virtio,bridge=vmbr1', 'ipconfig2' => 'ip=' . $details['address'] . '/29'], FakeHttp::form($posts[0]));

        $this->addon()->deprovision($this->row($service->id), ['id' => 7]);
        $posts = $this->http->requests('POST', '/api2/json/nodes/pve1/qemu/' . $vmid . '/config');
        self::assertSame(['delete' => 'net2,ipconfig2'], FakeHttp::form($posts[1]));
        self::assertSame('virtio=BC:24:11:00:00:09,bridge=vmbr9', $this->pve->guests[$vmid]['config']['net1']);
    }

    public function testAddressesAreReattachedAfterReinstallAndReleasedOnTermination(): void
    {
        $service = $this->server('lxc', false);
        // Booked before the guest exists: allocated now, attached when the container is created.
        $details = $this->addon()->provision($this->row($service->id), ['id' => 9]);
        self::assertSame([], $this->http->requests('PUT', '/api2/json/nodes/pve1/lxc/\d+/config'));

        $owner = $this->app->container()->get(UserRepository::class)->find($service->userId);
        self::assertNotNull($owner);
        $provisioner = $this->app->container()->get(ProxmoxProvisioner::class);
        for ($i = 0; $i < 6; $i++) {
            try {
                $provisioner->provision($this->reload($service->id), $owner);
                break;
            } catch (RetryLaterException) {
                $this->pve->finishTasks();
            }
        }
        $form = FakeHttp::form($this->http->requests('POST', '/api2/json/nodes/pve1/lxc')[0]);
        self::assertSame('203.0.113.34', $details['address'], 'Booked first, so the add-on took the first free address.');
        self::assertSame('name=eth0,bridge=vmbr1,ip=203.0.113.35/29,gw=203.0.113.33,ip6=auto,rate=50', $form['net0'], 'The main address is a separate one (not the add-on address).');
        self::assertSame('name=eth1,bridge=vmbr1,ip=203.0.113.34/29,rate=50', $form['net1']);

        // Termination returns both addresses to the pool.
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        for ($i = 0; $i < 6; $i++) {
            try {
                $provisioner->terminate($this->reload($service->id));
                break;
            } catch (RetryLaterException) {
                $this->pve->finishTasks();
            }
        }
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_ip_addresses WHERE service_id = ? OR addon_key IS NOT NULL", [$service->id]));
    }

    public function testExhaustedPoolsAndForeignGuestsAreRefused(): void
    {
        $service = $this->server('lxc');
        $addon = $this->addon();
        foreach ([1, 2, 3, 4, 5] as $id) {
            $addon->provision($this->row($service->id), ['id' => $id]);
        }
        try {
            $addon->provision($this->row($service->id), ['id' => 6]);
            self::fail('Allocated from an exhausted pool.');
        } catch (PoolExhaustedException $exception) {
            self::assertStringContainsString('no free addresses', $exception->getMessage());
        }

        // Key of another service.
        $other = $this->server('lxc');
        $this->expectException(IntegrationException::class);
        $addon->deprovision($this->row($other->id), ['id' => 1]);
    }

    public function testGuestWithAForeignMarkerIsNotChanged(): void
    {
        $service = $this->server('lxc');
        $this->pve->guests[20000 + $service->id]['config']['description'] = 'nebrix-service-999999';

        try {
            $this->addon()->provision($this->row($service->id), ['id' => 77]);
            self::fail('Attached to a foreign guest.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('could not be verified', $exception->getMessage());
        }
        self::assertSame([], $this->http->requests('PUT', '/api2/json/nodes/pve1/lxc/\d+/config'));
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_ip_addresses WHERE addon_key = 'addon:77'"));
        self::assertFalse($this->addon()->supports('ipv4', ['category' => 'dedicated', 'integration_id' => 1, 'config' => '{"ip_pool_id": 1}']));
        self::assertFalse($this->addon()->supports('ipv4', ['category' => 'lxc', 'integration_id' => 1, 'config' => '{}']));
    }
}
