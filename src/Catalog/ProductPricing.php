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
