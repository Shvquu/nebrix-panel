<?php

declare(strict_types=1);

namespace Nebrix\Server\SshKeys;

use InvalidArgumentException;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Settings\SettingsRepository;

/**
 * Public SSH keys of customers. Every lookup is scoped to the owner, so a
 * key of another customer can never be listed, used or deleted (IDOR).
 */
class SshKeyRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function maxKeys(): int
    {
        return max(1, min(50, $this->settings->int('server.ssh_keys_max')));
    }

    /**
     * @return list<array{id: int, name: string, type: string, bits: int, fingerprint: string, public_key: string, created_at: string, last_used_at: string|null}>
     */
    public function forUser(int $userId): array
    {
        return array_map(self::row(...), $this->db->fetchAll('SELECT * FROM `nx_ssh_keys` WHERE `user_id` = ? ORDER BY `name`, `id`', [$userId]));
    }

    /**
     * Key ID => "name (fingerprint)" for checkbox lists.
     *
     * @return array<int, string>
     */
    public function options(int $userId): array
    {
        $options = [];
        foreach ($this->forUser($userId) as $key) {
            $options[$key['id']] = sprintf('%s (%s)', $key['name'], $key['fingerprint']);
        }

        return $options;
    }

    /**
     * @return array{id: int, name: string, type: string, bits: int, fingerprint: string, public_key: string, created_at: string, last_used_at: string|null}|null
     */
    public function findForUser(int $id, int $userId): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM `nx_ssh_keys` WHERE `id` = ? AND `user_id` = ?', [$id, $userId]);

        return $row === null ? null : self::row($row);
    }

    /**
     * Stores a key; refuses duplicates and more keys than allowed (counted
     * under a lock of the customer row, so parallel requests cannot exceed it).
     *
     * @throws InvalidArgumentException with a user-facing message
     */
    public function add(int $userId, string $name, SshPublicKey $key): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new InvalidArgumentException('Enter a name of at most 100 characters.');
        }

        return $this->db->transaction(function () use ($userId, $name, $key): int {
            $this->db->fetchValue('SELECT `id` FROM `nx_users` WHERE `id` = ? FOR UPDATE', [$userId]);
            if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_ssh_keys` WHERE `user_id` = ?', [$userId]) >= $this->maxKeys()) {
                throw new InvalidArgumentException('You have reached the maximum number of SSH keys. Delete a key you no longer use first.');
            }
            if ($this->db->fetchValue('SELECT `id` FROM `nx_ssh_keys` WHERE `user_id` = ? AND `fingerprint` = ?', [$userId, $key->fingerprint()]) !== null) {
                throw new InvalidArgumentException('This key is already stored in your account.');
            }

            return $this->db->insert('nx_ssh_keys', [
                'user_id' => $userId,
                'name' => $name,
                'type' => $key->type,
                'bits' => $key->bits,
                'public_key' => $key->line(),
                'fingerprint' => $key->fingerprint(),
                'created_at' => $this->clock->nowString(),
            ]);
        });
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->db->run('DELETE FROM `nx_ssh_keys` WHERE `id` = ? AND `user_id` = ?', [$id, $userId])->rowCount() === 1;
    }

    /**
     * Public key lines of the given keys – only keys of $userId; unknown or
     * foreign IDs are skipped. Each line is re-validated before it is used.
     *
     * @param list<int> $ids
     * @return list<string>
     */
    public function publicKeys(int $userId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `public_key` FROM `nx_ssh_keys` WHERE `user_id` = ? AND `id` IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY `id`',
            [$userId, ...$ids],
        );
        $lines = [];
        foreach ($rows as $row) {
            try {
                $lines[] = SshPublicKey::parse((string) $row['public_key'])->line();
            } catch (InvalidArgumentException) {
                continue; // never install anything that is not a valid public key
            }
        }
        if ($lines !== []) {
            $this->db->run(
                'UPDATE `nx_ssh_keys` SET `last_used_at` = ? WHERE `user_id` = ? AND `id` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                [$this->clock->nowString(), $userId, ...$ids],
            );
        }

        return $lines;
    }

    /**
     * Whether all IDs are keys of the user.
     *
     * @param list<int> $ids
     */
    public function ownsAll(int $userId, array $ids): bool
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return true;
        }

        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM `nx_ssh_keys` WHERE `user_id` = ? AND `id` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            [$userId, ...$ids],
        ) === count($ids);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, type: string, bits: int, fingerprint: string, public_key: string, created_at: string, last_used_at: string|null}
     */
    private static function row(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'bits' => (int) $row['bits'],
            'fingerprint' => (string) $row['fingerprint'],
            'public_key' => (string) $row['public_key'],
            'created_at' => (string) $row['created_at'],
            'last_used_at' => isset($row['last_used_at']) ? (string) $row['last_used_at'] : null,
        ];
    }
}
