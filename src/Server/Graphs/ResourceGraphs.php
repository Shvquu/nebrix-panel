<?php

declare(strict_types=1);

namespace Nebrix\Server\Graphs;

use Nebrix\Cache\SecureCache;
use Nebrix\Catalog\ProductCategory;
use Nebrix\Integration\IntegrationException;
use Nebrix\Provisioning\ProvisionerRegistry;
use Nebrix\Provisioning\ProxmoxProvisioner;
use Nebrix\Service\Service;
use Nebrix\Settings\SettingsRepository;

/**
 * CPU, memory, network and disk I/O history of Proxmox guests (rrddata),
 * cached briefly per server and time frame so that page views do not reach
 * Proxmox on every request. The guest's ownership marker is checked before
 * every request to Proxmox.
 */
class ResourceGraphs
{
    public const TIMEFRAMES = ['hour', 'day', 'week', 'month'];

    private const MAX_POINTS = 800;

    public function __construct(
        private readonly ProvisionerRegistry $provisioners,
        private readonly SecureCache $cache,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function available(Service $service): bool
    {
        return in_array($service->category, [ProductCategory::VPS, ProductCategory::LXC], true)
            && $service->isActive()
            && $this->provisioners->supportsService($service)
            && isset($service->remote['node'], $service->remote['vmid']);
    }

    /**
     * @return array{timeframe: string, points: list<array{t: int, cpu: float|null, mem: float|null, maxmem: float|null, netin: float|null, netout: float|null, diskread: float|null, diskwrite: float|null}>}
     *
     * @throws IntegrationException
     */
    public function data(Service $service, string $timeframe): array
    {
        if (!in_array($timeframe, self::TIMEFRAMES, true)) {
            throw new \InvalidArgumentException('Unknown time frame.');
        }
        $key = sprintf('server-graphs:%d:%d:%s', $service->id, (int) ($service->remote['vmid'] ?? 0), $timeframe);
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && is_array($decoded['points'] ?? null)) {
                /** @var array{timeframe: string, points: list<array{t: int, cpu: float|null, mem: float|null, maxmem: float|null, netin: float|null, netout: float|null, diskread: float|null, diskwrite: float|null}>} $decoded */
                return $decoded;
            }
        }

        $provisioner = $this->provisioners->for($service->category);
        if (!$provisioner instanceof ProxmoxProvisioner) {
            throw new IntegrationException('Graphs are only available for Proxmox servers.');
        }
        [$client, $node, $vmid, $type] = $provisioner->verifiedGuest($service);

        $points = [];
        foreach ($client->rrdData($node, $type, $vmid, $timeframe) as $row) {
            $value = static fn (string $key): ?float => isset($row[$key]) && is_finite((float) $row[$key]) ? round((float) $row[$key], 4) : null;
            $cpu = $value('cpu');
            $points[] = [
                't' => (int) $row['time'],
                // Proxmox reports CPU as a fraction of the guest's cores (1 = all cores busy).
                'cpu' => $cpu !== null ? round(min(100, max(0, $cpu * 100)), 2) : null,
                'mem' => $value('mem'),
                'maxmem' => $value('maxmem'),
                'netin' => $value('netin'),
                'netout' => $value('netout'),
                'diskread' => $value('diskread'),
                'diskwrite' => $value('diskwrite'),
            ];
        }
        usort($points, static fn (array $a, array $b): int => $a['t'] <=> $b['t']);
        $points = array_slice($points, -self::MAX_POINTS);
        $result = ['timeframe' => $timeframe, 'points' => $points];

        $this->cache->put($key, json_encode($result, JSON_THROW_ON_ERROR), max(10, min(900, $this->settings->int('server.graph_cache_seconds'))));

        return $result;
    }
}
