<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Global sales: time-limited discounts for all products, categories or
 * selected products (permanent or for the first billing period), and the
 * sale an order item was bought in. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->run("CREATE TABLE IF NOT EXISTS `nx_sales` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `banner_text` VARCHAR(255) NULL,
            `discount_bp` INT UNSIGNED NOT NULL COMMENT 'basis points (2500 = 25 %)',
            `permanent` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = whole term, 0 = first billing period',
            `scope` VARCHAR(20) NOT NULL DEFAULT 'all' COMMENT 'all | categories | products',
            `categories` VARCHAR(255) NULL COMMENT 'comma-separated categories',
            `product_ids` TEXT NULL COMMENT 'comma-separated product IDs',
            `starts_at` DATETIME NOT NULL,
            `ends_at` DATETIME NOT NULL,
            `show_banner` TINYINT(1) NOT NULL DEFAULT 1,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_nx_sales_running` (`is_active`, `starts_at`, `ends_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $exists = (int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nx_order_items' AND COLUMN_NAME = 'sale_id'");
        if ($exists === 0) {
            $db->run("ALTER TABLE `nx_order_items` ADD COLUMN `sale_id` INT UNSIGNED NULL COMMENT 'sale the item was bought in' AFTER `promo_bp`, ADD KEY `idx_nx_order_items_sale` (`sale_id`)");
        }
    }
};
