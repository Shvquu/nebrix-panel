<?php

declare(strict_types=1);

namespace Nebrix\Security;

use Nebrix\Core\Clock;
use Nebrix\Database\Connection;

/**
 * Rate-limit counters in nx_rate_limits. The counter is incremented
 * atomically with INSERT … ON DUPLICATE KEY UPDATE (legacy finding H-06).
 */
final class DatabaseRateLimitStore implements RateLimitStore
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    public function hit(string $hash, int $decaySeconds): int
    {
        $now = $this->clock->now();
        $nowString = $now->format('Y-m-d H:i:s');
        $expiresAt = $now->modify(sprintf('+%d seconds', $decaySeconds))->format('Y-m-d H:i:s');

        $this->db->run(
            'INSERT INTO `nx_rate_limits` (`key_hash`, `hits`, `expires_at`) VALUES (:k, 1, :expires)
             ON DUPLICATE KEY UPDATE
                `hits` = IF(`expires_at` <= :now1, 1, `hits` + 1),
                `expires_at` = IF(`expires_at` <= :now2, :expires2, `expires_at`)',
            ['k' => $hash, 'expires' => $expiresAt, 'now1' => $nowString, 'now2' => $nowString, 'expires2' => $expiresAt],
        );

        return (int) $this->db->fetchValue('SELECT `hits` FROM `nx_rate_limits` WHERE `key_hash` = ?', [$hash]);
    }

    public function attempts(string $hash): int
    {
        $row = $this->db->fetchOne('SELECT `hits` FROM `nx_rate_limits` WHERE `key_hash` = ? AND `expires_at` > ?', [$hash, $this->clock->nowString()]);

        return $row === null ? 0 : (int) $row['hits'];
    }

    public function secondsLeft(string $hash): int
    {
        $expires = $this->db->fetchValue('SELECT `expires_at` FROM `nx_rate_limits` WHERE `key_hash` = ?', [$hash]);
        if (!is_string($expires)) {
            return 0;
        }

        return max(0, (strtotime($expires . ' UTC') ?: 0) - $this->clock->now()->getTimestamp());
    }

    public function release(string $hash): void
    {
        $this->db->run('UPDATE `nx_rate_limits` SET `hits` = IF(`hits` > 0, `hits` - 1, 0) WHERE `key_hash` = ?', [$hash]);
    }

    public function clear(string $hash): void
    {
        $this->db->run('DELETE FROM `nx_rate_limits` WHERE `key_hash` = ?', [$hash]);
    }

    public function garbageCollect(): int
    {
        return $this->db->run('DELETE FROM `nx_rate_limits` WHERE `expires_at` < ?', [$this->clock->nowString()])->rowCount();
    }
}
