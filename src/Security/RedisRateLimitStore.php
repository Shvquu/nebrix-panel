<?php

declare(strict_types=1);

namespace Nebrix\Security;

use Nebrix\Redis\RedisConnection;

/**
 * Rate-limit counters in Redis: INCR and EXPIRE run in one Lua script, so a
 * window and its counter are always created together; Redis drops expired
 * windows itself.
 */
final class RedisRateLimitStore implements RateLimitStore
{
    private const HIT = <<<'LUA'
local n = redis.call('INCR', KEYS[1])
if n == 1 or redis.call('TTL', KEYS[1]) < 0 then
  redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return n
LUA;

    private const RELEASE = <<<'LUA'
local v = tonumber(redis.call('GET', KEYS[1]) or '0')
if v > 0 then
  return redis.call('DECR', KEYS[1])
end
return 0
LUA;

    public function __construct(private readonly RedisConnection $redis)
    {
    }

    private function key(string $hash): string
    {
        return $this->redis->key('rl:' . $hash);
    }

    public function hit(string $hash, int $decaySeconds): int
    {
        return (int) $this->redis->script(self::HIT, [$this->key($hash)], [max(1, $decaySeconds)]);
    }

    public function attempts(string $hash): int
    {
        return (int) ($this->redis->client()->get($this->key($hash)) ?? 0);
    }

    public function secondsLeft(string $hash): int
    {
        return max(0, (int) $this->redis->client()->ttl($this->key($hash)));
    }

    public function release(string $hash): void
    {
        $this->redis->script(self::RELEASE, [$this->key($hash)]);
    }

    public function clear(string $hash): void
    {
        $this->redis->client()->del([$this->key($hash)]);
    }

    public function garbageCollect(): int
    {
        return 0; // keys expire in Redis
    }
}
