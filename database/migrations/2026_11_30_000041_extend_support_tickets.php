<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Ticket improvements: attachments (files under storage/, random names),
 * canned responses, satisfaction ratings, the source of a message (web,
 * e-mail, API) and the log of inbound e-mail replies (idempotency by
 * message ID). Grants "canned_responses.manage" to the built-in
 * administrator and support roles. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $exists = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['nx_ticket_messages', 'source'],
        );
        if ($exists === 0) {
            $db->run("ALTER TABLE `nx_ticket_messages` ADD COLUMN `source` VARCHAR(10) NOT NULL DEFAULT 'web' COMMENT 'web, email, api'");
        }

        $db->run("CREATE TABLE IF NOT EXISTS `nx_ticket_attachments` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ticket_id` BIGINT UNSIGNED NOT NULL,
            `message_id` BIGINT UNSIGNED NULL,
            `user_id` BIGINT UNSIGNED NULL,
            `is_staff` TINYINT(1) NOT NULL DEFAULT 0,
            `original_name` VARCHAR(150) NOT NULL COMMENT 'sanitized, display only',
            `stored_name` VARCHAR(80) NOT NULL COMMENT 'random file name below storage/attachments',
            `mime` VARCHAR(60) NOT NULL COMMENT 'detected from the content',
            `size` INT UNSIGNED NOT NULL,
            `sha256` CHAR(64) NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_ticket_attachments_stored` (`stored_name`),
            KEY `idx_nx_ticket_attachments_ticket` (`ticket_id`, `message_id`),
            CONSTRAINT `fk_nx_ticket_attachments_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `nx_tickets` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_nx_ticket_attachments_message` FOREIGN KEY (`message_id`) REFERENCES `nx_ticket_messages` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_nx_ticket_attachments_user` FOREIGN KEY (`user_id`) REFERENCES `nx_users` (`id`) ON DELETE SET NULL
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_canned_responses` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(120) NOT NULL,
            `body` TEXT NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_by` BIGINT UNSIGNED NULL,
            `updated_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_nx_canned_responses_sort` (`sort_order`, `title`),
            CONSTRAINT `fk_nx_canned_responses_created_by` FOREIGN KEY (`created_by`) REFERENCES `nx_users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_nx_canned_responses_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `nx_users` (`id`) ON DELETE SET NULL
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_ticket_ratings` (
            `ticket_id` BIGINT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED NULL,
            `rating` TINYINT UNSIGNED NOT NULL COMMENT '1-5',
            `comment` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`ticket_id`),
            KEY `idx_nx_ticket_ratings_created` (`created_at`),
            CONSTRAINT `fk_nx_ticket_ratings_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `nx_tickets` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_nx_ticket_ratings_user` FOREIGN KEY (`user_id`) REFERENCES `nx_users` (`id`) ON DELETE SET NULL
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_ticket_inbound_mails` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 of the Message-ID',
            `ticket_id` BIGINT UNSIGNED NULL,
            `ticket_message_id` BIGINT UNSIGNED NULL,
            `status` VARCHAR(20) NOT NULL COMMENT 'accepted or the reason it was ignored',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_ticket_inbound_mails_hash` (`message_hash`),
            KEY `idx_nx_ticket_inbound_mails_created` (`created_at`),
            CONSTRAINT `fk_nx_ticket_inbound_mails_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `nx_tickets` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_nx_ticket_inbound_mails_message` FOREIGN KEY (`ticket_message_id`) REFERENCES `nx_ticket_messages` (`id`) ON DELETE SET NULL
        ) $options");

        foreach (['admin', 'support'] as $slug) {
            $role = $db->fetchValue('SELECT `id` FROM `nx_roles` WHERE `slug` = ?', [$slug]);
            if ($role !== null) {
                $db->run('INSERT IGNORE INTO `nx_role_permissions` (`role_id`, `permission`) VALUES (?, ?)', [(int) $role, 'canned_responses.manage']);
            }
        }
    }
};
