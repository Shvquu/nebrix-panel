<?php

declare(strict_types=1);

namespace Nebrix\Billing\Bundles;

use Nebrix\Billing\Money;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;

/**
 * Bundles: products or categories that are cheaper together ("server +
 * domain"). When an order contains every component of a running bundle,
 * the bundle is applied automatically – at most one per order, the one that
 * saves the customer the most.
 *
 * A bundle never adds up with a product promotion or a global sale on the
 * same items (like promotions and sales among each other): it is only
 * applied when its discount is higher than the promotions of the items it
 * covers, and then replaces them on these items. Customer-group discounts
 * and coupons apply on top, as they do on top of promotions.
 *
 * The discount is a percentage of the list prices of the covered items or
 * a fixed amount split over them in proportion to their prices. Permanent
 * bundles also lower every renewal of the covered services, the others the
 * first billing period only.
 */
class BundleService
{
    public const PERCENT = 'percent';
    public const FIXED = 'fixed';

    /** Highest allowed percentage: 90 %. */
    public const MAX_BP = 9000;

    /** Most components per bundle. */
    public const MAX_COMPONENTS = 5;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Bundles that apply to orders placed now.
     *
     * @return list<array<string, mixed>>
     */
    public function running(): array
    {
        $now = $this->clock->nowString();

        return array_map(self::decode(...), $this->db->fetchAll(
            'SELECT * FROM `nx_bundles` WHERE `is_active` = 1 AND (`starts_at` IS NULL OR `starts_at` <= ?) AND (`ends_at` IS NULL OR `ends_at` > ?) ORDER BY `id`',
            [$now, $now],
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(self::decode(...), $this->db->fetchAll(
            'SELECT b.*, (SELECT COUNT(*) FROM `nx_orders` o WHERE o.`bundle_id` = b.`id`) AS `orders` FROM `nx_bundles` b ORDER BY b.`is_active` DESC, b.`id` DESC LIMIT 200',
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT b.*, (SELECT COUNT(*) FROM `nx_orders` o WHERE o.`bundle_id` = b.`id`) AS `orders` FROM `nx_bundles` b WHERE b.`id` = ?', [$id]);

        return $row !== null ? self::decode($row) : null;
    }

    /**
     * @param array{name: string, description: string|null, components: list<array{product_id?: int, category?: string}>, discount_type: string, discount_value: int, currency: string|null, permanent: bool, starts_at: string|null, ends_at: string|null, is_active: bool} $data
     */
    public function save(?int $id, array $data, int $actorId): int
    {
        $row = [
            'name' => $data['name'],
            'description' => $data['description'],
            'components' => json_encode(array_values($data['components']), JSON_THROW_ON_ERROR),
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'currency' => $data['discount_type'] === self::FIXED ? $data['currency'] : null,
            'permanent' => $data['permanent'] ? 1 : 0,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'is_active' => $data['is_active'] ? 1 : 0,
            'updated_at' => $this->clock->nowString(),
        ];
        if ($id === null) {
            return $this->db->insert('nx_bundles', $row + ['created_by' => $actorId, 'created_at' => $this->clock->nowString()]);
        }
        $this->db->update('nx_bundles', $row, ['id' => $id]);

        return $id;
    }

    /**
     * planned | running | ended | inactive
     *
     * @param array<string, mixed> $bundle
     */
    public function state(array $bundle): string
    {
        $now = $this->clock->nowString();

        return match (true) {
            !$bundle['is_active'] => 'inactive',
            $bundle['starts_at'] !== null && (string) $bundle['starts_at'] > $now => 'planned',
            $bundle['ends_at'] !== null && (string) $bundle['ends_at'] <= $now => 'ended',
            default => 'running',
        };
    }

    /**
     * The bundle that saves the most among the given bundles, or null.
     *
     * @param list<array<string, mixed>> $bundles running bundles
     * @param array<int|string, array{product: array<string, mixed>, list: int, promo: int}> $lines order lines: list price of the first period and its promotions
     * @return array{bundle: array<string, mixed>, shares: array<int|string, int>, total: int, replaced: int}|null
     */
    public static function best(array $bundles, array $lines, string $currency): ?array
    {
        $best = null;
        foreach ($bundles as $bundle) {
            $result = self::evaluate($bundle, $lines, $currency);
            if ($result !== null && ($best === null || $result['total'] - $result['replaced'] > $best['total'] - $best['replaced'])) {
                $best = $result;
            }
        }

        return $best;
    }

    /**
     * Applies one bundle to the lines: every component needs its own line
     * (product components are matched first, then categories, each with the
     * line that saves the most). Null when the bundle is incomplete or its
     * discount is not higher than the promotions it would replace.
     *
     * @param array<string, mixed> $bundle
     * @param array<int|string, array{product: array<string, mixed>, list: int, promo: int}> $lines
     * @return array{bundle: array<string, mixed>, shares: array<int|string, int>, total: int, replaced: int}|null
     */
    public static function evaluate(array $bundle, array $lines, string $currency): ?array
    {
        $components = $bundle['components'];
        if ($components === [] || count($components) > self::MAX_COMPONENTS) {
            return null;
        }
        $percent = $bundle['discount_type'] === self::PERCENT;
        $value = (int) $bundle['discount_value'];
        if ($value <= 0 || ($percent && $value > self::MAX_BP) || (!$percent && strtoupper((string) $bundle['currency']) !== strtoupper($currency))) {
            return null;
        }
        usort($components, static fn (array $a, array $b): int => (isset($a['product_id']) ? 0 : 1) <=> (isset($b['product_id']) ? 0 : 1));

        $matched = [];
        foreach ($components as $component) {
            $choice = null;
            $choiceValue = PHP_INT_MIN;
            foreach ($lines as $key => $line) {
                if (isset($matched[$key]) || strtoupper((string) $line['product']['currency']) !== strtoupper($currency)) {
                    continue;
                }
                $fits = isset($component['product_id'])
                    ? (int) $line['product']['id'] === (int) $component['product_id']
                    : (string) $line['product']['category'] === (string) ($component['category'] ?? '');
                if (!$fits) {
                    continue;
                }
                $gain = ($percent ? Money::discount($line['list'], $value) : $line['list']) - $line['promo'];
                if ($gain > $choiceValue) {
                    [$choice, $choiceValue] = [$key, $gain];
                }
            }
            if ($choice === null) {
                return null;
            }
            $matched[$choice] = true;
        }

        $shares = [];
        $sum = 0;
        foreach (array_keys($matched) as $key) {
            $sum += $lines[$key]['list'];
        }
        if ($sum <= 0) {
            return null;
        }
        if ($percent) {
            foreach (array_keys($matched) as $key) {
                $shares[$key] = Money::discount($lines[$key]['list'], $value);
            }
        } else {
            // A fixed amount: split in proportion to the prices, the rounding rest on the most expensive line.
            $total = min($value, $sum);
            $largest = null;
            foreach (array_keys($matched) as $key) {
                $shares[$key] = intdiv($total * $lines[$key]['list'], $sum);
                if ($largest === null || $lines[$key]['list'] > $lines[$largest]['list']) {
                    $largest = $key;
                }
            }
            $shares[$largest] += $total - array_sum($shares);
        }
        $replaced = 0;
        foreach (array_keys($matched) as $key) {
            $replaced += $lines[$key]['promo'];
        }
        $total = array_sum($shares);
        if ($total <= $replaced) {
            return null; // the promotions of these items are worth more: they stay
        }

        return ['bundle' => $bundle, 'shares' => $shares, 'total' => $total, 'replaced' => $replaced];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $components = json_decode((string) ($row['components'] ?? ''), true);
        $row['components'] = [];
        foreach (is_array($components) ? $components : [] as $component) {
            if (is_array($component) && isset($component['product_id']) && is_numeric($component['product_id'])) {
                $row['components'][] = ['product_id' => (int) $component['product_id']];
            } elseif (is_array($component) && isset($component['category']) && is_string($component['category'])) {
                $row['components'][] = ['category' => $component['category']];
            }
        }
        $row['discount_value'] = (int) $row['discount_value'];
        $row['permanent'] = (bool) $row['permanent'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['percent'] = $row['discount_type'] === self::PERCENT ? rtrim(rtrim(number_format($row['discount_value'] / 100, 2, '.', ''), '0'), '.') : null;

        return $row;
    }
}
