<?php

declare(strict_types=1);

namespace Nebrix\Cache;

use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Security\Encrypter;

/**
 * Small shared cache in the database for values that several processes
 * reuse (web, worker, scheduler) – e.g. OAuth access tokens. Keys are
 * stored hashed, values encrypted with APP_KEY and bound to their key.
 */
class SecureCache
{
    public function __construct(
        private readonly Connection $db,
        private readonly Encrypter $encrypter,
        private readonly Clock $clock,
    ) {
    }

    public function get(string $key): ?string
    {
        $value = $this->db->fetchValue('SELECT `value` FROM `nx_cache` WHERE `cache_key` = ? AND `expires_at` > ?', [hash('sha256', $key), $this->clock->nowString()]);
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
        $this->db->run(
            'INSERT INTO `nx_cache` (`cache_key`, `value`, `expires_at`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `expires_at` = VALUES(`expires_at`)',
            [hash('sha256', $key), $this->encrypter->encrypt($value, 'cache:' . $key), $this->clock->now()->modify('+' . max(1, $ttlSeconds) . ' seconds')->format('Y-m-d H:i:s')],
        );
    }

    public function forget(string $key): void
    {
        $this->db->run('DELETE FROM `nx_cache` WHERE `cache_key` = ?', [hash('sha256', $key)]);
    }

    public function prune(): int
    {
        return $this->db->run('DELETE FROM `nx_cache` WHERE `expires_at` <= ?', [$this->clock->nowString()])->rowCount();
    }
}
