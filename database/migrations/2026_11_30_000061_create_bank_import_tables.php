<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Bank statement import: uploaded statements (CAMT.053 or bank CSV), their
 * entries with the matching result, and saved CSV column mappings.
 * Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->run("CREATE TABLE IF NOT EXISTS `nx_bank_imports` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `format` VARCHAR(20) NOT NULL COMMENT 'camt053 or csv',
            `profile` VARCHAR(100) NULL COMMENT 'CSV mapping used',
            `filename` VARCHAR(150) NOT NULL,
            `file_sha256` CHAR(64) NOT NULL,
            `account_iban` VARCHAR(34) NULL,
            `statement_id` VARCHAR(100) NULL,
            `entries` INT UNSIGNED NOT NULL DEFAULT 0,
            `duplicates` INT UNSIGNED NOT NULL DEFAULT 0,
            `matched` INT UNSIGNED NOT NULL DEFAULT 0,
            `review` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_bank_imports_file` (`file_sha256`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_bank_entries` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `import_id` BIGINT UNSIGNED NOT NULL,
            `entry_hash` CHAR(64) NOT NULL COMMENT 'duplicate protection across imports',
            `entry_reference` VARCHAR(100) NULL COMMENT 'bank reference (AcctSvcrRef) if any',
            `booking_date` DATE NOT NULL,
            `value_date` DATE NULL,
            `direction` VARCHAR(6) NOT NULL COMMENT 'credit or debit',
            `amount` BIGINT NOT NULL COMMENT 'minor units, always positive',
            `currency` CHAR(3) NOT NULL,
            `counterparty_name` VARCHAR(140) NULL,
            `counterparty_iban` VARCHAR(34) NULL,
            `remittance` VARCHAR(1000) NULL,
            `status` VARCHAR(20) NOT NULL COMMENT 'matched (booked automatically), assigned, credited, review, ignored, debit',
            `reason` VARCHAR(30) NULL COMMENT 'why it needs a review: partial, overpaid, multiple, paid, cancelled, unknown, currency, reversal',
            `invoice_id` BIGINT UNSIGNED NULL COMMENT 'invoice the payment was booked for (or the suggestion)',
            `transaction_id` BIGINT UNSIGNED NULL,
            `note` VARCHAR(255) NULL,
            `processed_by` BIGINT UNSIGNED NULL,
            `processed_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_bank_entries_hash` (`entry_hash`),
            KEY `idx_nx_bank_entries_status` (`status`, `booking_date`),
            KEY `idx_nx_bank_entries_import` (`import_id`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_bank_csv_profiles` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `mapping` JSON NOT NULL COMMENT 'delimiter, encoding, date format, decimal separator and column names',
            `created_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_bank_csv_profiles_name` (`name`)
        ) $options");
    }
};
