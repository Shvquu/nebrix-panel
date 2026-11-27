<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Coupons (codes with percent or fixed discounts, limits and validity) and
 * referrals ("refer a friend": referral code per customer, reward after the
 * referred customer's first paid order). Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->run("CREATE TABLE IF NOT EXISTS `nx_coupons` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(32) NOT NULL,
            `description` VARCHAR(255) NULL COMMENT 'internal note',
            `type` VARCHAR(10) NOT NULL COMMENT 'percent or fixed',
            `value` INT UNSIGNED NOT NULL COMMENT 'basis points (percent) or minor units (fixed)',
            `currency` CHAR(3) NULL COMMENT 'fixed amounts only',
            `duration` VARCHAR(10) NOT NULL DEFAULT 'once' COMMENT 'once = first invoice, forever = also every renewal',
            `product_ids` VARCHAR(500) NULL COMMENT 'comma-separated; NULL = all products',
            `valid_from` DATETIME NULL,
            `valid_until` DATETIME NULL,
            `max_uses` INT UNSIGNED NULL COMMENT 'NULL = unlimited',
            `max_uses_per_customer` INT UNSIGNED NOT NULL DEFAULT 1,
            `new_customers_only` TINYINT(1) NOT NULL DEFAULT 0,
            `uses` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_coupons_code` (`code`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_coupon_redemptions` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `coupon_id` BIGINT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `order_id` BIGINT UNSIGNED NOT NULL,
            `discount` INT UNSIGNED NOT NULL COMMENT 'discount on the first invoice (net, minor units)',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_coupon_redemptions_order` (`order_id`),
            KEY `idx_nx_coupon_redemptions_coupon_user` (`coupon_id`, `user_id`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_referrals` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `referrer_id` BIGINT UNSIGNED NOT NULL,
            `referred_id` BIGINT UNSIGNED NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, review, qualified, rewarded, rejected',
            `invoice_id` BIGINT UNSIGNED NULL COMMENT 'qualifying invoice of the referred customer',
            `reward` INT UNSIGNED NULL,
            `welcome` INT UNSIGNED NULL,
            `currency` CHAR(3) NULL,
            `signup_ip` VARCHAR(45) NULL,
            `note` VARCHAR(255) NULL,
            `created_at` DATETIME NOT NULL,
            `qualified_at` DATETIME NULL,
            `rewarded_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_referrals_referred` (`referred_id`),
            KEY `idx_nx_referrals_referrer` (`referrer_id`, `status`),
            KEY `idx_nx_referrals_status` (`status`)
        ) $options");

        $columns = [
            ['nx_orders', 'coupon_id', 'BIGINT UNSIGNED NULL'],
            ['nx_orders', 'coupon_code', 'VARCHAR(32) NULL'],
            ['nx_orders', 'coupon_discount', 'INT UNSIGNED NOT NULL DEFAULT 0'],
            ['nx_order_items', 'recurring_discount', "INT UNSIGNED NULL COMMENT 'discount on every renewal; NULL = same as discount (before coupons)'"],
            ['nx_users', 'referral_code', 'VARCHAR(16) NULL'],
            ['nx_users', 'referred_by', 'BIGINT UNSIGNED NULL'],
        ];
        foreach ($columns as [$table, $column, $definition]) {
            $exists = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
            );
            if ($exists === 0) {
                $db->run(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
            }
        }
        $index = (int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nx_users' AND INDEX_NAME = 'uniq_nx_users_referral_code'");
        if ($index === 0) {
            $db->run('ALTER TABLE `nx_users` ADD UNIQUE KEY `uniq_nx_users_referral_code` (`referral_code`)');
        }
    }
};
