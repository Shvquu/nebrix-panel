<?php

declare(strict_types=1);

namespace Nebrix\Session;

use Nebrix\Core\Clock;

/**
 * Session persistence (database by default, Redis with SESSION_DRIVER=redis).
 *
 * Only the SHA-256 hash of a session ID is stored, so a leak of the store does
 * not allow session hijacking (legacy finding C-05). Sessions expire after an
 * idle timeout and after an absolute lifetime.
 */
class SessionStore
{
    public function __construct(
        private readonly SessionBackend $backend,
        private readonly Clock $clock,
        private readonly int $idleTimeoutSeconds,
        private readonly int $absoluteLifetimeSeconds,
    ) {
    }

    public static function hashId(string $id): string
    {
        return hash('sha256', $id);
    }

    public function create(): Session
    {
        $now = $this->clock->now()->getTimestamp();

        return new Session(Session::generateId(), [], true, null, $now, $now);
    }

    public function load(string $id): ?Session
    {
        if (preg_match('/^[a-f0-9]{64}$/', $id) !== 1) {
            return null;
        }

        $row = $this->backend->find(self::hashId($id));
        if ($row === null) {
            return null;
        }

        $now = $this->clock->now()->getTimestamp();
        if ($now - $row['last_activity'] > $this->idleTimeoutSeconds || $now - $row['created_at'] > $this->absoluteLifetimeSeconds) {
            $this->destroy($id);

            return null;
        }

        $data = json_decode($row['payload'], true);

        return new Session($id, is_array($data) ? $data : [], false, $row['user_id'], $row['created_at'], $row['last_activity']);
    }

    /**
     * Persists the session.
     *
     * New and regenerated IDs are inserted; an existing session is only
     * updated, never re-inserted. A request that loaded a session which was
     * deleted while the request ran (logout elsewhere, password change,
     * administrative revocation) therefore cannot bring it back.
     */
    public function save(Session $session, string $ip, string $userAgent): void
    {
        $previous = $session->previousId();
        if ($previous !== null) {
            $deleted = $this->backend->delete(self::hashId($previous));
            if ($deleted === 0 && !$session->isNew()) {
                return; // revoked during this request – stays revoked under the new ID as well
            }
        }

        if ($session->isEmpty() && ($session->isNew() || $previous !== null)) {
            // Do not persist empty anonymous sessions (bots, static pages).
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        $mustTouch = $now - $session->lastActivity() >= 60;
        if (!$session->isDirty() && !$session->isNew() && $previous === null && !$mustTouch) {
            return;
        }

        $payload = json_encode($session->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($session->isNew() || $previous !== null) {
            // A freshly generated ID is unknown to anyone else, so an upsert is
            // safe here (it only matters if the same request saves twice).
            $this->backend->insert(self::hashId($session->id()), $session->userId(), $ip, $userAgent, $payload, $now);

            return;
        }

        // A session deleted meanwhile is deliberately not recreated.
        $this->backend->update(self::hashId($session->id()), $session->userId(), $ip, $userAgent, $payload, $now);
    }

    public function destroy(string $id): void
    {
        $this->backend->delete(self::hashId($id));
    }

    /**
     * Terminates all sessions of a user, optionally keeping the current one
     * (used after password changes and by "log out other devices").
     */
    public function destroyForUser(int $userId, ?string $exceptId = null): int
    {
        return $this->backend->deleteForUser($userId, $exceptId !== null ? self::hashId($exceptId) : null);
    }

    /**
     * @return list<array{id: string, ip: string|null, user_agent: string|null, created_at: string, last_activity: string}>
     */
    public function listForUser(int $userId): array
    {
        return $this->backend->listForUser($userId);
    }

    public function countForUser(int $userId): int
    {
        return $this->backend->countForUser($userId);
    }

    public function garbageCollect(): int
    {
        $now = $this->clock->now()->getTimestamp();

        return $this->backend->garbageCollect($now - $this->idleTimeoutSeconds, $now - $this->absoluteLifetimeSeconds);
    }
}
