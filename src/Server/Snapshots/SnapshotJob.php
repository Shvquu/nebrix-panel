<?php

declare(strict_types=1);

namespace Nebrix\Server\Snapshots;

use Nebrix\Queue\FailureAwareJobHandler;

/** Queue job "service.snapshot": runs one snapshot task. */
final class SnapshotJob implements FailureAwareJobHandler
{
    public function __construct(private readonly SnapshotService $snapshots)
    {
    }

    public function handle(array $payload): void
    {
        $this->snapshots->process((int) ($payload['task_id'] ?? 0));
    }

    public function failed(array $payload, string $error): void
    {
        $this->snapshots->failed((int) ($payload['task_id'] ?? 0), $error);
    }
}
