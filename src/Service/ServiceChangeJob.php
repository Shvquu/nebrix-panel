<?php

declare(strict_types=1);

namespace Nebrix\Service;

use Nebrix\Queue\FailureAwareJobHandler;

/**
 * Applies a package change (after the upgrade invoice was paid, when a
 * downgrade is due, or at once for free changes).
 */
final class ServiceChangeJob implements FailureAwareJobHandler
{
    public function __construct(private readonly ServiceChangeService $changes)
    {
    }

    public function handle(array $payload): void
    {
        $this->changes->applyFromJob($payload);
    }

    public function failed(array $payload, string $error): void
    {
        $this->changes->markFailed($payload, $error);
    }
}
