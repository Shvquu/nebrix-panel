<?php

declare(strict_types=1);

namespace Nebrix\Server;

use Nebrix\Integration\Proxmox\ProxmoxIpv4Addon;
use Nebrix\Queue\JobHandler;
use Nebrix\Queue\PermanentJobFailure;
use Nebrix\Service\ServiceRepository;

/**
 * Queue job "service.ipv4": an additional IPv4 address added or removed by
 * staff on the admin service page (same code path as the add-on system).
 */
final class AdditionalIpv4Job implements JobHandler
{
    public const JOB_TYPE = 'service.ipv4';

    public function __construct(
        private readonly ProxmoxIpv4Addon $addon,
        private readonly ServiceRepository $services,
    ) {
    }

    public function handle(array $payload): void
    {
        $service = $this->services->find((int) ($payload['service_id'] ?? 0)) ?? throw new PermanentJobFailure('The service no longer exists.');
        $key = (string) ($payload['key'] ?? '');
        if (preg_match('/^manual:[A-Za-z0-9]{1,40}$/', $key) !== 1) {
            throw new PermanentJobFailure('Invalid address key.');
        }
        match ((string) ($payload['operation'] ?? '')) {
            'attach' => $this->addon->attach($service, $key),
            'detach' => $this->addon->detach($service, $key),
            default => throw new PermanentJobFailure('Unknown operation.'),
        };
    }
}
