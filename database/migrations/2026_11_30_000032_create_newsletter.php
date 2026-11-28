<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Newsletter with double opt-in: subscribers (proof of consent: time and
 * truncated IP address of the request and of the confirmation, the text
 * agreed to), campaigns and one delivery per campaign and recipient. Grants
 * "newsletter.view", "newsletter.manage" and "newsletter.export" to the
 * built-in administrator role. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->run("CREATE TABLE IF NOT EXISTS `nx_newsletter_subscribers` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `email` VARCHAR(254) NOT NULL,
            `user_id` BIGINT UNSIGNED NULL COMMENT 'customer account (subscribed in the account area)',
            `locale` VARCHAR(5) NOT NULL DEFAULT 'en',
            `status` VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending, confirmed, unsubscribed',
            `source` VARCHAR(20) NOT NULL COMMENT 'website, account',
            `consent_text` VARCHAR(500) NULL COMMENT 'the text the subscriber agreed to',
            `confirm_token_hash` CHAR(64) NULL COMMENT 'SHA-256 of the confirmation token',
            `confirm_expires_at` DATETIME NULL,
            `requested_at` DATETIME NULL,
            `requested_ip` VARCHAR(45) NULL COMMENT 'truncated (IPv4 /24, IPv6 /48)',
            `confirmed_at` DATETIME NULL,
            `confirmed_ip` VARCHAR(45) NULL COMMENT 'truncated (IPv4 /24, IPv6 /48)',
            `unsubscribed_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_newsletter_subscribers_email` (`email`),
            UNIQUE KEY `uniq_nx_newsletter_subscribers_token` (`confirm_token_hash`),
            KEY `idx_nx_newsletter_subscribers_user` (`user_id`),
            KEY `idx_nx_newsletter_subscribers_status` (`status`, `confirm_expires_at`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_newsletter_campaigns` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `subject` VARCHAR(200) NOT NULL,
            `body` MEDIUMTEXT NOT NULL COMMENT 'Markdown',
            `audience` VARCHAR(5) NOT NULL DEFAULT 'all' COMMENT 'all or one language (de, en)',
            `status` VARCHAR(12) NOT NULL DEFAULT 'draft' COMMENT 'draft, sending, sent, cancelled',
            `recipients` INT UNSIGNED NOT NULL DEFAULT 0,
            `queued_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `started_at` DATETIME NULL,
            `finished_at` DATETIME NULL,
            `created_by` BIGINT UNSIGNED NULL,
            `updated_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_nx_newsletter_campaigns_status` (`status`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_newsletter_deliveries` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `campaign_id` INT UNSIGNED NOT NULL,
            `subscriber_id` BIGINT UNSIGNED NOT NULL,
            `status` VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending, queued, skipped',
            `unsubscribe_token_hash` CHAR(64) NULL COMMENT 'SHA-256 of the unsubscribe token in this e-mail',
            `queued_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_newsletter_deliveries_recipient` (`campaign_id`, `subscriber_id`),
            UNIQUE KEY `uniq_nx_newsletter_deliveries_token` (`unsubscribe_token_hash`),
            KEY `idx_nx_newsletter_deliveries_state` (`campaign_id`, `status`),
            KEY `idx_nx_newsletter_deliveries_subscriber` (`subscriber_id`)
        ) $options");

        $role = $db->fetchValue("SELECT `id` FROM `nx_roles` WHERE `slug` = 'admin'");
        if ($role !== null) {
            foreach (['newsletter.view', 'newsletter.manage', 'newsletter.export'] as $permission) {
                $db->run('INSERT IGNORE INTO `nx_role_permissions` (`role_id`, `permission`) VALUES (?, ?)', [(int) $role, $permission]);
            }
        }
    }
};
