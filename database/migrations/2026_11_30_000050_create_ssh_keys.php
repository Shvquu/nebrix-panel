<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/*
 * Public SSH keys of customers (account → SSH keys), used for new and
 * reinstalled Proxmox servers. Only public keys are stored. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->run("CREATE TABLE IF NOT EXISTS `nx_ssh_keys` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `type` VARCHAR(30) NOT NULL COMMENT 'ssh-ed25519, ecdsa-sha2-nistp256/384/521, ssh-rsa',
            `bits` SMALLINT UNSIGNED NOT NULL,
            `public_key` TEXT NOT NULL COMMENT 'normalized OpenSSH line: type, base64 blob, optional comment',
            `fingerprint` VARCHAR(60) NOT NULL COMMENT 'SHA256:… (OpenSSH format)',
            `created_at` DATETIME NOT NULL,
            `last_used_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_ssh_keys_user_fingerprint` (`user_id`, `fingerprint`),
            CONSTRAINT `fk_nx_ssh_keys_user` FOREIGN KEY (`user_id`) REFERENCES `nx_users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
