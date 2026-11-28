<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Several billing cycles per product: the additional cycles a product
 * offers with their discount, the price history per additional cycle
 * (previous lowest price, § 11 PAngV) and a billing cycle chosen by the
 * customer for the next term of a service. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $this->addColumn($db, 'nx_products', 'billing_cycles', "TEXT NULL COMMENT 'JSON: additional billing cycles => discount in basis points' AFTER `billing_cycle`");
        $this->addColumn($db, 'nx_product_price_history', 'billing_cycle', "VARCHAR(20) NULL COMMENT 'NULL = price of the product billing cycle, else of this additional cycle' AFTER `price`");
        $this->addColumn($db, 'nx_services', 'next_billing_cycle', "VARCHAR(20) NULL COMMENT 'billing cycle chosen for the next term' AFTER `billing_cycle`");
        $this->addColumn($db, 'nx_services', 'next_recurring_amount', "BIGINT NULL COMMENT 'net price per period in next_billing_cycle' AFTER `next_billing_cycle`");

        $index = (int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nx_product_price_history' AND INDEX_NAME = 'idx_nx_product_price_history_cycle'");
        if ($index === 0) {
            $db->run('ALTER TABLE `nx_product_price_history` ADD KEY `idx_nx_product_price_history_cycle` (`product_id`, `billing_cycle`, `recorded_at`)');
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
