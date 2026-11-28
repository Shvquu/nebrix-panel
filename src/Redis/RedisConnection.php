<?php

declare(strict_types=1);

namespace Nebrix\Redis;

use Predis\Client;
use Predis\ClientInterface;

/**
 * Lazily opened Redis connection (Predis) with the installation's key prefix.
 *
 * REDIS_URL: redis://[user:password@]host:6379/0, rediss:// for TLS, or
 * unix:///path/to/redis.sock. Keys are prefixed in code (not by Predis), so
 * Lua scripts see exactly the keys they are given.
 */
class RedisConnection
{
    private ?ClientInterface $client = null;

    public function __construct(
        #[\SensitiveParameter] private readonly string $url,
        private readonly string $prefix = 'nebrix:',
        private readonly float $timeout = 2.0,
    ) {
    }

    public function key(string $name): string
    {
        return $this->prefix . $name;
    }

    public function client(): ClientInterface
    {
        if ($this->client === null) {
            if ($this->url === '') {
                throw new \RuntimeException('REDIS_URL is not set, but a Redis driver is configured.');
            }
            $this->client = new Client($this->url, ['timeout' => $this->timeout, 'read_write_timeout' => $this->timeout, 'exceptions' => true]);
        }

        return $this->client;
    }

    /**
     * Runs a Lua script atomically.
     *
     * @param list<string> $keys full keys (already prefixed)
     * @param list<string|int> $args
     */
    public function script(string $lua, array $keys, array $args = []): mixed
    {
        return $this->client()->eval($lua, count($keys), ...$keys, ...array_map('strval', $args));
    }
}
