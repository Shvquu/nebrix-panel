<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Finance area: transfer status of invoices to external accounting software
 * (lexoffice) and the new finance permissions for the default staff roles.
 * Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->run("CREATE TABLE IF NOT EXISTS `nx_accounting_sync` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `provider` VARCHAR(20) NOT NULL COMMENT 'lexoffice',
            `invoice_id` BIGINT UNSIGNED NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, creating, created, synced, failed',
            `external_id` VARCHAR(64) NULL COMMENT 'ID of the voucher at the provider',
            `file_uploaded` TINYINT(1) NOT NULL DEFAULT 0,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_error` VARCHAR(500) NULL,
            `synced_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_accounting_sync_invoice` (`provider`, `invoice_id`),
            KEY `idx_nx_accounting_sync_status` (`provider`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $grants = [
            'admin' => ['accounting.export', 'bank.import', 'gift_cards.manage', 'partners.manage', 'finance.settings'],
            'finance' => ['accounting.export', 'bank.import', 'gift_cards.manage', 'partners.manage'],
        ];
        foreach ($grants as $slug => $permissions) {
            $role = $db->fetchValue('SELECT `id` FROM `nx_roles` WHERE `slug` = ?', [$slug]);
            if ($role === null) {
                continue;
            }
            foreach ($permissions as $permission) {
                $db->run('INSERT IGNORE INTO `nx_role_permissions` (`role_id`, `permission`) VALUES (?, ?)', [(int) $role, $permission]);
            }
        }
    }
};
