<?php

declare(strict_types=1);

namespace Nebrix\Billing\Sales;

use DateTimeImmutable;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;

/**
 * Global sales ("Black Friday"): a percentage off all products, some
 * categories or selected products for orders placed between start and end.
 * A permanent sale applies to every renewal of the ordered service, the
 * other kind only to the first billing period. When a product has its own
 * promotion as well, the customer gets the higher discount – they never add
 * up.
 */
class SaleService
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_CATEGORIES = 'categories';
    public const SCOPE_PRODUCTS = 'products';

    /** Highest allowed sale: 90 %. */
    public const MAX_BP = 9000;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Sales running at a moment (active, started, not ended).
     *
     * @return list<array<string, mixed>>
     */
    public function running(?DateTimeImmutable $at = null): array
    {
        $now = ($at ?? $this->clock->now())->format('Y-m-d H:i:s');

        return array_map(self::decode(...), $this->db->fetchAll(
            'SELECT * FROM `nx_sales` WHERE `is_active` = 1 AND `starts_at` <= ? AND `ends_at` > ? ORDER BY `discount_bp` DESC, `id`',
            [$now, $now],
        ));
    }

    /**
     * The best sale for a product among the given sales (null = none).
     *
     * @param list<array<string, mixed>> $sales
     * @param array<string, mixed> $product
     * @return array<string, mixed>|null
     */
    public static function bestFor(array $sales, array $product): ?array
    {
        $best = null;
        foreach ($sales as $sale) {
            $applies = match ($sale['scope']) {
                self::SCOPE_ALL => true,
                self::SCOPE_CATEGORIES => in_array((string) ($product['category'] ?? ''), $sale['categories'], true),
                self::SCOPE_PRODUCTS => in_array((int) ($product['id'] ?? 0), $sale['product_ids'], true),
                default => false,
            };
            $bp = (int) $sale['discount_bp'];
            if ($applies && $bp > 0 && $bp <= self::MAX_BP && ($best === null || $bp > (int) $best['discount_bp'] || ($bp === (int) $best['discount_bp'] && $sale['permanent'] && !$best['permanent']))) {
                $best = $sale;
            }
        }

        return $best;
    }

    /**
     * The running sale shown in the site-wide banner (highest discount).
     *
     * @return array<string, mixed>|null
     */
    public function banner(): ?array
    {
        foreach ($this->running() as $sale) {
            if ($sale['show_banner']) {
                return $sale;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(self::decode(...), $this->db->fetchAll(
            'SELECT s.*, (SELECT COUNT(*) FROM `nx_order_items` oi WHERE oi.`sale_id` = s.`id`) AS `orders`
               FROM `nx_sales` s ORDER BY s.`starts_at` DESC, s.`id` DESC LIMIT 200',
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT s.*, (SELECT COUNT(*) FROM `nx_order_items` oi WHERE oi.`sale_id` = s.`id`) AS `orders` FROM `nx_sales` s WHERE s.`id` = ?', [$id]);

        return $row !== null ? self::decode($row) : null;
    }

    /**
     * @param array{name: string, banner_text: string|null, discount_bp: int, permanent: bool, scope: string, categories: list<string>, product_ids: list<int>, starts_at: string, ends_at: string, show_banner: bool, is_active: bool} $data
     */
    public function save(?int $id, array $data, int $actorId): int
    {
        $row = [
            'name' => $data['name'],
            'banner_text' => $data['banner_text'],
            'discount_bp' => $data['discount_bp'],
            'permanent' => $data['permanent'] ? 1 : 0,
            'scope' => $data['scope'],
            'categories' => $data['scope'] === self::SCOPE_CATEGORIES ? implode(',', $data['categories']) : null,
            'product_ids' => $data['scope'] === self::SCOPE_PRODUCTS ? implode(',', $data['product_ids']) : null,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'show_banner' => $data['show_banner'] ? 1 : 0,
            'is_active' => $data['is_active'] ? 1 : 0,
            'updated_at' => $this->clock->nowString(),
        ];
        if ($id === null) {
            return $this->db->insert('nx_sales', $row + ['created_by' => $actorId, 'created_at' => $this->clock->nowString()]);
        }
        $this->db->update('nx_sales', $row, ['id' => $id]);

        return $id;
    }

    /**
     * planned | running | ended | inactive
     *
     * @param array<string, mixed> $sale
     */
    public function state(array $sale): string
    {
        $now = $this->clock->nowString();

        return match (true) {
            !$sale['is_active'] => 'inactive',
            (string) $sale['starts_at'] > $now => 'planned',
            (string) $sale['ends_at'] <= $now => 'ended',
            default => 'running',
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $row['discount_bp'] = (int) $row['discount_bp'];
        $row['percent'] = rtrim(rtrim(number_format($row['discount_bp'] / 100, 2, '.', ''), '0'), '.');
        $row['permanent'] = (bool) $row['permanent'];
        $row['show_banner'] = (bool) $row['show_banner'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['categories'] = array_values(array_filter(explode(',', (string) ($row['categories'] ?? ''))));
        $row['product_ids'] = array_values(array_filter(array_map('intval', explode(',', (string) ($row['product_ids'] ?? '')))));

        return $row;
    }
}
