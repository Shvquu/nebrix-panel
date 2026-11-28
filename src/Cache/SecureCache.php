<?php

declare(strict_types=1);

namespace Nebrix\Cache;

use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Security\Encrypter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Small shared cache in the database for values that several processes
 * reuse (web, worker, scheduler) – e.g. OAuth access tokens. Keys are
 * stored hashed, values encrypted with APP_KEY and bound to their key.
 *
 * A cache must never break its callers: storage errors are logged and the
 * value is treated as missing (the caller fetches it again).
 */
class SecureCache
{
    public function __construct(
        private readonly Connection $db,
        private readonly Encrypter $encrypter,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function get(string $key): ?string
    {
        try {
            $value = $this->db->fetchValue('SELECT `value` FROM `nx_cache` WHERE `cache_key` = ? AND `expires_at` > ?', [hash('sha256', $key), $this->clock->nowString()]);
        } catch (Throwable $exception) {
            $this->logger->warning('Cache read failed', ['message' => $exception->getMessage()]);

            return null;
        }
        if (!is_string($value)) {
            return null;
        }
        try {
            return $this->encrypter->decrypt($value, 'cache:' . $key);
        } catch (\Throwable) {
            $this->forget($key); // e.g. after a key rotation without the previous key

            return null;
        }
    }

    public function put(string $key, #[\SensitiveParameter] string $value, int $ttlSeconds): void
    {
        try {
            $this->store($key, $value, $ttlSeconds);
        } catch (Throwable $exception) {
            $this->logger->warning('Cache write failed', ['message' => $exception->getMessage()]);
        }
    }

    private function store(string $key, #[\SensitiveParameter] string $value, int $ttlSeconds): void
    {
        $this->db->run(
            'INSERT INTO `nx_cache` (`cache_key`, `value`, `expires_at`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `expires_at` = VALUES(`expires_at`)',
            [hash('sha256', $key), $this->encrypter->encrypt($value, 'cache:' . $key), $this->clock->now()->modify('+' . max(1, $ttlSeconds) . ' seconds')->format('Y-m-d H:i:s')],
        );
    }

    public function forget(string $key): void
    {
        try {
            $this->db->run('DELETE FROM `nx_cache` WHERE `cache_key` = ?', [hash('sha256', $key)]);
        } catch (Throwable $exception) {
            $this->logger->warning('Cache delete failed', ['message' => $exception->getMessage()]);
        }
    }

    public function prune(): int
    {
        return $this->db->run('DELETE FROM `nx_cache` WHERE `expires_at` <= ?', [$this->clock->nowString()])->rowCount();
    }
}
