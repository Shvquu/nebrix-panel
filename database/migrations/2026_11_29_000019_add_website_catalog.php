<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Public website catalogue: product lines (tabs on the website),
 * time-limited permanent promotions with the price history required to
 * show the previous lowest price (§ 11 PAngV), the promotion of an order
 * item (kept when a configured service changes its resources) and the
 * configuration of a requested resource change. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $this->addColumn($db, 'nx_products', 'website_line', "VARCHAR(80) NULL COMMENT 'product line = tab on the website' AFTER `upgrade_group`");
        $this->addColumn($db, 'nx_products', 'promo_bp', "INT UNSIGNED NULL COMMENT 'permanent promotion discount in basis points' AFTER `website_line`");
        $this->addColumn($db, 'nx_products', 'promo_starts_at', 'DATETIME NULL AFTER `promo_bp`');
        $this->addColumn($db, 'nx_products', 'promo_ends_at', 'DATETIME NULL AFTER `promo_starts_at`');
        $this->addColumn($db, 'nx_order_items', 'promo_bp', "INT UNSIGNED NULL COMMENT 'promotion applied to this item (kept for its renewals)' AFTER `recurring_discount`");
        $this->addColumn($db, 'nx_service_changes', 'configuration', "TEXT NULL COMMENT 'JSON: new resources of a configured service' AFTER `to_product_id`");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_product_price_history` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id` INT UNSIGNED NOT NULL,
            `price` BIGINT NOT NULL COMMENT 'net list price in minor units from recorded_at on',
            `recorded_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_nx_product_price_history` (`product_id`, `recorded_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Starting point: the current prices (earlier changes are not known).
        if ((int) $db->fetchValue('SELECT COUNT(*) FROM `nx_product_price_history`') === 0) {
            $db->run('INSERT INTO `nx_product_price_history` (`product_id`, `price`, `recorded_at`) SELECT `id`, `price`, `updated_at` FROM `nx_products`');
        }
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
