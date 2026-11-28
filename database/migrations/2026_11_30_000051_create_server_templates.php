<?php

declare(strict_types=1);

use Nebrix\Database\Connection;
use Nebrix\Database\Migration;

/*
 * Operating system / application templates per Proxmox integration ("Debian
 * with Docker" …) and the products that offer them. The previous product
 * settings (template VM ID / OS template, "reinstall_templates") keep working
 * unchanged next to them. Additive only.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->run("CREATE TABLE IF NOT EXISTS `nx_server_templates` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `integration_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(80) NOT NULL,
            `description` VARCHAR(500) NULL,
            `icon` VARCHAR(30) NOT NULL DEFAULT 'server',
            `type` VARCHAR(10) NOT NULL COMMENT 'kvm or lxc',
            `source` VARCHAR(255) NOT NULL COMMENT 'KVM: template VM ID, LXC: vztmpl volume',
            `cloud_init_user` VARCHAR(32) NULL COMMENT 'KVM: default cloud-init user (empty = root)',
            `ssh_keys` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'customer SSH keys are installed',
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_nx_server_templates_integration` (`integration_id`, `type`),
            CONSTRAINT `fk_nx_server_templates_integration` FOREIGN KEY (`integration_id`) REFERENCES `nx_integrations` (`id`) ON DELETE CASCADE
        ) {$options}");

        $db->run("CREATE TABLE IF NOT EXISTS `nx_server_template_products` (
            `template_id` INT UNSIGNED NOT NULL,
            `product_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`template_id`, `product_id`),
            KEY `idx_nx_server_template_products_product` (`product_id`),
            CONSTRAINT `fk_nx_server_template_products_template` FOREIGN KEY (`template_id`) REFERENCES `nx_server_templates` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_nx_server_template_products_product` FOREIGN KEY (`product_id`) REFERENCES `nx_products` (`id`) ON DELETE CASCADE
        ) {$options}");
    }
};
