<?php

declare(strict_types=1);

namespace Nebrix\Server;

use Nebrix\Catalog\ProductCategory;
use Nebrix\Domain\Dns\DnsZoneService;
use Nebrix\Network\IpPoolService;
use Nebrix\Server\Graphs\ResourceGraphs;
use Nebrix\Server\ReverseDns\ReverseDnsService;
use Nebrix\Server\Snapshots\SnapshotService;
use Nebrix\Service\Service;

/**
 * Which self-service pages (DNS, snapshots, graphs, reverse DNS) a service
 * offers, and its additional IPv4 addresses – for the service pages. Only
 * local checks; the pages themselves check again before every remote call.
 */
class ServerFeatures
{
    public function __construct(
        private readonly DnsZoneService $dns,
        private readonly SnapshotService $snapshots,
        private readonly ResourceGraphs $graphs,
        private readonly ReverseDnsService $rdns,
        private readonly IpPoolService $pools,
    ) {
    }

    /**
     * @return array{dns: bool, snapshots: bool, graphs: bool, rdns: bool, additional_ips: list<array{id: int, address: string, prefix: int, gateway: string, netmask: string, guest_interface: string, addon_key: string}>, ipv4_pool: bool}
     */
    public function forService(Service $service): array
    {
        $server = in_array($service->category, [ProductCategory::VPS, ProductCategory::LXC], true);
        $extra = [];
        if ($server) {
            foreach ($this->pools->additionalAddresses($service->id) as $address) {
                $extra[] = $address + ['netmask' => IpPoolService::netmask($address['prefix'])];
            }
        }

        return [
            'dns' => $this->dns->available($service),
            'snapshots' => $this->snapshots->available($service),
            'graphs' => $this->graphs->available($service),
            'rdns' => $this->rdns->available($service),
            'additional_ips' => $extra,
            'ipv4_pool' => $server && $service->integrationId !== null && (int) $service->config('ip_pool_id', 0) > 0,
        ];
    }
}
