<?php

declare(strict_types=1);

namespace Nebrix\Billing\Coupons;

use Nebrix\Auth\User;
use Nebrix\Billing\Money;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;

/**
 * Coupon codes: a percentage or a fixed amount off the first invoice
 * ("once") or off the first invoice and every renewal ("forever").
 *
 * Rules are checked on the server for the price preview and again inside
 * the order transaction with the coupon row locked, so limits (total uses,
 * uses per customer) cannot be exceeded by concurrent orders. Cancelling
 * the unpaid order releases the use again.
 */
class CouponService
{
    public const PERCENT = 'percent';
    public const FIXED = 'fixed';
    public const ONCE = 'once';
    public const FOREVER = 'forever';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $code));
    }

    public static function isValidCode(string $code): bool
    {
        return preg_match('/^[A-Z0-9][A-Z0-9_-]{2,31}$/', $code) === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_coupons` WHERE `id` = ?', [$id]);
    }

    /**
     * Discounts of a code for one product (price preview).
     *
     * @param array<string, mixed> $product
     * @param int $base price after the customer-group discount
     * @return array{coupon: array<string, mixed>, first: int, recurring: int}
     *
     * @throws CouponException with a message for the customer
     */
    public function evaluate(string $code, array $product, User $user, int $base, int $setupFee): array
    {
        $code = self::normalize($code);
        $coupon = self::isValidCode($code) ? $this->db->fetchOne('SELECT * FROM `nx_coupons` WHERE `code` = ?', [$code]) : null;

        return $this->check($coupon, $product, $user, $base, $setupFee, false);
    }

    /**
     * Inside the order transaction: locks the coupon row and checks it again.
     *
     * @param array<string, mixed> $product
     * @return array{coupon: array<string, mixed>, first: int, recurring: int}
     *
     * @throws CouponException
     */
    public function lockAndEvaluate(string $code, array $product, User $user, int $base, int $setupFee): array
    {
        $code = self::normalize($code);
        $coupon = self::isValidCode($code) ? $this->db->fetchOne('SELECT * FROM `nx_coupons` WHERE `code` = ? FOR UPDATE', [$code]) : null;

        return $this->check($coupon, $product, $user, $base, $setupFee, true);
    }

    /** Records the use (same transaction as the order). */
    public function redeem(int $couponId, int $userId, int $orderId, int $discount): void
    {
        $this->db->insert('nx_coupon_redemptions', ['coupon_id' => $couponId, 'user_id' => $userId, 'order_id' => $orderId, 'discount' => $discount, 'created_at' => $this->clock->nowString()]);
        $this->db->run('UPDATE `nx_coupons` SET `uses` = `uses` + 1, `updated_at` = ? WHERE `id` = ?', [$this->clock->nowString(), $couponId]);
    }

    /** The order was cancelled before it was paid: the use counts no longer. */
    public function releaseForOrder(int $orderId): void
    {
        $redemption = $this->db->fetchOne('SELECT `id`, `coupon_id` FROM `nx_coupon_redemptions` WHERE `order_id` = ? FOR UPDATE', [$orderId]);
        if ($redemption === null) {
            return;
        }
        $this->db->run('DELETE FROM `nx_coupon_redemptions` WHERE `id` = ?', [$redemption['id']]);
        $this->db->run('UPDATE `nx_coupons` SET `uses` = GREATEST(`uses`, 1) - 1, `updated_at` = ? WHERE `id` = ?', [$this->clock->nowString(), $redemption['coupon_id']]);
    }

    /**
     * @param array<string, mixed> $data validated admin input
     */
    public function save(?int $id, array $data, int $actorId): int
    {
        $row = [
            'code' => self::normalize((string) $data['code']),
            'description' => ($data['description'] ?? '') !== '' ? mb_substr((string) $data['description'], 0, 255) : null,
            'type' => (string) $data['type'],
            'value' => (int) $data['value'],
            'currency' => $data['type'] === self::FIXED ? (string) $data['currency'] : null,
            'duration' => (string) $data['duration'],
            'product_ids' => ($data['product_ids'] ?? []) !== [] ? implode(',', array_map('intval', (array) $data['product_ids'])) : null,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_until' => $data['valid_until'] ?? null,
            'max_uses' => $data['max_uses'] ?? null,
            'max_uses_per_customer' => (int) ($data['max_uses_per_customer'] ?? 1),
            'new_customers_only' => !empty($data['new_customers_only']) ? 1 : 0,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'updated_at' => $this->clock->nowString(),
        ];
        if ($id === null) {
            return $this->db->insert('nx_coupons', $row + ['created_by' => $actorId, 'created_at' => $this->clock->nowString()]);
        }
        $this->db->update('nx_coupons', $row, ['id' => $id]);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM `nx_coupons` ORDER BY `is_active` DESC, `id` DESC LIMIT 500');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function redemptions(int $couponId): array
    {
        return $this->db->fetchAll(
            'SELECT r.*, o.`number` AS order_number, o.`status` AS order_status, o.`currency`, u.`email` FROM `nx_coupon_redemptions` r
               JOIN `nx_orders` o ON o.`id` = r.`order_id` JOIN `nx_users` u ON u.`id` = r.`user_id`
              WHERE r.`coupon_id` = ? ORDER BY r.`id` DESC LIMIT 200',
            [$couponId],
        );
    }

    /**
     * Discounts of a code for several order lines (cart preview). Percent
     * coupons reduce every line they are valid for; a fixed amount reduces
     * one line – the most expensive one it is valid for.
     *
     * @param array<int|string, array{product: array<string, mixed>, base: int, setup: int}> $lines
     * @return array{coupon: array<string, mixed>, lines: array<int|string, array{first: int, recurring: int}>, first: int, recurring: int}
     *
     * @throws CouponException
     */
    public function evaluateLines(string $code, array $lines, User $user): array
    {
        $code = self::normalize($code);
        $coupon = self::isValidCode($code) ? $this->db->fetchOne('SELECT * FROM `nx_coupons` WHERE `code` = ?', [$code]) : null;

        return $this->checkLines($coupon, $lines, $user, false);
    }

    /**
     * Inside the order transaction (cart checkout): locks the coupon row and checks it again.
     *
     * @param array<int|string, array{product: array<string, mixed>, base: int, setup: int}> $lines
     * @return array{coupon: array<string, mixed>, lines: array<int|string, array{first: int, recurring: int}>, first: int, recurring: int}
     *
     * @throws CouponException
     */
    public function lockAndEvaluateLines(string $code, array $lines, User $user): array
    {
        $code = self::normalize($code);
        $coupon = self::isValidCode($code) ? $this->db->fetchOne('SELECT * FROM `nx_coupons` WHERE `code` = ? FOR UPDATE', [$code]) : null;

        return $this->checkLines($coupon, $lines, $user, true);
    }

    /**
     * @param array<string, mixed>|null $coupon
     * @param array<string, mixed> $product
     * @return array{coupon: array<string, mixed>, first: int, recurring: int}
     */
    private function check(?array $coupon, array $product, User $user, int $base, int $setupFee, bool $locking): array
    {
        $result = $this->checkLines($coupon, [0 => ['product' => $product, 'base' => $base, 'setup' => $setupFee]], $user, $locking);

        return ['coupon' => $result['coupon'], 'first' => $result['lines'][0]['first'] ?? 0, 'recurring' => $result['lines'][0]['recurring'] ?? 0];
    }

    /**
     * @param array<string, mixed> $coupon
     * @param array<string, mixed> $product
     */
    private static function validFor(array $coupon, array $product): bool
    {
        $products = array_filter(array_map('intval', explode(',', (string) $coupon['product_ids'])));

        return ($products === [] || in_array((int) $product['id'], $products, true))
            && ($coupon['type'] !== self::FIXED || strtoupper((string) $coupon['currency']) === strtoupper((string) $product['currency']));
    }

    /**
     * @param array<string, mixed>|null $coupon
     * @param array<int|string, array{product: array<string, mixed>, base: int, setup: int}> $lines
     * @return array{coupon: array<string, mixed>, lines: array<int|string, array{first: int, recurring: int}>, first: int, recurring: int}
     */
    private function checkLines(?array $coupon, array $lines, User $user, bool $locking): array
    {
        // Inside the order transaction the counts are locking reads: they see the latest
        // committed rows, not the transaction's snapshot.
        $lock = $locking ? ' FOR UPDATE' : '';
        if ($coupon === null || !(bool) $coupon['is_active']) {
            throw new CouponException('This coupon code is not valid.');
        }
        $now = $this->clock->nowString();
        if ($coupon['valid_from'] !== null && $now < (string) $coupon['valid_from']) {
            throw new CouponException('This coupon code is not valid yet.');
        }
        if ($coupon['valid_until'] !== null && $now > (string) $coupon['valid_until']) {
            throw new CouponException('This coupon code has expired.');
        }
        if ($coupon['max_uses'] !== null && (int) $coupon['uses'] >= (int) $coupon['max_uses']) {
            throw new CouponException('This coupon code has been used up.');
        }
        $eligible = array_filter($lines, static fn (array $line): bool => self::validFor($coupon, $line['product']));
        if ($eligible === []) {
            throw new CouponException('This coupon code is not valid for this product.');
        }
        $used = (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_coupon_redemptions` WHERE `coupon_id` = ? AND `user_id` = ?' . $lock, [$coupon['id'], $user->id]);
        if ($used >= max(1, (int) $coupon['max_uses_per_customer'])) {
            throw new CouponException('You have already used this coupon code.');
        }
        if ((bool) $coupon['new_customers_only']
            && (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_orders` WHERE `user_id` = ? AND `status` <> 'cancelled'" . $lock, [$user->id]) > 0) {
            throw new CouponException('This coupon code is only valid for new customers.');
        }

        $value = (int) $coupon['value'];
        $forever = $coupon['duration'] === self::FOREVER;
        $result = [];
        if ($coupon['type'] === self::PERCENT) {
            foreach ($eligible as $key => $line) {
                $result[$key] = [
                    'first' => max(0, Money::discount($line['base'] + $line['setup'], $value)),
                    'recurring' => $forever ? max(0, Money::discount($line['base'], $value)) : 0,
                ];
            }
        } else {
            // A fixed amount is granted once per order: on the most expensive line it is valid for.
            $target = array_key_first($eligible);
            foreach ($eligible as $key => $line) {
                if ($line['base'] + $line['setup'] > $eligible[$target]['base'] + $eligible[$target]['setup']) {
                    $target = $key;
                }
            }
            $line = $eligible[$target];
            $result[$target] = [
                'first' => max(0, min($value, $line['base'] + $line['setup'])),
                'recurring' => $forever ? max(0, min($value, $line['base'])) : 0,
            ];
        }

        return [
            'coupon' => $coupon,
            'lines' => $result,
            'first' => array_sum(array_column($result, 'first')),
            'recurring' => array_sum(array_column($result, 'recurring')),
        ];
    }
}
