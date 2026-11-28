<?php

declare(strict_types=1);

namespace Nebrix\Provisioning;

use Nebrix\Database\Connection;
use Nebrix\Integration\IntegrationType;
use Nebrix\Service\ServiceRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Scheduler: renews domains of registrars without own renewal (SKRIME) for
 * every period the customer has paid for.
 */
final class DomainRenewals
{
    public function __construct(
        private readonly DomainProvisioner $domains,
        private readonly ServiceRepository $services,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{checked: int, renewed: int, failed: int}
     */
    public function process(): array
    {
        $result = ['checked' => 0, 'renewed' => 0, 'failed' => 0];
        $ids = $this->db->fetchAll(
            "SELECT s.`id` FROM `nx_services` s JOIN `nx_integrations` i ON i.`id` = s.`integration_id`
             WHERE s.`category` = 'domain' AND s.`status` = 'active' AND s.`next_due_at` IS NOT NULL AND i.`type` = ? AND i.`is_enabled` = 1
               AND JSON_EXTRACT(s.`remote`, '$.submitted') IS NOT NULL
             ORDER BY s.`id` LIMIT 500",
            [IntegrationType::SKRIME],
        );
        foreach ($ids as $row) {
            $service = $this->services->find((int) $row['id']);
            if ($service === null) {
                continue;
            }
            $result['checked']++;
            try {
                if ($this->domains->renewPaidPeriod($service)) {
                    $result['renewed']++;
                }
            } catch (Throwable $exception) {
                $result['failed']++;
                $this->logger->warning('Domain renewal failed', ['service' => $service->id, 'message' => $exception->getMessage()]);
            }
        }

        return $result;
    }
}
