<?php

declare(strict_types=1);

namespace Nebrix\Marketing;

use Nebrix\Queue\JobHandler;
use Nebrix\Queue\PermanentJobFailure;

/**
 * Job "newsletter.batch": queues the next batch of a campaign's e-mails
 * (see NewsletterCampaigns::processBatch()).
 */
final class NewsletterBatchJob implements JobHandler
{
    public function __construct(private readonly NewsletterCampaigns $campaigns)
    {
    }

    public function handle(array $payload): void
    {
        $id = (int) ($payload['campaign_id'] ?? 0);
        if ($id <= 0) {
            throw new PermanentJobFailure('The job has no campaign.');
        }
        $this->campaigns->processBatch($id, max(1, (int) ($payload['batch'] ?? 1)));
    }
}
