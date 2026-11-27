<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Public status page: components, incidents and maintenance announcements
 * with a timeline of updates. Grants "status.manage" to the built-in
 * administrator and support roles. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->run("CREATE TABLE IF NOT EXISTS `nx_status_components` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `description` VARCHAR(255) NULL,
            `group_name` VARCHAR(60) NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'operational' COMMENT 'operational, degraded, partial_outage, major_outage, maintenance',
            `integration_id` INT UNSIGNED NULL COMMENT 'failed health checks of this integration show the component as degraded',
            `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_nx_status_components_sort` (`is_visible`, `sort_order`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_status_incidents` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `type` VARCHAR(12) NOT NULL COMMENT 'incident or maintenance',
            `title` VARCHAR(150) NOT NULL,
            `impact` VARCHAR(12) NOT NULL DEFAULT 'minor' COMMENT 'none, minor, major, critical (incidents)',
            `status` VARCHAR(20) NOT NULL COMMENT 'incident: investigating, identified, monitoring, resolved; maintenance: scheduled, in_progress, completed',
            `starts_at` DATETIME NOT NULL,
            `ends_at` DATETIME NULL COMMENT 'maintenance: planned end',
            `resolved_at` DATETIME NULL,
            `is_published` TINYINT(1) NOT NULL DEFAULT 1,
            `notified_at` DATETIME NULL,
            `created_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_nx_status_incidents_state` (`is_published`, `resolved_at`, `starts_at`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_status_incident_components` (
            `incident_id` BIGINT UNSIGNED NOT NULL,
            `component_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`incident_id`, `component_id`),
            KEY `idx_nx_status_incident_components_component` (`component_id`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_status_updates` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `incident_id` BIGINT UNSIGNED NOT NULL,
            `status` VARCHAR(20) NOT NULL,
            `message` TEXT NOT NULL,
            `created_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_nx_status_updates_incident` (`incident_id`, `created_at`)
        ) $options");

        foreach (['admin', 'support'] as $slug) {
            $role = $db->fetchValue('SELECT `id` FROM `nx_roles` WHERE `slug` = ?', [$slug]);
            if ($role !== null) {
                $db->run('INSERT IGNORE INTO `nx_role_permissions` (`role_id`, `permission`) VALUES (?, ?)', [(int) $role, 'status.manage']);
            }
        }
    }
};
