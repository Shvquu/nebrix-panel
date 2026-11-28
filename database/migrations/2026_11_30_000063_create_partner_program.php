<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Partner program (cash commissions for referrals): partners with their
 * consent and encrypted payout details, commission entries and payouts.
 * Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->run("CREATE TABLE IF NOT EXISTS `nx_partners` (
            `user_id` BIGINT UNSIGNED NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active, suspended, ended',
            `terms_version` VARCHAR(50) NULL,
            `consented_at` DATETIME NOT NULL,
            `consent_ip` VARCHAR(45) NULL,
            `iban_encrypted` TEXT NULL,
            `holder_encrypted` TEXT NULL,
            `bic` VARCHAR(11) NULL,
            `iban_last4` CHAR(4) NULL,
            `details_updated_at` DATETIME NULL,
            `note` VARCHAR(255) NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`user_id`),
            KEY `idx_nx_partners_status` (`status`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_partner_commissions` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `partner_id` BIGINT UNSIGNED NOT NULL,
            `referral_id` BIGINT UNSIGNED NOT NULL,
            `invoice_id` BIGINT UNSIGNED NOT NULL,
            `kind` VARCHAR(10) NOT NULL DEFAULT 'accrual' COMMENT 'accrual or adjustment (reversal of a refunded share)',
            `accrual_key` VARCHAR(40) NULL COMMENT 'one accrual per invoice',
            `basis` BIGINT NOT NULL COMMENT 'net invoice amount the commission is based on',
            `rate_bp` INT UNSIGNED NOT NULL,
            `amount` BIGINT NOT NULL COMMENT 'commission, negative for adjustments',
            `currency` CHAR(3) NOT NULL,
            `status` VARCHAR(10) NOT NULL DEFAULT 'pending' COMMENT 'pending, payable, requested, paid, reversed',
            `payable_at` DATETIME NOT NULL,
            `payout_id` BIGINT UNSIGNED NULL,
            `note` VARCHAR(255) NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_partner_commissions_accrual` (`accrual_key`),
            KEY `idx_nx_partner_commissions_partner` (`partner_id`, `status`),
            KEY `idx_nx_partner_commissions_invoice` (`invoice_id`),
            KEY `idx_nx_partner_commissions_payout` (`payout_id`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_partner_payouts` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `partner_id` BIGINT UNSIGNED NOT NULL,
            `amount` BIGINT NOT NULL,
            `currency` CHAR(3) NOT NULL,
            `status` VARCHAR(10) NOT NULL DEFAULT 'requested' COMMENT 'requested, approved, paid, rejected',
            `iban_encrypted` TEXT NULL COMMENT 'payout details at the time of the request',
            `holder_encrypted` TEXT NULL,
            `bic` VARCHAR(11) NULL,
            `iban_last4` CHAR(4) NULL,
            `reference` VARCHAR(35) NOT NULL COMMENT 'end-to-end reference of the transfer',
            `requested_at` DATETIME NOT NULL,
            `approved_by` BIGINT UNSIGNED NULL,
            `approved_at` DATETIME NULL,
            `paid_by` BIGINT UNSIGNED NULL,
            `paid_at` DATETIME NULL,
            `bank_reference` VARCHAR(100) NULL,
            `rejected_by` BIGINT UNSIGNED NULL,
            `rejected_at` DATETIME NULL,
            `note` VARCHAR(255) NULL,
            `sepa_exported_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_partner_payouts_reference` (`reference`),
            KEY `idx_nx_partner_payouts_partner` (`partner_id`, `status`),
            KEY `idx_nx_partner_payouts_status` (`status`)
        ) $options");
    }
};
