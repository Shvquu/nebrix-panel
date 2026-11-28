<?php

declare(strict_types=1);

namespace Nebrix\Catalog;

use DateTimeImmutable;
use DateTimeZone;
use Nebrix\Billing\Money;

/**
 * Price rules of a product beyond its list price:
 *
 * - **Promotion**: a percentage discount for orders placed between start
 *   and end. It is permanent – it applies to every renewal of the ordered
 *   service ("for the whole term"). Previous lowest price: see
 *   ProductRepository::lowestPriceBefore() (§ 11 PAngV).
 * - **Domain first year**: optional net prices of the first year for new
 *   registrations and for transfers; renewals cost the list price.
 */
final class ProductPricing
{
    /** Highest allowed promotion: 90 %. */
    public const MAX_PROMO_BP = 9000;

    /** Highest allowed discount of an additional billing cycle: 90 %. */
    public const MAX_CYCLE_BP = 9000;

    /**
     * Billing cycles a product offers, ordered by length: cycle => discount
     * in basis points. The product's own billing cycle (the default) is
     * always offered without discount; its price is the product price.
     * Every other cycle costs the product price scaled to its length
     * (e.g. × 12 for annually when the default is monthly) less its
     * discount. Domains and one-time products have their single cycle only.
     *
     * @param array<string, mixed> $product
     * @return array<string, int>
     */
    public static function cycles(array $product): array
    {
        $default = (string) ($product['billing_cycle'] ?? BillingCycle::MONTHLY);
        if (!BillingCycle::isRecurring($default) || ($product['category'] ?? null) === ProductCategory::DOMAIN) {
            return [$default => 0];
        }
        $extra = is_array($product['billing_cycles'] ?? null) ? $product['billing_cycles'] : [];
        $cycles = [];
        foreach (array_keys(BillingCycle::labels()) as $cycle) {
            if ($cycle === $default) {
                $cycles[$cycle] = 0;
            } elseif (BillingCycle::isRecurring($cycle) && array_key_exists($cycle, $extra) && is_numeric($extra[$cycle])) {
                $cycles[$cycle] = max(0, min(self::MAX_CYCLE_BP, (int) $extra[$cycle]));
            }
        }
        uksort($cycles, static fn (string $a, string $b): int => BillingCycle::months($a) <=> BillingCycle::months($b));

        return $cycles;
    }

    /**
     * @param array<string, mixed> $product
     */
    public static function offersCycle(array $product, string $cycle): bool
    {
        return array_key_exists($cycle, self::cycles($product));
    }

    /**
     * Net list price of one billing period in a cycle the product offers.
     *
     * @param array<string, mixed> $product
     * @param int|null $basePrice price in the product's own cycle (configured price of the configurator; default: product price)
     */
    public static function cyclePrice(array $product, string $cycle, ?int $basePrice = null): int
    {
        $cycles = self::cycles($product);
        if (!array_key_exists($cycle, $cycles)) {
            throw new \InvalidArgumentException(sprintf('The product does not offer the billing cycle "%s".', $cycle));
        }

        return self::scale($basePrice ?? (int) $product['price'], (string) $product['billing_cycle'], $cycle, $cycles[$cycle]);
    }

    /**
     * Price of the product's own cycle scaled to another cycle (rounded
     * half up to minor units) less a discount in basis points.
     */
    public static function scale(int $price, string $from, string $to, int $discountBp = 0): int
    {
        $scaled = self::undiscounted($price, $from, $to);

        return $scaled - Money::discount($scaled, $discountBp);
    }

    /** The price scaled to another cycle without its discount (the reference for "you save"). */
    public static function undiscounted(int $price, string $from, string $to): int
    {
        $fromMonths = BillingCycle::months($from);
        $toMonths = BillingCycle::months($to);
        if ($from === $to || $fromMonths <= 0 || $toMonths <= 0) {
            return $price;
        }

        return intdiv($price * $toMonths * 2 + $fromMonths, 2 * $fromMonths);
    }

    /**
     * Discount of the running promotion in basis points (0 = none).
     *
     * @param array<string, mixed> $product
     */
    public static function promotionBp(array $product, DateTimeImmutable $now): int
    {
        $bp = (int) ($product['promo_bp'] ?? 0);
        $end = (string) ($product['promo_ends_at'] ?? '');
        if ($bp <= 0 || $bp > self::MAX_PROMO_BP || $end === '') {
            return 0;
        }
        $utc = new DateTimeZone('UTC');
        $start = (string) ($product['promo_starts_at'] ?? '');
        if ($now >= new DateTimeImmutable($end, $utc) || ($start !== '' && $now < new DateTimeImmutable($start, $utc))) {
            return 0;
        }

        return $bp;
    }

    /**
     * Discounts of a product with an optional global sale: the higher one
     * wins, they never add up. "permanent_bp" applies to every renewal,
     * "first_bp" (≥ permanent_bp) to the first billing period.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed>|null $sale a running sale for this product (SaleService::bestFor)
     * @return array{permanent_bp: int, first_bp: int, sale: array<string, mixed>|null, starts_at: string|null, ends_at: string|null}
     */
    public static function discounts(array $product, ?array $sale, DateTimeImmutable $now): array
    {
        $productBp = self::promotionBp($product, $now);
        $saleBp = $sale !== null ? min((int) $sale['discount_bp'], self::MAX_PROMO_BP) : 0;
        $permanent = max($productBp, $sale !== null && (bool) $sale['permanent'] ? $saleBp : 0);
        $useSale = $saleBp > $productBp;

        return [
            'permanent_bp' => $permanent,
            'first_bp' => max($permanent, $saleBp),
            'sale' => $useSale ? $sale : null,
            'starts_at' => $useSale ? (string) $sale['starts_at'] : ($productBp > 0 && (string) ($product['promo_starts_at'] ?? '') !== '' ? (string) $product['promo_starts_at'] : null),
            'ends_at' => $useSale ? (string) $sale['ends_at'] : ($productBp > 0 ? (string) $product['promo_ends_at'] : null),
        ];
    }

    /**
     * Net price of the first year of a domain (registration or transfer), or
     * null when the list price applies.
     *
     * @param array<string, mixed> $product
     */
    public static function domainFirstYear(array $product, bool $transfer): ?int
    {
        if (($product['category'] ?? null) !== ProductCategory::DOMAIN) {
            return null;
        }
        $config = is_array($product['provisioning_config'] ?? null) ? $product['provisioning_config'] : [];
        $value = trim((string) ($config[$transfer ? 'transfer_price' : 'register_price'] ?? ''));
        if ($value === '' || preg_match('/^\d{1,5}([.,]\d{1,2})?$/', $value) !== 1) {
            return null;
        }
        try {
            return Money::parse($value, (string) ($product['currency'] ?? 'EUR'));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
