<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Encrypted short-lived cache (e.g. the PayPal access token) and the
 * location evidence of paid invoices (country reported by the payment
 * provider, conflict with the billing country). Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->run("CREATE TABLE IF NOT EXISTS `nx_cache` (
            `cache_key` CHAR(64) NOT NULL COMMENT 'SHA-256 of the key',
            `value` TEXT NOT NULL COMMENT 'encrypted',
            `expires_at` DATETIME NOT NULL,
            PRIMARY KEY (`cache_key`),
            KEY `idx_nx_cache_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addColumn($db, 'nx_invoices', 'payment_country', "CHAR(2) NULL COMMENT 'country reported by the payment provider (card issuer, PayPal account)' AFTER `tax_country`");
        $this->addColumn($db, 'nx_invoices', 'location_conflict', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `payment_country`');
        $exists = (int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nx_invoices' AND INDEX_NAME = 'idx_nx_invoices_location_conflict'");
        if ($exists === 0) {
            $db->run('ALTER TABLE `nx_invoices` ADD KEY `idx_nx_invoices_location_conflict` (`location_conflict`)');
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
