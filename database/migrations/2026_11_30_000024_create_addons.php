<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Add-ons: extras that staff define (additional IPv4 address, backup
 * storage, priority support, custom) and customers book for a service –
 * with the order or later. Booked add-ons are fulfilled automatically by an
 * add-on provisioner or by staff, renewed with their service and cancelled
 * for the end of the term. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->run("CREATE TABLE IF NOT EXISTS `nx_addons` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `description` TEXT NULL,
            `kind` VARCHAR(30) NOT NULL COMMENT 'ipv4 | backup_storage | priority_support | custom',
            `price` BIGINT NOT NULL DEFAULT 0 COMMENT 'net per month and unit, minor units',
            `setup_fee` BIGINT NOT NULL DEFAULT 0 COMMENT 'net once per unit',
            `currency` CHAR(3) NOT NULL DEFAULT 'EUR',
            `tax_rate_bp` INT UNSIGNED NOT NULL DEFAULT 0,
            `max_quantity` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'per service',
            `product_ids` TEXT NULL COMMENT 'comma-separated products offering it',
            `categories` VARCHAR(255) NULL COMMENT 'comma-separated categories offering it',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_nx_addons_active` (`is_active`, `sort_order`)
        ) {$options}");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_service_addons` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `service_id` BIGINT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `addon_id` INT UNSIGNED NULL,
            `kind` VARCHAR(30) NOT NULL,
            `name` VARCHAR(100) NOT NULL COMMENT 'snapshot at booking',
            `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
            `unit_price` BIGINT NOT NULL COMMENT 'net per month and unit at booking',
            `recurring_amount` BIGINT NOT NULL COMMENT 'net per billing period of the service (after discounts)',
            `tax_rate_bp` INT UNSIGNED NOT NULL DEFAULT 0,
            `currency` CHAR(3) NOT NULL,
            `status` VARCHAR(20) NOT NULL COMMENT 'pending_payment | pending | active | failed | removing | removed | cancelled',
            `fulfilment` VARCHAR(10) NULL COMMENT 'auto | manual',
            `order_item_id` BIGINT UNSIGNED NULL COMMENT 'booked with an order',
            `invoice_id` BIGINT UNSIGNED NULL COMMENT 'booking invoice (booked later)',
            `details` TEXT NULL COMMENT 'JSON: provisioner result or details entered by staff',
            `staff_note` VARCHAR(500) NULL,
            `last_error` TEXT NULL,
            `cancel_at` DATETIME NULL COMMENT 'end of the term after a cancellation',
            `cancelled_at` DATETIME NULL COMMENT 'time the cancellation was received',
            `activated_at` DATETIME NULL,
            `removed_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_service_addons_order_item` (`order_item_id`),
            UNIQUE KEY `uniq_nx_service_addons_invoice` (`invoice_id`),
            KEY `idx_nx_service_addons_service` (`service_id`, `status`),
            KEY `idx_nx_service_addons_status` (`status`),
            KEY `idx_nx_service_addons_user_kind` (`user_id`, `kind`, `status`),
            CONSTRAINT `fk_nx_service_addons_service` FOREIGN KEY (`service_id`) REFERENCES `nx_services` (`id`),
            CONSTRAINT `fk_nx_service_addons_user` FOREIGN KEY (`user_id`) REFERENCES `nx_users` (`id`),
            CONSTRAINT `fk_nx_service_addons_addon` FOREIGN KEY (`addon_id`) REFERENCES `nx_addons` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_nx_service_addons_order_item` FOREIGN KEY (`order_item_id`) REFERENCES `nx_order_items` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_nx_service_addons_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `nx_invoices` (`id`) ON DELETE SET NULL
        ) {$options}");

        $this->addColumn($db, 'nx_order_items', 'addon_id', "INT UNSIGNED NULL COMMENT 'add-on booked with the parent item'");
        $this->addColumn($db, 'nx_order_items', 'parent_item_id', "BIGINT UNSIGNED NULL COMMENT 'order item of the service the add-on belongs to'");
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
