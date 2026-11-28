<?php

declare(strict_types=1);

namespace Nebrix\Server\ReverseDns;

use Nebrix\Audit\AuditLogger;
use Nebrix\Cache\SecureCache;
use Nebrix\Catalog\ProductCategory;
use Nebrix\Http\IpRange;
use Nebrix\Integration\Hetzner\RobotClient;
use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\IntegrationManager;
use Nebrix\Network\IpPoolService;
use Nebrix\Provisioning\HetznerRobotProvisioner;
use Nebrix\Provisioning\ProvisionerRegistry;
use Nebrix\Provisioning\ServiceStateException;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;
use Psr\Log\LoggerInterface;

/**
 * Reverse DNS (PTR records) of the addresses of a customer's service, set
 * through the Hetzner Robot API (/rdns/{ip}):
 *
 * - dedicated servers of a Hetzner Robot integration: the main IPv4, the
 *   additional single addresses and any IPv6 address inside the server's
 *   subnets (server data read live, after the marker check of the server);
 * - Proxmox servers whose addresses come from an IP pool linked to a Hetzner
 *   Robot integration (pool setting); other pools have no reverse DNS here.
 *
 * Only addresses that belong to the service are accepted; host names are
 * validated; every change is audit-logged. Current PTR values are cached for
 * a few minutes because of the Robot request limits.
 */
class ReverseDnsService
{
    public const HOSTNAME = '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    private const CACHE_SECONDS = 600;
    private const MAX_IPV6_ENTRIES = 20;

