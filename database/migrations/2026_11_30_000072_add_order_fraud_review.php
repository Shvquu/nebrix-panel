<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/**
 * Fraud check of paid orders before they are set up: score, reasons and the
 * staff decision. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $exists = (int) $db->fetchValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nx_orders' AND COLUMN_NAME = 'fraud_status'");
        if ($exists === 0) {
            $db->run("ALTER TABLE `nx_orders`
                ADD COLUMN `fraud_score` SMALLINT UNSIGNED NULL AFTER `notes`,
                ADD COLUMN `fraud_status` VARCHAR(20) NULL COMMENT 'clear | review | approved | rejected' AFTER `fraud_score`,
                ADD COLUMN `fraud_reasons` TEXT NULL COMMENT 'JSON list of matched rules' AFTER `fraud_status`,
                ADD COLUMN `fraud_checked_at` DATETIME NULL AFTER `fraud_reasons`,
                ADD COLUMN `fraud_reviewed_by` BIGINT UNSIGNED NULL AFTER `fraud_checked_at`,
                ADD COLUMN `fraud_reviewed_at` DATETIME NULL AFTER `fraud_reviewed_by`,
                ADD KEY `idx_nx_orders_fraud` (`fraud_status`)");
        }
    }
};
