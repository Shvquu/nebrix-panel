<?php

declare(strict_types=1);

namespace Nebrix\Session;

/**
 * Persistence of sessions under the SHA-256 hash of their ID.
 */
interface SessionBackend
{
    /**
     * @return array{user_id: int|null, payload: string, created_at: int, last_activity: int}|null
     */
    public function find(string $hash): ?array;

    /** Inserts a session (or replaces one with the same, freshly generated ID). */
    public function insert(string $hash, ?int $userId, string $ip, string $userAgent, string $payload, int $now): void;

    /** Updates an existing session; a session that no longer exists is never recreated. Returns whether a row was written. */
    public function update(string $hash, ?int $userId, string $ip, string $userAgent, string $payload, int $now): bool;

    /** Returns the number of deleted sessions (0 or 1). */
    public function delete(string $hash): int;

    public function deleteForUser(int $userId, ?string $exceptHash): int;

    /**
     * @return list<array{id: string, ip: string|null, user_agent: string|null, created_at: string, last_activity: string}> newest activity first
     */
    public function listForUser(int $userId): array;

    public function countForUser(int $userId): int;

    public function garbageCollect(int $idleBefore, int $createdBefore): int;
}
