<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Marketing consents of customers (time, source, truncated IP address and
 * the text agreed to; withdrawable) and the review invitations sent (at most
 * one per customer). Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->run("CREATE TABLE IF NOT EXISTS `nx_marketing_consents` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `purpose` VARCHAR(40) NOT NULL COMMENT 'review_invitation',
            `granted_at` DATETIME NULL,
            `granted_source` VARCHAR(20) NULL COMMENT 'registration, checkout, account',
            `granted_ip` VARCHAR(45) NULL COMMENT 'truncated (IPv4 /24, IPv6 /48)',
            `consent_text` VARCHAR(500) NULL COMMENT 'the text the customer agreed to',
            `withdrawn_at` DATETIME NULL,
            `withdrawn_via` VARCHAR(20) NULL COMMENT 'account, email',
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_marketing_consents_user_purpose` (`user_id`, `purpose`),
            KEY `idx_nx_marketing_consents_purpose` (`purpose`, `withdrawn_at`)
        ) $options");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_review_invitations` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `service_id` BIGINT UNSIGNED NULL COMMENT 'first active service',
            `optout_token_hash` CHAR(64) NULL COMMENT 'SHA-256 of the opt-out link token',
            `sent_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_nx_review_invitations_user` (`user_id`),
            UNIQUE KEY `uniq_nx_review_invitations_token` (`optout_token_hash`)
        ) $options");
    }
};
