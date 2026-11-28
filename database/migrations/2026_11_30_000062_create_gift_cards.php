<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Gift cards (credit vouchers): only the SHA-256 hash and the last four
 * characters of a code are stored. Single use; redeeming books account
 * credit. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->run("CREATE TABLE IF NOT EXISTS `nx_gift_cards` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code_hash` CHAR(64) NOT NULL,
            `last4` CHAR(4) NOT NULL,
            `batch` CHAR(16) NOT NULL COMMENT 'codes created together',
            `value` BIGINT UNSIGNED NOT NULL COMMENT 'minor units',
            `currency` CHAR(3) NOT NULL,
            `status` VARCHAR(10) NOT NULL DEFAULT 'active' COMMENT 'active, redeemed, void',
            `source` VARCHAR(10) NOT NULL DEFAULT 'admin' COMMENT 'admin or purchase',
            `invoice_id` BIGINT UNSIGNED NULL COMMENT 'purchase invoice (type gift_card)',
            `purchased_by` BIGINT UNSIGNED NULL,
            `expires_at` DATETIME NULL,
            `note` VARCHAR(255) NULL,
            `redeemed_by` BIGINT UNSIGNED NULL,
            `redeemed_at` DATETIME NULL,
            `voided_by` BIGINT UNSIGNED NULL,
            `voided_at` DATETIME NULL,
            `void_reason` VARCHAR(255) NULL,
            `created_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_gift_cards_code` (`code_hash`),
            UNIQUE KEY `uniq_nx_gift_cards_invoice` (`invoice_id`),
            KEY `idx_nx_gift_cards_batch` (`batch`),
            KEY `idx_nx_gift_cards_status` (`status`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
