<?php

declare(strict_types=1);

namespace Nebrix\Session;

use Nebrix\Redis\RedisConnection;

/**
 * Sessions in Redis: one hash per session (key = SHA-256 of the ID) with a
 * TTL of the remaining idle/absolute lifetime, plus a set of session hashes
 * per user for "sign out everywhere" and the session list.
 *
 * Updates run as Lua scripts that write only if the session still exists,
 * so a request cannot bring back a session that was revoked meanwhile.
 */
final class RedisSessionBackend implements SessionBackend
{
    private const UPDATE = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then
  return 0
end
local old = redis.call('HGET', KEYS[1], 'user_id')
redis.call('HSET', KEYS[1], 'user_id', ARGV[1], 'ip', ARGV[2], 'user_agent', ARGV[3], 'payload', ARGV[4], 'last_activity', ARGV[5])
local created = tonumber(redis.call('HGET', KEYS[1], 'created_at') or ARGV[5])
local ttl = math.min(tonumber(ARGV[6]), created + tonumber(ARGV[7]) - tonumber(ARGV[5]))
if ttl < 1 then ttl = 1 end
redis.call('EXPIRE', KEYS[1], ttl)
if ARGV[1] ~= '' then
  redis.call('SADD', KEYS[2], ARGV[8])
  redis.call('EXPIRE', KEYS[2], tonumber(ARGV[7]))
end
return 1
LUA;

    private const INSERT = <<<'LUA'
local created = tonumber(redis.call('HGET', KEYS[1], 'created_at') or ARGV[5])
redis.call('HSET', KEYS[1], 'user_id', ARGV[1], 'ip', ARGV[2], 'user_agent', ARGV[3], 'payload', ARGV[4], 'last_activity', ARGV[5], 'created_at', created)
local ttl = math.min(tonumber(ARGV[6]), created + tonumber(ARGV[7]) - tonumber(ARGV[5]))
if ttl < 1 then ttl = 1 end
redis.call('EXPIRE', KEYS[1], ttl)
if ARGV[1] ~= '' then
  redis.call('SADD', KEYS[2], ARGV[8])
  redis.call('EXPIRE', KEYS[2], tonumber(ARGV[7]))
end
return 1
LUA;

    public function __construct(
        private readonly RedisConnection $redis,
        private readonly int $idleTimeoutSeconds,
        private readonly int $absoluteLifetimeSeconds,
    ) {
    }

    private function sessionKey(string $hash): string
    {
        return $this->redis->key('session:' . $hash);
    }

    private function userKey(int $userId): string
    {
        return $this->redis->key('user-sessions:' . $userId);
    }

    public function find(string $hash): ?array
    {
        /** @var array<string, string> $data */
        $data = $this->redis->client()->hgetall($this->sessionKey($hash));
        if ($data === [] || !isset($data['payload'], $data['created_at'], $data['last_activity'])) {
            return null;
        }

        return [
            'user_id' => ($data['user_id'] ?? '') !== '' ? (int) $data['user_id'] : null,
            'payload' => $data['payload'],
            'created_at' => (int) $data['created_at'],
            'last_activity' => (int) $data['last_activity'],
        ];
    }

    public function insert(string $hash, ?int $userId, string $ip, string $userAgent, string $payload, int $now): void
    {
        $this->redis->script(self::INSERT, [$this->sessionKey($hash), $this->userKey($userId ?? 0)], $this->args($hash, $userId, $ip, $userAgent, $payload, $now));
    }

    public function update(string $hash, ?int $userId, string $ip, string $userAgent, string $payload, int $now): bool
    {
        return (int) $this->redis->script(self::UPDATE, [$this->sessionKey($hash), $this->userKey($userId ?? 0)], $this->args($hash, $userId, $ip, $userAgent, $payload, $now)) === 1;
    }

    /**
     * @return list<string|int>
     */
    private function args(string $hash, ?int $userId, string $ip, string $userAgent, string $payload, int $now): array
    {
        return [$userId !== null ? (string) $userId : '', $ip, mb_substr($userAgent, 0, 500), $payload, $now, $this->idleTimeoutSeconds, $this->absoluteLifetimeSeconds, $hash];
    }

    public function delete(string $hash): int
    {
        return (int) $this->redis->client()->del([$this->sessionKey($hash)]);
    }

    public function deleteForUser(int $userId, ?string $exceptHash): int
    {
        $deleted = 0;
        foreach ($this->memberHashes($userId) as $hash) {
            if ($hash === $exceptHash) {
                continue;
            }
            $deleted += $this->delete($hash);
            $this->redis->client()->srem($this->userKey($userId), $hash);
        }

        return $deleted;
    }

    public function listForUser(int $userId): array
    {
        $sessions = [];
        foreach ($this->memberHashes($userId) as $hash) {
            /** @var array<string, string> $data */
            $data = $this->redis->client()->hgetall($this->sessionKey($hash));
            $sessions[] = [
                'id' => $hash,
                'ip' => $data['ip'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
                'created_at' => gmdate('Y-m-d H:i:s', (int) ($data['created_at'] ?? 0)),
                'last_activity' => gmdate('Y-m-d H:i:s', (int) ($data['last_activity'] ?? 0)),
            ];
        }
        usort($sessions, static fn (array $a, array $b): int => strcmp($b['last_activity'], $a['last_activity']));

        return $sessions;
    }

    public function countForUser(int $userId): int
    {
        return count($this->memberHashes($userId));
    }

    /**
     * Session hashes of the user that still exist and still belong to the user
     * (stale set members are removed).
     *
     * @return list<string>
     */
    private function memberHashes(int $userId): array
    {
        $client = $this->redis->client();
        $hashes = [];
        foreach ((array) $client->smembers($this->userKey($userId)) as $hash) {
            $hash = (string) $hash;
            $owner = $client->hget($this->sessionKey($hash), 'user_id');
            if ($owner === null || (string) $owner !== (string) $userId) {
                $client->srem($this->userKey($userId), $hash);
                continue;
            }
            $hashes[] = $hash;
        }

        return $hashes;
    }

    public function garbageCollect(int $idleBefore, int $createdBefore): int
    {
        return 0; // sessions expire in Redis
    }
}
