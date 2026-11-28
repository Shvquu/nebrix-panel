<?php

declare(strict_types=1);

namespace Nebrix\Provisioning;

use Nebrix\Queue\FailureAwareJobHandler;
use Nebrix\Queue\JobHandler;

final class FulfilOrderJob implements JobHandler
{
    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly \Nebrix\Service\Addon\AddonService $addons,
    ) {
    }

    public function handle(array $payload): void
    {
        $this->provisioning->fulfilOrder((int) ($payload['order_id'] ?? 0));
        // Add-ons booked with the order belong to the services created above.
        $this->addons->fulfilOrder((int) ($payload['order_id'] ?? 0));
    }
}
