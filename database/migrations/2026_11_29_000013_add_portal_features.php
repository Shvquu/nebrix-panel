<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Customer portal: support PIN (encrypted, shown to the customer, verified
 * by staff) and Discord account links (Discord user ID and name, roles the
 * panel granted). Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        foreach (['support_pin' => 'VARCHAR(255) NULL', 'support_pin_created_at' => 'DATETIME NULL'] as $column => $definition) {
            $exists = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['nx_users', $column],
            );
            if ($exists === 0) {
                $db->run(sprintf('ALTER TABLE `nx_users` ADD COLUMN `%s` %s', $column, $definition));
            }
        }

        $db->run("CREATE TABLE IF NOT EXISTS `nx_discord_links` (
            `user_id` BIGINT UNSIGNED NOT NULL,
            `discord_id` VARCHAR(20) NOT NULL,
            `username` VARCHAR(100) NOT NULL,
            `global_name` VARCHAR(100) NULL,
            `linked_at` DATETIME NOT NULL,
            `synced_roles` VARCHAR(255) NULL COMMENT 'comma-separated role IDs the panel granted',
            `in_guild` TINYINT(1) NULL,
            `last_synced_at` DATETIME NULL,
            `sync_error` VARCHAR(255) NULL,
            PRIMARY KEY (`user_id`),
            UNIQUE KEY `uniq_nx_discord_links_discord` (`discord_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
