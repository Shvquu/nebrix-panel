<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Saved payment methods (card / SEPA direct debit at Stripe) for automatic
 * renewal payments, and the log of automatic payment attempts (one row per
 * charge attempt or SEPA pre-notification, unique per invoice). Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->run("CREATE TABLE IF NOT EXISTS `nx_payment_methods` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `gateway` VARCHAR(20) NOT NULL DEFAULT 'stripe',
            `external_id` VARCHAR(64) NOT NULL COMMENT 'Stripe PaymentMethod ID (pm_…)',
            `customer_ref` VARCHAR(64) NOT NULL COMMENT 'Stripe Customer ID (cus_…)',
            `type` VARCHAR(20) NOT NULL COMMENT 'card | sepa_debit',
            `brand` VARCHAR(30) NULL,
            `last4` CHAR(4) NULL,
            `exp_month` TINYINT UNSIGNED NULL,
            `exp_year` SMALLINT UNSIGNED NULL,
            `country` CHAR(2) NULL,
            `mandate_id` VARCHAR(64) NULL COMMENT 'SEPA mandate at Stripe',
            `mandate_reference` VARCHAR(64) NULL,
            `is_default` TINYINT(1) NOT NULL DEFAULT 0,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active | inactive | removed',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            `removed_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_payment_methods_external` (`gateway`, `external_id`),
            KEY `idx_nx_payment_methods_user` (`user_id`, `status`),
            CONSTRAINT `fk_nx_payment_methods_user` FOREIGN KEY (`user_id`) REFERENCES `nx_users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_autopay_attempts` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `invoice_id` BIGINT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `payment_method_id` BIGINT UNSIGNED NULL,
            `kind` VARCHAR(20) NOT NULL COMMENT 'charge | prenotice',
            `attempt` TINYINT UNSIGNED NOT NULL,
            `status` VARCHAR(20) NOT NULL COMMENT 'sent | processing | succeeded | failed | action_required',
            `payment_intent_id` VARCHAR(64) NULL,
            `amount` BIGINT NOT NULL DEFAULT 0,
            `currency` CHAR(3) NOT NULL,
            `debit_on` DATE NULL COMMENT 'announced debit date (SEPA pre-notification)',
            `error` VARCHAR(255) NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_autopay_attempts` (`invoice_id`, `kind`, `attempt`),
            KEY `idx_nx_autopay_attempts_intent` (`payment_intent_id`),
            KEY `idx_nx_autopay_attempts_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $columns = static fn (string $column): int => (int) $db->fetchValue(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nx_users' AND COLUMN_NAME = ?",
            [$column],
        );
        if ($columns('stripe_customer_id') === 0) {
            $db->run("ALTER TABLE `nx_users` ADD COLUMN `stripe_customer_id` VARCHAR(64) NULL AFTER `auto_pay_credit`, ADD UNIQUE KEY `uniq_nx_users_stripe_customer` (`stripe_customer_id`)");
        }
        if ($columns('auto_pay_saved') === 0) {
            $db->run("ALTER TABLE `nx_users` ADD COLUMN `auto_pay_saved` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'pay renewals with the default saved payment method' AFTER `stripe_customer_id`");
        }
    }
};
