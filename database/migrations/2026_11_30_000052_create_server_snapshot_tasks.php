<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/*
 * Snapshot requests of Proxmox servers (create, roll back, delete). They run
 * through the job queue; the row shows the customer the state of the task.
 * Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->run("CREATE TABLE IF NOT EXISTS `nx_server_snapshot_tasks` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `service_id` BIGINT UNSIGNED NOT NULL,
            `action` VARCHAR(10) NOT NULL COMMENT 'create, rollback, delete',
            `snapshot` VARCHAR(40) NOT NULL,
            `description` VARCHAR(255) NULL,
            `status` VARCHAR(10) NOT NULL DEFAULT 'queued' COMMENT 'queued, running, done, failed',
            `upid` VARCHAR(255) NULL,
            `error` VARCHAR(255) NULL,
            `requested_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            `finished_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `idx_nx_server_snapshot_tasks_service` (`service_id`, `status`),
            CONSTRAINT `fk_nx_server_snapshot_tasks_service` FOREIGN KEY (`service_id`) REFERENCES `nx_services` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_nx_server_snapshot_tasks_user` FOREIGN KEY (`requested_by`) REFERENCES `nx_users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
