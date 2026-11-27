<?php

declare(strict_types=1);

namespace Nebrix\Integration\Discord;

use Nebrix\Integration\IntegrationException;

/** Discord answered 429: try again after the given number of seconds. */
final class DiscordRateLimited extends IntegrationException
{
    public function __construct(public readonly float $retryAfter)
    {
        parent::__construct(sprintf('Discord rate limit, retry after %.1f s.', $retryAfter));
    }
}
