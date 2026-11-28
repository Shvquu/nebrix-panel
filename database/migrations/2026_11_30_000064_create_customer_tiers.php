<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Reseller tiers: rules that place customers in customer groups by paid
 * revenue or active services, and the tier state per customer (for the
 * "at most one downgrade per period" rule). Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->run("CREATE TABLE IF NOT EXISTS `nx_tier_rules` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `customer_group_id` INT UNSIGNED NOT NULL,
            `metric` VARCHAR(20) NOT NULL COMMENT 'revenue_12m or active_services',
            `threshold` BIGINT UNSIGNED NOT NULL COMMENT 'minor units (revenue) or number of services',
            `tier_rank` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'higher rank = better tier',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_nx_tier_rules_rank` (`is_active`, `tier_rank`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_customer_tiers` (
            `user_id` BIGINT UNSIGNED NOT NULL,
            `rule_id` INT UNSIGNED NULL COMMENT 'current tier (NULL = none)',
            `customer_group_id` INT UNSIGNED NULL COMMENT 'group set by the tier evaluation',
            `revenue_12m` BIGINT NOT NULL DEFAULT 0,
            `active_services` INT UNSIGNED NOT NULL DEFAULT 0,
            `changed_at` DATETIME NULL,
            `last_downgrade_at` DATETIME NULL,
            `evaluated_at` DATETIME NOT NULL,
            PRIMARY KEY (`user_id`),
            KEY `idx_nx_customer_tiers_rule` (`rule_id`)
        ) $options");
    }
};
