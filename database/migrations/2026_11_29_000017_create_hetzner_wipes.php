<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Automatic disk wiping of returned Hetzner servers before they go back to
 * the pool. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->run("CREATE TABLE IF NOT EXISTS `nx_hetzner_wipes` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `integration_id` INT UNSIGNED NOT NULL,
            `server_number` INT UNSIGNED NOT NULL,
            `service_id` BIGINT UNSIGNED NULL,
            `status` VARCHAR(12) NOT NULL DEFAULT 'pending' COMMENT 'pending, booting, running, done, failed',
            `method` VARCHAR(10) NOT NULL DEFAULT 'full',
            `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `host_keys` TEXT NULL COMMENT 'rescue host keys reported by Robot (JSON)',
            `reset_at` DATETIME NULL,
            `started_at` DATETIME NULL,
            `finished_at` DATETIME NULL,
            `error` VARCHAR(500) NULL,
            `log` TEXT NULL COMMENT 'last lines of the wipe log',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_nx_hetzner_wipes_open` (`status`, `updated_at`),
            KEY `idx_nx_hetzner_wipes_server` (`integration_id`, `server_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
