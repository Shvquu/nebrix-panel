<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Shopping cart of signed-in customers: one cart per customer with its
 * items (product, billing cycle, options, configuration, add-ons). Prices
 * are never stored in the cart – they are calculated again at checkout.
 * Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->run("CREATE TABLE IF NOT EXISTS `nx_carts` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `coupon_code` VARCHAR(40) NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_carts_user` (`user_id`),
            KEY `idx_nx_carts_updated` (`updated_at`),
            CONSTRAINT `fk_nx_carts_user` FOREIGN KEY (`user_id`) REFERENCES `nx_users` (`id`) ON DELETE CASCADE
        ) {$options}");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_cart_items` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `cart_id` BIGINT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED NOT NULL,
            `billing_cycle` VARCHAR(20) NOT NULL,
            `options` TEXT NULL COMMENT 'JSON: order options as entered (secret values encrypted)',
            `configuration` TEXT NULL COMMENT 'JSON: configurator choice',
            `addons` TEXT NULL COMMENT 'JSON: add-on ID => quantity',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_nx_cart_items_cart` (`cart_id`),
            CONSTRAINT `fk_nx_cart_items_cart` FOREIGN KEY (`cart_id`) REFERENCES `nx_carts` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_nx_cart_items_product` FOREIGN KEY (`product_id`) REFERENCES `nx_products` (`id`) ON DELETE CASCADE
        ) {$options}");
    }
};
