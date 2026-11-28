<?php

declare(strict_types=1);

namespace Nebrix\Service\Addon;

use Nebrix\Core\Clock;
use Nebrix\Database\Connection;

/**
 * Add-on catalog (nx_addons) and booked add-ons of services
 * (nx_service_addons). Customer-facing lookups take the user ID.
 */
class AddonRepository
{
    public const KIND_IPV4 = 'ipv4';
    public const KIND_BACKUP_STORAGE = 'backup_storage';
    public const KIND_PRIORITY_SUPPORT = 'priority_support';
    public const KIND_CUSTOM = 'custom';

    /** Booked, invoice not paid yet. */
    public const PENDING_PAYMENT = 'pending_payment';
    /** Paid, waiting for the provisioner or staff. */
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    /** Automatic set-up failed: staff take over. */
    public const FAILED = 'failed';
    /** Ended (cancellation date, service terminated): to be removed. */
    public const REMOVING = 'removing';
    public const REMOVED = 'removed';
    /** Never paid (booking invoice cancelled). */
    public const CANCELLED = 'cancelled';

    /** Statuses of add-ons that belong to the service (paid, not ended). */
    public const LIVE = [self::PENDING, self::ACTIVE, self::FAILED];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array<string, string> kind => label
     */
    public static function kinds(): array
    {
        return [
            self::KIND_IPV4 => 'Additional IPv4 address',
            self::KIND_BACKUP_STORAGE => 'Backup storage',
            self::KIND_PRIORITY_SUPPORT => 'Priority support',
            self::KIND_CUSTOM => 'Custom',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM `nx_addons` WHERE `id` = ?', [$id]);

        return $row !== null ? self::decode($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(self::decode(...), $this->db->fetchAll(
            "SELECT a.*, (SELECT COUNT(*) FROM `nx_service_addons` s WHERE s.`addon_id` = a.`id` AND s.`status` IN ('pending', 'active', 'failed')) AS `booked`
               FROM `nx_addons` a ORDER BY a.`is_active` DESC, a.`sort_order`, a.`name` LIMIT 500",
        ));
    }

    /**
     * Active add-ons a product offers (same currency).
     *
     * @param array<string, mixed> $product
     * @return list<array<string, mixed>>
     */
    public function offeredFor(array $product): array
    {
        $offered = [];
        foreach ($this->db->fetchAll('SELECT * FROM `nx_addons` WHERE `is_active` = 1 ORDER BY `sort_order`, `name`') as $row) {
            $addon = self::decode($row);
            if (self::offers($addon, $product)) {
                $offered[] = $addon;
            }
        }

        return $offered;
    }

    /**
     * @param array<string, mixed> $addon
     * @param array<string, mixed> $product
     */
    public static function offers(array $addon, array $product): bool
    {
        return (bool) $addon['is_active']
            && strtoupper((string) $addon['currency']) === strtoupper((string) ($product['currency'] ?? ''))
            && (in_array((int) ($product['id'] ?? 0), $addon['product_ids'], true) || in_array((string) ($product['category'] ?? ''), $addon['categories'], true));
    }

    /**
     * @param array<string, mixed> $data validated admin input
     */
    public function save(?int $id, array $data, int $actorId): int
    {
        $row = [
            'name' => (string) $data['name'],
            'description' => ($data['description'] ?? '') !== '' ? (string) $data['description'] : null,
            'kind' => (string) $data['kind'],
            'price' => (int) $data['price'],
            'setup_fee' => (int) $data['setup_fee'],
            'currency' => (string) $data['currency'],
            'tax_rate_bp' => (int) $data['tax_rate_bp'],
            'max_quantity' => max(1, (int) $data['max_quantity']),
            'product_ids' => $data['product_ids'] !== [] ? implode(',', array_map('intval', $data['product_ids'])) : null,
            'categories' => $data['categories'] !== [] ? implode(',', $data['categories']) : null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'updated_at' => $this->clock->nowString(),
        ];
        if ($id === null) {
            return $this->db->insert('nx_addons', $row + ['created_by' => $actorId, 'created_at' => $this->clock->nowString()]);
        }
        $this->db->update('nx_addons', $row, ['id' => $id]);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function booked(int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM `nx_service_addons` WHERE `id` = ?', [$id]);

        return $row !== null ? self::decodeBooked($row) : null;
    }

    /**
     * Ownership-scoped lookup of a booked add-on (IDOR protection).
     *
     * @return array<string, mixed>|null
     */
    public function bookedForUser(int $id, int $serviceId, int $userId): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM `nx_service_addons` WHERE `id` = ? AND `service_id` = ? AND `user_id` = ?', [$id, $serviceId, $userId]);

        return $row !== null ? self::decodeBooked($row) : null;
    }

    /**
     * @return array<string, mixed>|null locked row (inside a transaction)
     */
    public function lockBooked(int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM `nx_service_addons` WHERE `id` = ? FOR UPDATE', [$id]);

        return $row !== null ? self::decodeBooked($row) : null;
    }

    /**
     * Add-ons of a service that are not removed or cancelled.
     *
     * @return list<array<string, mixed>>
     */
    public function forService(int $serviceId): array
    {
        return array_map(self::decodeBooked(...), $this->db->fetchAll(
            "SELECT * FROM `nx_service_addons` WHERE `service_id` = ? AND `status` NOT IN ('removed', 'cancelled') ORDER BY `id`",
            [$serviceId],
        ));
    }

    /**
     * Add-ons billed with the renewal of a period starting at $periodStart:
     * paid, set up (or waiting for staff) and not cancelled before it.
     *
     * @return list<array<string, mixed>>
     */
    public function renewable(int $serviceId, string $periodStart): array
    {
        return array_map(self::decodeBooked(...), $this->db->fetchAll(
            "SELECT * FROM `nx_service_addons` WHERE `service_id` = ? AND `status` IN ('pending', 'active')
                AND (`cancel_at` IS NULL OR `cancel_at` > ?) ORDER BY `id`",
            [$serviceId, $periodStart],
        ));
    }

    /** Units of an add-on a service already holds or has booked (towards the maximum quantity). */
    public function bookedQuantity(int $serviceId, int $addonId): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COALESCE(SUM(`quantity`), 0) FROM `nx_service_addons` WHERE `service_id` = ? AND `addon_id` = ? AND `status` IN ('pending_payment', 'pending', 'active', 'failed')",
            [$serviceId, $addonId],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $now = $this->clock->nowString();
        if (isset($data['details']) && is_array($data['details'])) {
            $data['details'] = json_encode($data['details'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->db->insert('nx_service_addons', $data + ['created_at' => $now, 'updated_at' => $now]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        if (array_key_exists('details', $data) && is_array($data['details'])) {
            $data['details'] = $data['details'] !== [] ? json_encode($data['details'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        }
        $data['updated_at'] = $this->clock->nowString();
        $this->db->update('nx_service_addons', $data, ['id' => $id]);
    }

    /**
     * Staff queue: add-ons to set up (paid, manual or failed) and to remove.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(): array
    {
        return array_map(self::decodeBooked(...), $this->db->fetchAll(
            "SELECT a.*, s.`name` AS service_name, s.`status` AS service_status, s.`category`, u.`email`
               FROM `nx_service_addons` a JOIN `nx_services` s ON s.`id` = a.`service_id` JOIN `nx_users` u ON u.`id` = a.`user_id`
              WHERE (a.`status` IN ('pending', 'removing') AND a.`fulfilment` = 'manual') OR a.`status` = 'failed'
              ORDER BY FIELD(a.`status`, 'failed', 'pending', 'removing'), a.`id` LIMIT 500",
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function decode(array $row): array
    {
        $row['product_ids'] = array_values(array_filter(array_map('intval', explode(',', (string) ($row['product_ids'] ?? ''))), static fn (int $id): bool => $id > 0));
        $row['categories'] = array_values(array_filter(explode(',', (string) ($row['categories'] ?? ''))));
        $row['is_active'] = (bool) $row['is_active'];

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function decodeBooked(array $row): array
    {
        $details = json_decode((string) ($row['details'] ?? ''), true);
        $row['details'] = is_array($details) ? $details : [];

        return $row;
    }
}
