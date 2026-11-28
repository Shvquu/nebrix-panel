<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Landing pages and blog posts of the public website. Grants
 * "content.view" and "content.manage" to the built-in administrator role.
 * Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->run("CREATE TABLE IF NOT EXISTS `nx_landing_pages` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `slug` VARCHAR(100) NOT NULL COMMENT 'address /{slug}',
            `status` VARCHAR(12) NOT NULL DEFAULT 'draft' COMMENT 'draft, published',
            `seo_title` VARCHAR(120) NULL,
            `meta_description` VARCHAR(320) NULL,
            `hero_title` VARCHAR(150) NOT NULL,
            `hero_teaser` VARCHAR(400) NULL,
            `intro` MEDIUMTEXT NULL COMMENT 'Markdown',
            `features` TEXT NULL COMMENT 'one section per line: Title | Text',
            `faq` TEXT NULL COMMENT 'blocks separated by a blank line: question, then answer',
            `website_page` VARCHAR(30) NULL COMMENT 'product page whose packages are shown (WebsiteCatalog::PAGES)',
            `product_line` VARCHAR(100) NULL COMMENT 'only this product line of the page',
            `published_at` DATETIME NULL COMMENT 'first publication',
            `created_by` BIGINT UNSIGNED NULL,
            `updated_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_landing_pages_slug` (`slug`),
            KEY `idx_nx_landing_pages_status` (`status`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_blog_posts` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `slug` VARCHAR(120) NOT NULL,
            `title` VARCHAR(150) NOT NULL,
            `teaser` VARCHAR(500) NULL,
            `body` MEDIUMTEXT NOT NULL COMMENT 'Markdown',
            `cover_image` VARCHAR(64) NULL COMMENT 'file name in storage/uploads/marketing',
            `author_name` VARCHAR(100) NULL,
            `status` VARCHAR(12) NOT NULL DEFAULT 'draft' COMMENT 'draft, published (public from published_at)',
            `published_at` DATETIME NULL COMMENT 'UTC; in the future = scheduled',
            `created_by` BIGINT UNSIGNED NULL,
            `updated_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_blog_posts_slug` (`slug`),
            KEY `idx_nx_blog_posts_public` (`status`, `published_at`)
        ) $options");

        $role = $db->fetchValue("SELECT `id` FROM `nx_roles` WHERE `slug` = 'admin'");
        if ($role !== null) {
            foreach (['content.view', 'content.manage'] as $permission) {
                $db->run('INSERT IGNORE INTO `nx_role_permissions` (`role_id`, `permission`) VALUES (?, ?)', [(int) $role, $permission]);
            }
        }
    }
};
