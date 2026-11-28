<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Help center: categories and articles (Markdown, published or draft) with
 * "Was this helpful?" counters. Grants "help_center.manage" to the built-in
 * administrator and support roles. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->run("CREATE TABLE IF NOT EXISTS `nx_help_categories` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `slug` VARCHAR(80) NOT NULL,
            `title` VARCHAR(150) NOT NULL,
            `description` VARCHAR(255) NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_published` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_help_categories_slug` (`slug`),
            KEY `idx_nx_help_categories_sort` (`is_published`, `sort_order`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_help_articles` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `category_id` INT UNSIGNED NOT NULL,
            `slug` VARCHAR(120) NOT NULL,
            `title` VARCHAR(200) NOT NULL,
            `body` MEDIUMTEXT NOT NULL COMMENT 'Markdown, rendered with all HTML escaped',
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_published` TINYINT(1) NOT NULL DEFAULT 0,
            `helpful_yes` INT UNSIGNED NOT NULL DEFAULT 0,
            `helpful_no` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_by` BIGINT UNSIGNED NULL,
            `updated_by` BIGINT UNSIGNED NULL,
            `published_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_help_articles_slug` (`category_id`, `slug`),
            KEY `idx_nx_help_articles_list` (`category_id`, `is_published`, `sort_order`),
            CONSTRAINT `fk_nx_help_articles_category` FOREIGN KEY (`category_id`) REFERENCES `nx_help_categories` (`id`),
            CONSTRAINT `fk_nx_help_articles_created_by` FOREIGN KEY (`created_by`) REFERENCES `nx_users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_nx_help_articles_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `nx_users` (`id`) ON DELETE SET NULL
        ) $options");

        foreach (['admin', 'support'] as $slug) {
            $role = $db->fetchValue('SELECT `id` FROM `nx_roles` WHERE `slug` = ?', [$slug]);
            if ($role !== null) {
                $db->run('INSERT IGNORE INTO `nx_role_permissions` (`role_id`, `permission`) VALUES (?, ?)', [(int) $role, 'help_center.manage']);
            }
        }
    }
};
