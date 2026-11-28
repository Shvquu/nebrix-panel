<?php

declare(strict_types=1);

namespace Nebrix\Provisioning;

use Nebrix\Billing\InvoiceService;
use Nebrix\Queue\JobHandler;

final class JobRegistry
{
    /**
     * @return array<string, class-string<JobHandler>>
     */
    public static function handlers(): array
    {
        return [
            \Nebrix\Mail\Mailer::JOB_TYPE => \Nebrix\Mail\SendMailJob::class,
            InvoiceService::JOB_FULFIL_ORDER => FulfilOrderJob::class,
            ProvisioningService::JOB_PROVISION => ProvisionServiceJob::class,
            ProvisioningService::JOB_SUSPEND => SuspendServiceJob::class,
            InvoiceService::JOB_UNSUSPEND_SERVICE => UnsuspendServiceJob::class,
            ProvisioningService::JOB_TERMINATE => TerminateServiceJob::class,
            \Nebrix\Discord\DiscordService::JOB_SYNC => \Nebrix\Discord\DiscordSyncJob::class,
            InvoiceService::JOB_APPLY_CHANGE => \Nebrix\Service\ServiceChangeJob::class,
            \Nebrix\Notification\NotificationRouter::JOB_DISCORD_DM => \Nebrix\Notification\DiscordDirectMessageJob::class,
            \Nebrix\Notification\StaffNotifier::JOB => \Nebrix\Notification\StaffWebhookJob::class,
            \Nebrix\Server\Snapshots\SnapshotService::JOB_TYPE => \Nebrix\Server\Snapshots\SnapshotJob::class,
            \Nebrix\Server\AdditionalIpv4Job::JOB_TYPE => \Nebrix\Server\AdditionalIpv4Job::class,
        ];
    }
}
