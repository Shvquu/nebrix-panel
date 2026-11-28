<?php

declare(strict_types=1);

namespace Nebrix\Domain;

use Nebrix\Audit\AuditLogger;
use Nebrix\Billing\Money;
use Nebrix\Catalog\ProductCategory;
use Nebrix\Database\Connection;
use Nebrix\Integration\Integration;
use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\IntegrationManager;
use Nebrix\Integration\Skrime\SkrimeClient;
use Nebrix\Repository\ProductRepository;

/**
 * Creates (and optionally re-prices) one domain product per TLD from the
 * SKRIME price list, so that staff do not enter hundreds of TLDs by hand.
 *
 * Three selling prices per ending, each SKRIME's cost plus the markup,
 * rounded, at least the minimum price: the renewal (product price), the
 * first year of a registration and the first year of a transfer. A
 * transfer is priced from the higher of transfer and renewal cost, because
 * transfers that do not extend the term (e.g. .de) need a renewal within
 * the paid year. Nothing is ever deleted: TLDs that disappear from the list
 * keep their product; existing services keep their price.
 */
final class TldImporter
{
    /** Shown first in the store. */
    private const POPULAR = ['de', 'com', 'net', 'org', 'eu', 'at', 'ch', 'info', 'io', 'shop', 'online', 'store', 'app', 'dev', 'cloud', 'me', 'biz', 'xyz', 'co.uk', 'nl', 'fr', 'it', 'es', 'pl', 'be'];

    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly ProductRepository $products,
        private readonly Connection $db,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array{markup_percent: float, markup_fixed: int, rounding: string, min_price: int, max_cost: int, tlds: list<string>, tax_rate_bp: int, visible: bool, update_prices: bool} $options
     * @return array{rows: list<array{tld: string, create: int, renew: int, transfer: int, offer: bool, cost: int, price: int, register_price: int, transfer_price: int, action: string, product_id: int|null, current_price: int|null, reason: string|null}>, counts: array<string, int>}
     *
     * @throws IntegrationException
     */
    public function preview(Integration $integration, array $options): array
    {
        $registrar = $this->integrations->registrar($integration);
        if (!$registrar instanceof SkrimeClient) {
            throw new IntegrationException('TLDs can only be imported from a SKRIME integration.');
        }
        $wanted = array_flip(array_map(static fn (string $t): string => ltrim(mb_strtolower(trim($t)), '.'), $options['tlds']));
        $existing = $this->existingProducts();
        $slugs = array_flip(array_map('strval', array_column($this->db->fetchAll('SELECT `slug` FROM `nx_products`'), 'slug')));
        $rows = [];
        $counts = ['create' => 0, 'update' => 0, 'keep' => 0, 'skip' => 0];
        foreach ($registrar->pricelist() as $entry) {
            if ($wanted !== [] && !isset($wanted[$entry['tld']])) {
                continue;
            }
            $create = self::cents($entry['create']);
            $renew = self::cents($entry['renew']);
            $transfer = self::cents($entry['transfer']);
            $cost = max($create, $renew, $transfer);
            $price = self::sellingPrice($renew, $options);
            $registerPrice = self::sellingPrice($create, $options);
            $transferPrice = self::sellingPrice(max($transfer, $renew), $options);
            $current = $existing[$entry['tld']] ?? null;
            $changed = $current !== null && ((int) $current['price'] !== $price
                || self::configuredPrice($current, 'register_price') !== ($registerPrice !== $price ? $registerPrice : null)
                || self::configuredPrice($current, 'transfer_price') !== ($transferPrice !== $price ? $transferPrice : null));
            [$action, $reason] = match (true) {
                $renew <= 0 || $create <= 0 => ['skip', 'no price'],
                $options['max_cost'] > 0 && $cost > $options['max_cost'] => ['skip', 'above the maximum cost'],
                $current !== null && (int) $current['integration_id'] !== $integration->id => ['skip', 'sold through another integration'],
                $current === null && isset($slugs[self::slug($entry['tld'])]) => ['skip', 'product address taken'],
                $current === null => ['create', null],
                $options['update_prices'] && $changed => ['update', null],
                default => ['keep', null],
            };
            $counts[$action]++;
            $rows[] = [
                'tld' => $entry['tld'], 'create' => $create, 'renew' => $renew, 'transfer' => $transfer, 'offer' => $entry['offer'],
                'cost' => $cost, 'price' => $price, 'register_price' => $registerPrice, 'transfer_price' => $transferPrice, 'action' => $action, 'product_id' => $current !== null ? (int) $current['id'] : null,
                'current_price' => $current !== null ? (int) $current['price'] : null, 'reason' => $reason,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [self::rank($a['tld']), $a['tld']] <=> [self::rank($b['tld']), $b['tld']]);

        return ['rows' => $rows, 'counts' => $counts];
    }

    /**
     * @param array{markup_percent: float, markup_fixed: int, rounding: string, min_price: int, max_cost: int, tlds: list<string>, tax_rate_bp: int, visible: bool, update_prices: bool} $options
     * @return array<string, int> created, updated, kept, skipped
     *
     * @throws IntegrationException
     */
    public function apply(Integration $integration, array $options, int $actorId, ?string $ip): array
    {
        $preview = $this->preview($integration, $options);
        $created = $updated = 0;
        $this->db->transaction(function () use ($preview, $integration, $options, &$created, &$updated): void {
            foreach ($preview['rows'] as $row) {
                if ($row['action'] === 'create') {
                    $this->products->create([
                        'category' => ProductCategory::DOMAIN,
                        'name' => '.' . $row['tld'],
                        'slug' => self::slug($row['tld']),
                        'description' => null,
                        'price' => $row['price'],
                        'setup_fee' => 0,
                        'currency' => 'EUR',
                        'billing_cycle' => 'annually',
                        'tax_rate_bp' => $options['tax_rate_bp'],
                        'is_visible' => $options['visible'] ? 1 : 0,
                        'is_active' => 1,
                        'auto_provision' => 1,
                        'integration_id' => $integration->id,
                        'sort_order' => self::rank($row['tld']),
                        'provisioning_config' => ['tld' => $row['tld']] + self::firstYearPrices($row),
                    ]);
                    $created++;
                } elseif ($row['action'] === 'update' && $row['product_id'] !== null) {
                    $product = $this->products->find($row['product_id']);
                    $config = is_array($product['provisioning_config'] ?? null) ? $product['provisioning_config'] : [];
                    unset($config['register_price'], $config['transfer_price']);
                    $this->products->update($row['product_id'], ['price' => $row['price'], 'provisioning_config' => $config + self::firstYearPrices($row)]);
                    $updated++;
                }
            }
        });
        $result = ['created' => $created, 'updated' => $updated, 'kept' => $preview['counts']['keep'], 'skipped' => $preview['counts']['skip']];
        $this->audit->log($actorId, 'products.tlds_imported', 'integration', $integration->id, $result + ['markup_percent' => $options['markup_percent'], 'markup_fixed' => $options['markup_fixed']], $ip);

        return $result;
    }

    /**
     * @param array{markup_percent: float, markup_fixed: int, rounding: string, min_price: int} $options
     */
    public static function sellingPrice(int $cost, array $options): int
    {
        $price = (int) ceil($cost * (1 + max(0.0, $options['markup_percent']) / 100) + max(0, $options['markup_fixed']));
        $price = match ($options['rounding']) {
            '99' => (int) (ceil(($price + 1) / 100) * 100) - 1,        // 3.74 → 3.99
            '49' => (int) (ceil(($price + 1) / 50) * 50) - 1,          // 3.74 → 3.99, 3.40 → 3.49
            default => $price,
        };

        return max($price, $options['min_price'], $cost);
    }

    /**
     * First-year prices that differ from the renewal price (product config, decimal strings).
     *
     * @param array{price: int, register_price: int, transfer_price: int} $row
     * @return array<string, string>
     */
    private static function firstYearPrices(array $row): array
    {
        $prices = [];
        foreach (['register_price', 'transfer_price'] as $key) {
            if ($row[$key] !== $row['price']) {
                $prices[$key] = Money::toDecimal($row[$key]);
            }
        }

        return $prices;
    }

    /**
     * @param array<string, mixed> $product row with provisioning_config (JSON)
     */
    private static function configuredPrice(array $product, string $key): ?int
    {
        $config = json_decode((string) ($product['provisioning_config'] ?? ''), true);
        $value = is_array($config) ? trim((string) ($config[$key] ?? '')) : '';

        return $value !== '' && preg_match('/^\d{1,7}([.,]\d{1,2})?$/', $value) === 1 ? Money::parse($value) : null;
    }

    public static function slug(string $tld): string
    {
        return 'domain-' . str_replace('.', '-', $tld);
    }

    private static function cents(string $value): int
    {
        return (int) round((float) $value * 100);
    }

    private static function rank(string $tld): int
    {
        $position = array_search($tld, self::POPULAR, true);

        return $position === false ? 1000 : (int) $position;
    }

    /**
     * Domain products by TLD (one per TLD; the first one wins).
     *
     * @return array<string, array<string, mixed>>
     */
    private function existingProducts(): array
    {
        $byTld = [];
        foreach ($this->db->fetchAll("SELECT `id`, `price`, `integration_id`, `provisioning_config` FROM `nx_products` WHERE `category` = 'domain' ORDER BY `id`") as $row) {
            $config = json_decode((string) $row['provisioning_config'], true);
            $tld = is_array($config) ? ltrim(mb_strtolower((string) ($config['tld'] ?? '')), '.') : '';
            if ($tld !== '' && !isset($byTld[$tld])) {
                $byTld[$tld] = $row;
            }
        }

        return $byTld;
    }
}
