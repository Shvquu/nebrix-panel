<?php

declare(strict_types=1);

namespace Nebrix\Provisioning;

use Nebrix\Queue\FailureAwareJobHandler;
use Nebrix\Queue\JobHandler;

final class FulfilOrderJob implements JobHandler
{
    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly \Nebrix\Billing\FraudCheck $fraud,
        private readonly \Nebrix\Service\Addon\AddonService $addons,
    ) {
    }

    public function handle(array $payload): void
    {
        $orderId = (int) ($payload['order_id'] ?? 0);
        // Held for fraud review: staff approval dispatches the job again.
        if (!$this->fraud->clearForFulfilment($orderId)) {
            return;
        }
        $this->provisioning->fulfilOrder($orderId);
        // Add-ons booked with the order belong to the services created above.
        $this->addons->fulfilOrder($orderId);
    }
}
