<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Team access: customers invite other users to their account with limited
 * permissions (view / manage / see credentials of all or selected services,
 * view and pay invoices). Removed members are kept with status "revoked" for
 * traceability. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->run("CREATE TABLE IF NOT EXISTS `nx_account_members` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `owner_id` BIGINT UNSIGNED NOT NULL,
            `member_id` BIGINT UNSIGNED NULL COMMENT 'set when the invitation is accepted',
            `email` VARCHAR(190) NOT NULL COMMENT 'invited address',
            `permissions` VARCHAR(255) NOT NULL COMMENT 'comma-separated: services.view, services.manage, services.credentials, billing',
            `all_services` TINYINT(1) NOT NULL DEFAULT 1,
            `service_ids` TEXT NULL COMMENT 'comma-separated service IDs when all_services = 0',
            `status` VARCHAR(20) NOT NULL DEFAULT 'invited' COMMENT 'invited | active | revoked',
            `token_hash` CHAR(64) NULL COMMENT 'SHA-256 of the invitation token',
            `token_expires_at` DATETIME NULL,
            `invited_by` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            `accepted_at` DATETIME NULL,
            `revoked_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_account_members_token` (`token_hash`),
            KEY `idx_nx_account_members_owner` (`owner_id`, `status`),
            KEY `idx_nx_account_members_member` (`member_id`, `status`),
            CONSTRAINT `fk_nx_account_members_owner` FOREIGN KEY (`owner_id`) REFERENCES `nx_users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_nx_account_members_member` FOREIGN KEY (`member_id`) REFERENCES `nx_users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
