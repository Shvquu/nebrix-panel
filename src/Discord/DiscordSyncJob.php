<?php

declare(strict_types=1);

namespace Nebrix\Discord;

use Nebrix\Integration\Discord\DiscordRateLimited;
use Nebrix\Provisioning\RetryLaterException;
use Nebrix\Queue\JobHandler;

/**
 * Applies Discord roles: {"user_id": n} syncs a linked customer,
 * {"discord_id": "…", "remove": [roles]} cleans up after unlinking.
 */
final class DiscordSyncJob implements JobHandler
{
    public function __construct(private readonly DiscordService $discord)
    {
    }

    public function handle(array $payload): void
    {
        try {
            if (isset($payload['user_id'])) {
                $this->discord->sync((int) $payload['user_id']);
            } elseif (isset($payload['discord_id'])) {
                $roles = array_values(array_filter(is_array($payload['remove'] ?? null) ? $payload['remove'] : [], 'is_string'));
                $this->discord->removeRoles((string) $payload['discord_id'], $roles);
            }
        } catch (DiscordRateLimited $limited) {
            throw new RetryLaterException('Discord rate limit.', max(1, (int) ceil($limited->retryAfter)));
        }
    }
}
