<?php

declare(strict_types=1);

namespace Nebrix\Integration\Proxmox;

use Nebrix\Catalog\ProductCategory;
use Nebrix\Integration\IntegrationException;
use Nebrix\Network\IpPoolService;
use Nebrix\Network\PoolExhaustedException;
use Nebrix\Provisioning\ProxmoxProvisioner;
use Nebrix\Service\Addon\AddonProvisioner;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;
use Psr\Log\LoggerInterface;

/**
 * Add-on "ipv4": an additional IPv4 address for a Proxmox server.
 *
 * The address comes from the IPv4 pool of the service's product (allocated in
 * a transaction with the service row locked, idempotent per add-on: a second
 * call returns the same address). It is attached to the guest as a further
 * interface without a gateway:
 *
 * - LXC: "netN" = name=ethN,bridge=…,ip=<address>/<prefix> (applied at once);
 * - KVM: "netN" = virtio,bridge=… plus cloud-init "ipconfigN" = ip=<address>/<prefix>
 *   (the NIC is hot-plugged; the guest configures the address through
 *   cloud-init at its next start).
 *
 * The interface is recorded with the address before the remote change, so a
 * retried job uses the same one; reinstalls re-create it. deprovision()
 * removes the interface (only when it still carries this address) and
 * returns the address to the pool. Every remote change checks the guest's
 * ownership marker first.
 */
final class ProxmoxIpv4Addon implements AddonProvisioner
{
    public const KIND = 'ipv4';

