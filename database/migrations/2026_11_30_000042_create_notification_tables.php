<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Notifications: customer preferences per event and channel, the log of
 * staff notifications already posted to the Discord channel (deduplication
 * and cursor) and the status page subscribers (double opt-in, confirmation
 * token stored as SHA-256 hash). Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->run("CREATE TABLE IF NOT EXISTS `nx_notification_preferences` (
            `user_id` BIGINT UNSIGNED NOT NULL,
            `event` VARCHAR(40) NOT NULL,
            `email` TINYINT(1) NOT NULL DEFAULT 1,
            `discord` TINYINT(1) NOT NULL DEFAULT 0,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`user_id`, `event`),
            CONSTRAINT `fk_nx_notification_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `nx_users` (`id`) ON DELETE CASCADE
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_staff_notifications` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `event_key` VARCHAR(120) NOT NULL,
            `kind` VARCHAR(30) NOT NULL,
            `ref_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_staff_notifications_key` (`event_key`),
            KEY `idx_nx_staff_notifications_kind` (`kind`, `ref_id`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_status_subscribers` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `email` VARCHAR(190) NOT NULL,
            `locale` VARCHAR(5) NOT NULL DEFAULT 'en',
            `confirm_hash` CHAR(64) NULL COMMENT 'SHA-256 of the confirmation token',
            `confirm_expires_at` DATETIME NULL,
            `confirmed_at` DATETIME NULL,
            `secret` CHAR(32) NOT NULL COMMENT 'random salt of the unsubscribe token (HMAC with APP_KEY)',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_status_subscribers_email` (`email`),
            UNIQUE KEY `uniq_nx_status_subscribers_confirm` (`confirm_hash`),
            KEY `idx_nx_status_subscribers_confirmed` (`confirmed_at`)
        ) $options");
    }
};
