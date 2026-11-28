<?php

declare(strict_types=1);

namespace Nebrix\Security;

/**
 * Fixed-window rate limiter.
 *
 * Counters live in the configured store (database by default, Redis with
 * RATE_LIMIT_DRIVER=redis); both count atomically, so concurrent requests
 * cannot bypass a limit (legacy finding H-06). Keys are hashed before
 * storage (they may contain e-mail addresses / IPs).
 */
class RateLimiter
{
    public function __construct(private readonly RateLimitStore $store)
    {
    }

    /**
     * Key part for a client address: IPv4 as is, IPv6 by network (a single
     * customer usually holds a whole /64 or larger), so rotating addresses
     * within one allocation does not escape a limit.
     */
    public static function network(?string $ip, int $ipv6PrefixBits = 64): string
    {
        $packed = $ip !== null ? @inet_pton($ip) : false;
        if ($packed === false || strlen($packed) !== 16) {
            return (string) $ip;
        }
        $bytes = intdiv(max(8, min(128, $ipv6PrefixBits)), 8);

        return bin2hex(substr($packed, 0, $bytes)) . '::/' . ($bytes * 8);
    }

    /** Registers a hit and returns the number of hits in the current window. */
    public function hit(string $key, int $decaySeconds): int
    {
        return $this->store->hit(hash('sha256', $key), $decaySeconds);
    }

    public function attempts(string $key): int
    {
        return $this->store->attempts(hash('sha256', $key));
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    /** Seconds until the key's window resets. */
    public function availableIn(string $key): int
    {
        return $this->store->secondsLeft(hash('sha256', $key));
    }

    /**
     * Counts an attempt and reports whether it is still within the limit.
     *
     * The atomic increment decides, not a preceding read: concurrent requests
     * cannot all pass a check before any of them is counted.
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        return $this->hit($key, $decaySeconds) <= $maxAttempts;
    }

    /** Takes back one counted attempt (e.g. a successful sign-in from an IP). */
    public function release(string $key): void
    {
        $this->store->release(hash('sha256', $key));
    }

    public function clear(string $key): void
    {
        $this->store->clear(hash('sha256', $key));
    }

    public function garbageCollect(): int
    {
        return $this->store->garbageCollect();
    }
}
