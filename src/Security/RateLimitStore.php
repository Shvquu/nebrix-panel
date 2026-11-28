<?php

declare(strict_types=1);

namespace Nebrix\Security;

/**
 * Storage of fixed-window rate-limit counters. Keys arrive hashed.
 * Implementations must count atomically.
 */
interface RateLimitStore
{
    /** Counts a hit and returns the hits in the current window (a new window starts at 1). */
    public function hit(string $hash, int $decaySeconds): int;

    /** Hits in the current window (0 when none or expired). */
    public function attempts(string $hash): int;

    /** Seconds until the window resets (0 when none). */
    public function secondsLeft(string $hash): int;

    /** Takes back one hit (never below 0). */
    public function release(string $hash): void;

    public function clear(string $hash): void;

    /** Removes expired counters; returns how many. */
    public function garbageCollect(): int;
}