    public function __construct(
        private readonly ProxmoxProvisioner $proxmox,
        private readonly IpPoolService $pools,
        private readonly ServiceRepository $services,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(string $kind, array $service): bool
    {
        if ($kind !== self::KIND || !in_array((string) ($service['category'] ?? ''), [ProductCategory::VPS, ProductCategory::LXC], true) || ($service['integration_id'] ?? null) === null) {
            return false;
        }
        $config = is_array($service['config'] ?? null) ? $service['config'] : json_decode((string) ($service['config'] ?? ''), true);
        $poolId = is_array($config) ? (int) ($config['ip_pool_id'] ?? 0) : 0;
        $pool = $poolId > 0 ? $this->pools->find($poolId) : null;

        return $pool !== null && !str_contains((string) $pool['network'], ':');
    }

    public function provision(array $service, array $addon): array
    {
        return $this->attach($this->model($service), self::addonKey($addon));
    }

    public function deprovision(array $service, array $addon): void
    {
        $this->detach($this->model($service), self::addonKey($addon));
    }

    /**
     * Allocates (or finds) the address of the key and attaches it to the guest.
     *
     * @return array{address: string, gateway: string, netmask: string}
     *
     * @throws IntegrationException|PoolExhaustedException
     */
    public function attach(Service $service, string $key): array
    {
        if (!in_array($service->category, [ProductCategory::VPS, ProductCategory::LXC], true) || $service->integrationId === null) {
            throw new IntegrationException('Additional IPv4 addresses are only available for Proxmox servers.');
        }
        $poolId = (int) $service->config('ip_pool_id', 0);
        if ($poolId < 1) {
            throw new IntegrationException('The product of this server has no IPv4 pool.');
        }

        $existing = $this->pools->additionalByKey($key);
        $guest = $this->proxmox->guestExists($service) ? $this->proxmox->verifiedGuest($service) : null;
        $config = $guest !== null ? $guest[0]->guestConfig($guest[1], $guest[3], $guest[2]) : [];

        // Interfaces that are taken on the guest (net0, manual NICs, ipconfigN of KVM guests).
        $used = [];
        foreach (array_keys($config) as $name) {
            if (preg_match('/^(net|ipconfig)(\d+)$/', (string) $name, $match) === 1) {
                $used[] = 'net' . $match[2];
            }
        }
        if ($existing !== null && in_array($existing['guest_interface'], $used, true)) {
            $used = array_values(array_diff($used, [$existing['guest_interface']]));
        }
        $address = $this->pools->allocateAdditional($poolId, $service->id, $key, [...$used, 'net0']);
        $interface = $address['guest_interface'];

        if ($guest !== null) {
            [$client, $node, $vmid, $type] = $guest;
            $bridge = (string) $service->config('bridge', 'vmbr0');
            $rate = (int) $service->config('rate_mbps', 0);
            $index = substr($interface, 3);
            if ($type === ProxmoxClient::TYPE_LXC) {
                $current = (string) ($config[$interface] ?? '');
                if ($current === '') {
                    $client->updateContainerConfig($node, $vmid, [$interface => ProxmoxProvisioner::lxcExtraInterface($address, $bridge, $rate)]);
                } elseif (!self::carries($current, $address['address'])) {
                    throw new IntegrationException(sprintf('Interface %s of the container is used for something else; the address was not attached.', $interface));
                }
            } else {
                $current = (string) ($config['ipconfig' . $index] ?? '');
                if ($current === '' && !isset($config[$interface])) {
                    $client->updateVmConfig($node, $vmid, [
                        $interface => ProxmoxProvisioner::kvmExtraInterface($bridge, $rate),
                        'ipconfig' . $index => sprintf('ip=%s/%d', $address['address'], $address['prefix']),
                    ]);
                } elseif (!self::carries($current, $address['address'])) {
                    throw new IntegrationException(sprintf('Interface %s of the virtual machine is used for something else; the address was not attached.', $interface));
                }
            }
        }
        $this->logger->info('Additional IPv4 address attached', ['service' => $service->id, 'address' => $address['address'], 'interface' => $interface]);

        return ['address' => $address['address'], 'gateway' => $address['gateway'], 'netmask' => IpPoolService::netmask($address['prefix'])];
    }

    /**
     * Removes the interface of the key from the guest and releases the address (idempotent).
     *
     * @throws IntegrationException
     */
    public function detach(Service $service, string $key): void
    {
        $address = $this->pools->additionalByKey($key);
        if ($address === null) {
            return; // already released
        }
        if ($address['service_id'] !== $service->id) {
            throw new IntegrationException('The address belongs to another service.');
        }

        if ($this->proxmox->guestExists($service)) {
            [$client, $node, $vmid, $type] = $this->proxmox->verifiedGuest($service);
            $config = $client->guestConfig($node, $type, $vmid);
            $interface = $address['guest_interface'];
            $index = substr($interface, 3);
            if ($type === ProxmoxClient::TYPE_LXC) {
                if (self::carries((string) ($config[$interface] ?? ''), $address['address'])) {
                    $client->updateContainerConfig($node, $vmid, ['delete' => $interface]);
                } elseif (isset($config[$interface])) {
                    $this->logger->warning('Interface of an additional address carries another address; left unchanged', ['service' => $service->id, 'interface' => $interface]);
                }
            } elseif (self::carries((string) ($config['ipconfig' . $index] ?? ''), $address['address'])) {
                $client->updateVmConfig($node, $vmid, ['delete' => $interface . ',ipconfig' . $index]);
            } elseif (isset($config[$interface])) {
                $this->logger->warning('Interface of an additional address carries another address; left unchanged', ['service' => $service->id, 'interface' => $interface]);
            }
        }
        $this->pools->releaseAdditional($key);
        $this->logger->info('Additional IPv4 address released', ['service' => $service->id, 'address' => $address['address']]);
    }

    /** Whether a Proxmox interface line carries the address ("…ip=203.0.113.5/24…"). */
    private static function carries(string $line, string $address): bool
    {
        return preg_match('#(^|,)ip=' . preg_quote($address, '#') . '/\d+(,|$)#', $line) === 1;
    }

    /**
     * @param array<string, mixed> $addon
     */
    private static function addonKey(array $addon): string
    {
        $id = $addon['id'] ?? null;
        if (!is_numeric($id) || (int) $id < 1) {
            throw new IntegrationException('The add-on has no ID.');
        }

        return 'addon:' . (int) $id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function model(array $row): Service
    {
        $id = (int) ($row['id'] ?? 0);

        // Always the current state (remote data changes while jobs run).
        return $this->services->find($id) ?? throw new IntegrationException('The service no longer exists.');
    }
}
