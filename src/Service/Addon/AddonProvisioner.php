<?php

declare(strict_types=1);

namespace Nebrix\Service\Addon;

/**
 * Fulfils booked add-ons of a service automatically (e.g. an additional IPv4
 * address on a Proxmox server). Add-on kinds without a provisioner that
 * supports them are fulfilled manually by staff.
 *
 * Implementations are called from queued jobs, never from a web request, and
 * must be idempotent: calling provision() again for an add-on that is already
 * set up returns the existing details instead of allocating a second resource.
 */
interface AddonProvisioner
{
    /**
     * Whether this provisioner fulfils add-ons of the given kind for the service.
     *
     * @param array<string, mixed> $service row of nx_services
     */
    public function supports(string $kind, array $service): bool;

    /**
     * Sets the add-on up and returns the details to store on it
     * (shown to the customer, e.g. ['address' => '203.0.113.5']).
     *
     * @param array<string, mixed> $service row of nx_services
     * @param array<string, mixed> $addon   the booked add-on (id, kind, quantity, details)
     *
     * @return array<string, string|int|float|bool|null>
     */
    public function provision(array $service, array $addon): array;

    /**
     * Removes the add-on again (cancellation at the end of the term, service deletion).
     *
     * @param array<string, mixed> $service row of nx_services
     * @param array<string, mixed> $addon   the booked add-on including the details provision() returned
     */
    public function deprovision(array $service, array $addon): void;
}
