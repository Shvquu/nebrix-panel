<?php

declare(strict_types=1);

namespace Nebrix\Session;

use Nebrix\Database\Connection;

/** Sessions in nx_sessions (default). */
final class DatabaseSessionBackend implements SessionBackend
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function find(string $hash): ?array
    {
        $row = $this->db->fetchOne('SELECT `user_id`, `payload`, `created_at`, `last_activity` FROM `nx_sessions` WHERE `id` = ?', [$hash]);
        if ($row === null) {
            return null;
        }

        return [
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'payload' => (string) $row['payload'],
            'created_at' => strtotime($row['created_at'] . ' UTC') ?: 0,
            'last_activity' => strtotime($row['last_activity'] . ' UTC') ?: 0,
        ];
    }

    public function insert(string $hash, ?int $userId, string $ip, string $userAgent, string $payload, int $now): void
    {
        $time = gmdate('Y-m-d H:i:s', $now);
        $this->db->run(
            'INSERT INTO `nx_sessions` (`id`, `user_id`, `ip`, `user_agent`, `payload`, `created_at`, `last_activity`)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `user_id` = ?, `ip` = ?, `user_agent` = ?, `payload` = ?, `last_activity` = ?',
            [$hash, $userId, $ip, $userAgent, $payload, $time, $time, $userId, $ip, $userAgent, $payload, $time],
        );
    }

    public function update(string $hash, ?int $userId, string $ip, string $userAgent, string $payload, int $now): bool
    {
        // A deleted row stays deleted (zero affected rows).
        return $this->db->run(
            'UPDATE `nx_sessions` SET `user_id` = ?, `ip` = ?, `user_agent` = ?, `payload` = ?, `last_activity` = ? WHERE `id` = ?',
            [$userId, $ip, $userAgent, $payload, gmdate('Y-m-d H:i:s', $now), $hash],
        )->rowCount() > 0;
    }

    public function delete(string $hash): int
    {
        return $this->db->run('DELETE FROM `nx_sessions` WHERE `id` = ?', [$hash])->rowCount();
    }

    public function deleteForUser(int $userId, ?string $exceptHash): int
    {
        if ($exceptHash !== null) {
            return $this->db->run('DELETE FROM `nx_sessions` WHERE `user_id` = ? AND `id` <> ?', [$userId, $exceptHash])->rowCount();
        }

        return $this->db->run('DELETE FROM `nx_sessions` WHERE `user_id` = ?', [$userId])->rowCount();
    }

    public function listForUser(int $userId): array
    {
        /** @var list<array{id: string, ip: string|null, user_agent: string|null, created_at: string, last_activity: string}> */
        return $this->db->fetchAll('SELECT `id`, `ip`, `user_agent`, `created_at`, `last_activity` FROM `nx_sessions` WHERE `user_id` = ? ORDER BY `last_activity` DESC', [$userId]);
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_sessions` WHERE `user_id` = ?', [$userId]);
    }

    public function garbageCollect(int $idleBefore, int $createdBefore): int
    {
        return $this->db->run('DELETE FROM `nx_sessions` WHERE `last_activity` < ? OR `created_at` < ?', [gmdate('Y-m-d H:i:s', $idleBefore), gmdate('Y-m-d H:i:s', $createdBefore)])->rowCount();
    }
}
