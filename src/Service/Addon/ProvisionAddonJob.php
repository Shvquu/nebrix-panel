<?php

declare(strict_types=1);

namespace Nebrix\Service\Addon;

use Nebrix\Queue\FailureAwareJobHandler;

/** Sets a paid add-on up (payload service_addon_id). */
final class ProvisionAddonJob implements FailureAwareJobHandler
{
    public function __construct(private readonly AddonService $addons)
    {
    }

    public function handle(array $payload): void
    {
        $this->addons->provision((int) ($payload['service_addon_id'] ?? 0));
    }

    public function failed(array $payload, string $error): void
    {
        $this->addons->provisioningFailed((int) ($payload['service_addon_id'] ?? 0), $error);
    }
}
