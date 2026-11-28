<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/*
 * IP pools: optional Hetzner Robot integration for reverse DNS of the pool's
 * addresses. IP addresses: additional (add-on) addresses of a service carry
 * the add-on key and the guest interface they are attached to; the main
 * address of a service has no add-on key. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $column = static fn (string $table, string $name): bool => (int) $db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $name],
        ) > 0;

        if (!$column('nx_ip_pools', 'rdns_integration_id')) {
            $db->run("ALTER TABLE `nx_ip_pools` ADD COLUMN `rdns_integration_id` INT UNSIGNED NULL COMMENT 'Hetzner Robot integration for reverse DNS' AFTER `integration_id`,
                ADD CONSTRAINT `fk_nx_ip_pools_rdns_integration` FOREIGN KEY (`rdns_integration_id`) REFERENCES `nx_integrations` (`id`) ON DELETE SET NULL");
        }
        if (!$column('nx_ip_addresses', 'addon_key')) {
            $db->run("ALTER TABLE `nx_ip_addresses` ADD COLUMN `addon_key` VARCHAR(64) NULL COMMENT 'additional address: addon:<id> or manual:<token>' AFTER `service_id`,
                ADD COLUMN `guest_interface` VARCHAR(10) NULL COMMENT 'guest interface of an additional address, e.g. net1' AFTER `addon_key`,
                ADD UNIQUE KEY `uniq_nx_ip_addresses_addon` (`addon_key`)");
        }
    }
};
