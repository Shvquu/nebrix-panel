<?php

declare(strict_types=1);

namespace Nebrix\Provisioning;

use Nebrix\Integration\IntegrationException;
use Nebrix\Service\Service;

/**
 * Provisioners that can change the package (resources, limits, plan) of an
 * existing service in place – used for upgrades and downgrades.
 */
interface PackageChangeable
{
    /**
     * Configuration keys that make up the package. Only these values are
     * taken from the target product; everything else (template, node, IP
     * pool, customer options) stays as it is.
     *
     * @return list<string>
     */
    public function packageKeys(Service $service): array;

    /**
     * Why the change is not possible (e.g. a disk cannot be made smaller),
     * or null. Must not contact the remote system.
     *
     * @param array<string, mixed> $newConfig the service configuration after the change
     */
    public function packageChangeBlocker(Service $service, array $newConfig): ?string;

    /**
     * Applies the new package on the remote system. Must be idempotent (the
     * job may run again).
     *
     * @param array<string, mixed> $newConfig
     * @return string|null a note for the customer, e.g. that a restart is needed
     *
     * @throws RetryLaterException|IntegrationException
     */
    public function changePackage(Service $service, array $newConfig): ?string;
}
