<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Upgrades and downgrades of services: products of the same upgrade group
 * can be switched between, every change is recorded. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $exists = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nx_products' AND COLUMN_NAME = 'upgrade_group'",
        );
        if ($exists === 0) {
            $db->run("ALTER TABLE `nx_products` ADD COLUMN `upgrade_group` VARCHAR(50) NULL COMMENT 'services can switch between products of the same group' AFTER `customer_group_id`, ADD KEY `idx_nx_products_upgrade_group` (`upgrade_group`)");
        }

        $db->run("CREATE TABLE IF NOT EXISTS `nx_service_changes` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `service_id` BIGINT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `from_product_id` INT UNSIGNED NULL,
            `to_product_id` INT UNSIGNED NOT NULL,
            `direction` VARCHAR(10) NOT NULL COMMENT 'upgrade or downgrade',
            `status` VARCHAR(20) NOT NULL COMMENT 'pending_payment, scheduled, queued, completed, failed, cancelled',
            `old_recurring` BIGINT NOT NULL COMMENT 'net per cycle before the change',
            `new_recurring` BIGINT NOT NULL COMMENT 'net per cycle after the change',
            `charge` BIGINT NOT NULL DEFAULT 0 COMMENT 'prorated net amount invoiced for an upgrade',
            `currency` CHAR(3) NOT NULL,
            `invoice_id` BIGINT UNSIGNED NULL,
            `effective_at` DATETIME NULL COMMENT 'downgrades: end of the paid period',
            `requested_by` BIGINT UNSIGNED NULL,
            `error` VARCHAR(500) NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            `completed_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `idx_nx_service_changes_service` (`service_id`, `status`),
            KEY `idx_nx_service_changes_status` (`status`, `effective_at`),
            UNIQUE KEY `uniq_nx_service_changes_invoice` (`invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
