<?php

declare(strict_types=1);

namespace Nebrix\Service\Addon;

/**
 * Registry of the add-on provisioners (bound in config/container.php).
 *
 * The first provisioner that supports the kind for the service fulfils a
 * booked add-on automatically; without one the add-on goes to the staff
 * queue (Admin → Add-ons to set up).
 */
final class AddonProvisioners
{
    /** @var list<AddonProvisioner> */
    private readonly array $provisioners;

    /**
     * @param iterable<AddonProvisioner> $provisioners
     */
    public function __construct(iterable $provisioners = [])
    {
        $list = [];
        foreach ($provisioners as $provisioner) {
            $list[] = $provisioner;
        }
        $this->provisioners = $list;
    }

    /**
     * @param array<string, mixed> $service row of nx_services
     */
    public function for(string $kind, array $service): ?AddonProvisioner
    {
        foreach ($this->provisioners as $provisioner) {
            if ($provisioner->supports($kind, $service)) {
                return $provisioner;
            }
        }

        return null;
    }

    /**
     * @return list<AddonProvisioner>
     */
    public function all(): array
    {
        return $this->provisioners;
    }
}
