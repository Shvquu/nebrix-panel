<?php

declare(strict_types=1);

namespace Nebrix\Billing\Cart;

use Nebrix\Catalog\ProductCategory;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Security\Encrypter;
use Nebrix\Settings\SettingsRepository;

/**
 * Shopping cart of a signed-in customer (one per customer, stored in the
 * database). An item keeps what the customer chose – product, billing
 * cycle, order options, configurator values and add-ons – but never a
 * price: the cart page and the checkout calculate every price again.
 * Secret options (a transfer auth code) are stored encrypted.
 */
class CartService
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Encrypter $encrypter,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function maxItems(): int
    {
        return max(1, $this->settings->int('orders.cart_max_items') ?: 20);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cart(int $userId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_carts` WHERE `user_id` = ?', [$userId]);
    }

    /**
     * Items of the customer's cart with the options as entered (secrets decrypted).
     *
     * @return list<array{id: int, product_id: int, billing_cycle: string, input: array<string, mixed>, configuration: array<string, int>, addons: array<int, int>}>
     */
    public function items(int $userId): array
    {
        $items = [];
        foreach ($this->db->fetchAll(
            'SELECT i.* FROM `nx_cart_items` i JOIN `nx_carts` c ON c.`id` = i.`cart_id` WHERE c.`user_id` = ? ORDER BY i.`id`',
            [$userId],
        ) as $row) {
            $items[] = $this->decode($row);
        }

        return $items;
    }

    /**
     * @return array{id: int, product_id: int, billing_cycle: string, input: array<string, mixed>, configuration: array<string, int>, addons: array<int, int>}|null
     */
    public function item(int $userId, int $itemId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT i.* FROM `nx_cart_items` i JOIN `nx_carts` c ON c.`id` = i.`cart_id` WHERE c.`user_id` = ? AND i.`id` = ?',
            [$userId, $itemId],
        );

        return $row !== null ? $this->decode($row) : null;
    }

    public function count(int $userId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_cart_items` i JOIN `nx_carts` c ON c.`id` = i.`cart_id` WHERE c.`user_id` = ?', [$userId]);
    }

    /**
     * Adds an item (validated by OrderService::prepareLine() before).
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $input order options as entered
     * @param array<string, int> $configuration configurator values
     * @param array<int, int> $addons add-on ID => quantity
     *
     * @throws CartFullException
     */
    public function add(int $userId, array $product, array $input, array $configuration, string $cycle, array $addons): int
    {
        return $this->db->transaction(function () use ($userId, $product, $input, $configuration, $cycle, $addons): int {
            $cartId = $this->lockCart($userId);
            if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_cart_items` WHERE `cart_id` = ?', [$cartId]) >= $this->maxItems()) {
                throw new CartFullException('Your cart is full. Please order the items in it first.');
            }
            $now = $this->clock->nowString();
            $id = $this->db->insert('nx_cart_items', [
                'cart_id' => $cartId,
                'product_id' => (int) $product['id'],
                'billing_cycle' => $cycle,
                'options' => $this->encodeOptions($product, $input),
                'configuration' => $configuration !== [] ? json_encode($configuration, JSON_THROW_ON_ERROR) : null,
                'addons' => $addons !== [] ? json_encode($addons, JSON_THROW_ON_ERROR) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->db->update('nx_carts', ['updated_at' => $now], ['id' => $cartId]);

            return $id;
        });
    }

    /**
     * Replaces an item's choice (validated before).
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $input
     * @param array<string, int> $configuration
     * @param array<int, int> $addons
     */
    public function update(int $userId, int $itemId, array $product, array $input, array $configuration, string $cycle, array $addons): bool
    {
        $cart = $this->cart($userId);
        $item = $this->item($userId, $itemId);
        if ($cart === null || $item === null || $item['product_id'] !== (int) $product['id']) {
            return false;
        }
        $this->db->update('nx_cart_items', [
            'billing_cycle' => $cycle,
            'options' => $this->encodeOptions($product, $input),
            'configuration' => $configuration !== [] ? json_encode($configuration, JSON_THROW_ON_ERROR) : null,
            'addons' => $addons !== [] ? json_encode($addons, JSON_THROW_ON_ERROR) : null,
            'updated_at' => $this->clock->nowString(),
        ], ['id' => $itemId, 'cart_id' => (int) $cart['id']]);
        $this->touch((int) $cart['id']);

        return true;
    }

    public function remove(int $userId, int $itemId): bool
    {
        $cart = $this->cart($userId);
        if ($cart === null) {
            return false;
        }
        $removed = $this->db->delete('nx_cart_items', ['id' => $itemId, 'cart_id' => (int) $cart['id']]) > 0;
        $this->touch((int) $cart['id']);

        return $removed;
    }

    public function setCoupon(int $userId, ?string $code): void
    {
        $this->db->transaction(function () use ($userId, $code): void {
            $cartId = $this->lockCart($userId);
            $this->db->update('nx_carts', ['coupon_code' => $code, 'updated_at' => $this->clock->nowString()], ['id' => $cartId]);
        });
    }

    /**
     * Empties the cart after the checkout (inside the order transaction):
     * only the items that were ordered, in case another tab added one.
     *
     * @param list<int> $itemIds
     */
    public function clear(int $userId, array $itemIds): void
    {
        $cart = $this->cart($userId);
        if ($cart === null) {
            return;
        }
        foreach ($itemIds as $id) {
            $this->db->delete('nx_cart_items', ['id' => $id, 'cart_id' => (int) $cart['id']]);
        }
        $this->db->update('nx_carts', ['coupon_code' => null, 'updated_at' => $this->clock->nowString()], ['id' => (int) $cart['id']]);
    }

    /** Removes carts that were not touched for the configured number of days. */
    public function prune(): int
    {
        $days = max(1, $this->settings->int('orders.cart_retention_days') ?: 30);
        $cutoff = $this->clock->now()->modify('-' . $days . ' days')->format('Y-m-d H:i:s');

        return $this->db->run('DELETE FROM `nx_carts` WHERE `updated_at` < ?', [$cutoff])->rowCount();
    }

    /** The cart row ID, created on first use and locked. */
    private function lockCart(int $userId): int
    {
        $now = $this->clock->nowString();
        $this->db->run('INSERT IGNORE INTO `nx_carts` (`user_id`, `created_at`, `updated_at`) VALUES (?, ?, ?)', [$userId, $now, $now]);

        return (int) $this->db->fetchValue('SELECT `id` FROM `nx_carts` WHERE `user_id` = ? FOR UPDATE', [$userId]);
    }

    private function touch(int $cartId): void
    {
        $this->db->update('nx_carts', ['updated_at' => $this->clock->nowString()], ['id' => $cartId]);
    }

    /**
     * Only the product's order options are kept; secret ones encrypted.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $input
     */
    private function encodeOptions(array $product, array $input): ?string
    {
        $options = [];
        foreach (ProductCategory::get((string) $product['category'])['options'] as $name => $definition) {
            $value = $input[$name] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            $options[$name] = ($definition['secret'] ?? false) === true ? ['encrypted' => $this->encrypter->encrypt($value, 'cart-option:' . $name)] : $value;
        }

        return $options !== [] ? json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, product_id: int, billing_cycle: string, input: array<string, mixed>, configuration: array<string, int>, addons: array<int, int>}
     */
    private function decode(array $row): array
    {
        $input = [];
        $options = json_decode((string) ($row['options'] ?? ''), true);
        foreach (is_array($options) ? $options : [] as $name => $value) {
            if (is_array($value) && isset($value['encrypted']) && is_string($value['encrypted'])) {
                $value = $this->encrypter->decrypt($value['encrypted'], 'cart-option:' . $name);
            }
            if (is_string($name) && is_string($value)) {
                $input[$name] = $value;
            }
        }
        $configuration = json_decode((string) ($row['configuration'] ?? ''), true);
        $addons = json_decode((string) ($row['addons'] ?? ''), true);

        return [
            'id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'billing_cycle' => (string) $row['billing_cycle'],
            'input' => $input,
            'configuration' => is_array($configuration) ? array_map('intval', array_filter($configuration, 'is_numeric')) : [],
            'addons' => is_array($addons) ? array_map('intval', array_filter($addons, 'is_numeric')) : [],
        ];
    }
}
