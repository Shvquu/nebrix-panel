<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Bundles: a set of products or categories that is cheaper together. The
 * best complete bundle of a cart is applied automatically at checkout; the
 * order keeps the bundle and its discount, each item its share. Additive
 * only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->run("CREATE TABLE IF NOT EXISTS `nx_bundles` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `description` VARCHAR(255) NULL,
            `components` TEXT NOT NULL COMMENT 'JSON list of {\"product_id\": n} or {\"category\": \"...\"}',
            `discount_type` VARCHAR(10) NOT NULL DEFAULT 'percent' COMMENT 'percent | fixed',
            `discount_value` BIGINT UNSIGNED NOT NULL COMMENT 'basis points (percent) or minor units (fixed)',
            `currency` CHAR(3) NULL COMMENT 'fixed discounts only',
            `permanent` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = every renewal, 0 = first billing period',
            `starts_at` DATETIME NULL,
            `ends_at` DATETIME NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_nx_bundles_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addColumn($db, 'nx_orders', 'bundle_id', "INT UNSIGNED NULL COMMENT 'bundle applied to the order'");
        $this->addColumn($db, 'nx_orders', 'bundle_name', 'VARCHAR(100) NULL');
        $this->addColumn($db, 'nx_orders', 'bundle_discount', "BIGINT NOT NULL DEFAULT 0 COMMENT 'net bundle discount of the first invoice'");
        $this->addColumn($db, 'nx_order_items', 'bundle_discount', "BIGINT NOT NULL DEFAULT 0 COMMENT 'share of the bundle discount (included in discount)'");
    }

    private function addColumn(Connection $db, string $table, string $column, string $definition): void
    {
        $exists = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );
        if ($exists === 0) {
            $db->run(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
        }
    }
};