    public function __construct(
        private readonly ProvisionerRegistry $provisioners,
        private readonly IntegrationManager $integrations,
        private readonly IpPoolService $pools,
        private readonly ServiceRepository $services,
        private readonly SecureCache $cache,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Whether reverse DNS can be managed for the service (no remote call). */
    public function available(Service $service): bool
    {
        if (!$service->isActive() || $service->integrationId === null) {
            return false;
        }
        if ($service->category === ProductCategory::DEDICATED) {
            return $this->provisioners->supportsService($service) && isset($service->remote['server_number']);
        }
        if (!in_array($service->category, [ProductCategory::VPS, ProductCategory::LXC], true)) {
            return false;
        }
        foreach ($this->pools->serviceAddresses($service->id) as $address) {
            if ($address['rdns_integration_id'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Addresses with their current PTR record, and the IPv6 subnets in which
     * the customer may set records for single addresses.
     *
     * @return array{addresses: list<array{ip: string, family: int, ptr: string|null, custom: bool}>, subnets: list<string>}
     *
     * @throws ServiceStateException|IntegrationException
     */
    public function overview(Service $service): array
    {
        $targets = $this->targets($service);
        $addresses = [];
        foreach ($targets['addresses'] as $ip => $client) {
            $addresses[] = ['ip' => $ip, 'family' => str_contains($ip, ':') ? 6 : 4, 'ptr' => $this->currentPtr($client, $ip), 'custom' => false];
        }
        // IPv6 addresses inside the subnets that were set through the panel.
        foreach ($this->customEntries($service) as $ip) {
            if ($targets['subnet_client'] !== null && !isset($targets['addresses'][$ip]) && $this->inSubnets($ip, $targets['subnets'])) {
                $addresses[] = ['ip' => $ip, 'family' => 6, 'ptr' => $this->currentPtr($targets['subnet_client'], $ip), 'custom' => true];
            }
        }

        return ['addresses' => $addresses, 'subnets' => $targets['subnets']];
    }

    /**
     * @throws ServiceStateException with a user-facing message
     * @throws IntegrationException
     */
    public function set(Service $service, string $ip, string $hostname, ?int $actorId, ?string $clientIp): void
    {
        $ptr = self::normalizeHostname($hostname);
        [$client, $ip, $custom] = $this->resolve($service, $ip);
        $client->setReverseDns($ip, $ptr);
        $this->cache->put($this->cacheKey($ip), $ptr, self::CACHE_SECONDS);
        if ($custom) {
            $entries = $this->customEntries($service);
            if (!in_array($ip, $entries, true)) {
                $this->services->mergeRemote($service->id, ['rdns6' => array_slice([...$entries, $ip], -self::MAX_IPV6_ENTRIES)]);
            }
        }
        $this->audit->log($actorId, 'service.rdns_updated', 'service', $service->id, ['ip' => $ip, 'ptr' => $ptr], $clientIp);
    }

    /**
     * @throws ServiceStateException
     * @throws IntegrationException
     */
    public function delete(Service $service, string $ip, ?int $actorId, ?string $clientIp): void
    {
        [$client, $ip, $custom] = $this->resolve($service, $ip);
        $client->deleteReverseDns($ip);
        $this->cache->forget($this->cacheKey($ip));
        if ($custom) {
            $this->services->mergeRemote($service->id, ['rdns6' => array_values(array_diff($this->customEntries($service), [$ip]))]);
        }
        $this->audit->log($actorId, 'service.rdns_deleted', 'service', $service->id, ['ip' => $ip], $clientIp);
    }

    /**
     * Lower-case host name without a trailing dot.
     *
     * @throws ServiceStateException
     */
    public static function normalizeHostname(string $hostname): string
    {
        $hostname = rtrim(mb_strtolower(trim($hostname)), '.');
        if (preg_match(self::HOSTNAME, $hostname) !== 1) {
            throw new ServiceStateException('Enter a fully qualified host name, e.g. server1.example.com.');
        }

        return $hostname;
    }

    /**
     * Robot client for an address of the service; the address must belong to it.
     *
     * @return array{0: RobotClient, 1: string, 2: bool} client, canonical address, IPv6 address of a subnet
     *
     * @throws ServiceStateException|IntegrationException
     */
    private function resolve(Service $service, string $ip): array
    {
        $binary = @inet_pton(trim($ip));
        if ($binary === false) {
            throw new ServiceStateException('This address does not belong to your service.');
        }
        $ip = (string) inet_ntop($binary);
        $targets = $this->targets($service);
        if (isset($targets['addresses'][$ip])) {
            return [$targets['addresses'][$ip], $ip, false];
        }
        if ($targets['subnet_client'] !== null && str_contains($ip, ':') && $this->inSubnets($ip, $targets['subnets'])) {
            return [$targets['subnet_client'], $ip, true];
        }

        throw new ServiceStateException('This address does not belong to your service.');
    }

    /**
     * @return array{addresses: array<string, RobotClient>, subnets: list<string>, subnet_client: RobotClient|null}
     *
     * @throws ServiceStateException|IntegrationException
     */
    private function targets(Service $service): array
    {
        if (!$this->available($service)) {
            throw new ServiceStateException('Reverse DNS is not available for this service.');
        }

        if ($service->category === ProductCategory::DEDICATED) {
            $provisioner = $this->provisioners->for($service->category);
            if (!$provisioner instanceof HetznerRobotProvisioner) {
                throw new ServiceStateException('Reverse DNS is not available for this service.');
            }
            [$client, $server] = $provisioner->ownedServerData($service);
            $addresses = [];
            foreach ([$server['server_ip'] ?? null, ...(is_array($server['ip'] ?? null) ? $server['ip'] : [])] as $ip) {
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $addresses[$ip] = $client;
                }
            }
            $subnets = [];
            foreach (is_array($server['subnet'] ?? null) ? $server['subnet'] : [] as $subnet) {
                if (is_array($subnet) && is_string($subnet['ip'] ?? null) && str_contains($subnet['ip'], ':') && is_numeric($subnet['mask'] ?? null)) {
                    $subnets[] = self::subnet($subnet['ip'], (int) $subnet['mask']);
                }
            }
            if (is_string($server['server_ipv6_net'] ?? null) && str_contains($server['server_ipv6_net'], ':')) {
                $subnets[] = self::subnet(explode('/', $server['server_ipv6_net'])[0], 64);
            }

            return ['addresses' => $addresses, 'subnets' => array_values(array_unique(array_filter($subnets))), 'subnet_client' => $subnets !== [] ? $client : null];
        }

        // Proxmox: addresses of pools linked to a Hetzner Robot integration only.
        $addresses = [];
        $clients = [];
        foreach ($this->pools->serviceAddresses($service->id) as $address) {
            $integrationId = $address['rdns_integration_id'];
            if ($integrationId === null) {
                continue;
            }
            $clients[$integrationId] ??= $this->integrations->hetznerRobot($this->integrations->get($integrationId));
            $binary = @inet_pton($address['address']);
            if ($binary !== false) {
                $addresses[(string) inet_ntop($binary)] = $clients[$integrationId];
            }
        }

        return ['addresses' => $addresses, 'subnets' => [], 'subnet_client' => null];
    }

    private function currentPtr(RobotClient $client, string $ip): ?string
    {
        $cached = $this->cache->get($this->cacheKey($ip));
        if ($cached !== null) {
            return $cached === '' ? null : $cached;
        }
        try {
            $ptr = $client->reverseDns($ip);
        } catch (IntegrationException $exception) {
            $this->logger->notice('Reverse DNS entry could not be read', ['ip' => $ip, 'message' => $exception->getMessage()]);

            return null;
        }
        $this->cache->put($this->cacheKey($ip), $ptr ?? '', self::CACHE_SECONDS);

        return $ptr;
    }

    /**
     * @return list<string>
     */
    private function customEntries(Service $service): array
    {
        $fresh = $this->services->find($service->id) ?? $service;
        $entries = $fresh->remote['rdns6'] ?? [];

        return is_array($entries) ? array_values(array_filter($entries, 'is_string')) : [];
    }

    /**
     * @param list<string> $subnets
     */
    private function inSubnets(string $ip, array $subnets): bool
    {
        foreach ($subnets as $subnet) {
            if (IpRange::contains($subnet, $ip)) {
                return true;
            }
        }

        return false;
    }

    private static function subnet(string $ip, int $mask): string
    {
        $binary = @inet_pton($ip);
        if ($binary === false || strlen($binary) !== 16 || $mask < 32 || $mask > 128) {
            return '';
        }

        return inet_ntop($binary) . '/' . $mask;
    }

    private function cacheKey(string $ip): string
    {
        return 'rdns:' . $ip;
    }
}
