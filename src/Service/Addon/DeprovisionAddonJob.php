<?php

declare(strict_types=1);

namespace Nebrix\Service\Addon;

use Nebrix\Queue\FailureAwareJobHandler;

/** Removes an ended add-on (payload service_addon_id). */
final class DeprovisionAddonJob implements FailureAwareJobHandler
{
    public function __construct(private readonly AddonService $addons)
    {
    }

    public function handle(array $payload): void
    {
        $this->addons->deprovision((int) ($payload['service_addon_id'] ?? 0));
    }

    public function failed(array $payload, string $error): void
    {
        $this->addons->deprovisioningFailed((int) ($payload['service_addon_id'] ?? 0), $error);
    }
}
