<?php

declare(strict_types=1);

namespace Nebrix\Server\Templates;

use Nebrix\Catalog\ProductCategory;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Integration\IntegrationType;
use Nebrix\Security\ValidationException;
use Nebrix\Security\Validator;

/**
 * Operating system / application templates per Proxmox integration and the
 * products that offer them.
 *
 * A template is only ever used for a service when it is active, belongs to
 * the service's integration, has the guest type of the service (KVM for VPS
 * products, LXC for containers) and is offered by the service's product. The
 * template source is validated like the product settings (KVM: VM ID, LXC:
 * "storage:vztmpl/file").
 */
class ServerTemplateRepository
{
    public const TYPE_KVM = 'kvm';
    public const TYPE_LXC = 'lxc';

    /** Icons offered in the form (names of macros/icons.twig). */
    public const ICONS = ['server', 'box', 'layers', 'cloud', 'code', 'globe', 'gamepad', 'cpu', 'zap', 'lock', 'star', 'grid'];

    public const KVM_SOURCE = '/^[1-9]\d{2,8}$/';
    public const LXC_SOURCE = '/^[A-Za-z0-9][A-Za-z0-9._-]*:vztmpl\/[A-Za-z0-9._+-]+$/';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    public static function typeFor(string $category): ?string
    {
        return match ($category) {
            ProductCategory::VPS => self::TYPE_KVM,
            ProductCategory::LXC => self::TYPE_LXC,
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>> templates with integration name and number of products
     */
    public function all(): array
    {
        return array_map(self::cast(...), $this->db->fetchAll(
            'SELECT t.*, i.`name` AS integration_name, (SELECT COUNT(*) FROM `nx_server_template_products` tp WHERE tp.`template_id` = t.`id`) AS product_count
             FROM `nx_server_templates` t JOIN `nx_integrations` i ON i.`id` = t.`integration_id` ORDER BY i.`name`, t.`type`, t.`sort_order`, t.`name`',
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM `nx_server_templates` WHERE `id` = ?', [$id]);

        return $row === null ? null : self::cast($row);
    }

    /**
     * Active templates a product offers for its guest type, in display order.
     *
     * @return list<array<string, mixed>>
     */
    public function offeredBy(int $productId): array
    {
        $product = $this->db->fetchOne('SELECT `category`, `integration_id` FROM `nx_products` WHERE `id` = ?', [$productId]);
        $type = $product !== null ? self::typeFor((string) $product['category']) : null;
        if ($type === null || $product['integration_id'] === null) {
            return [];
        }

        return array_map(self::cast(...), $this->db->fetchAll(
            'SELECT t.* FROM `nx_server_templates` t JOIN `nx_server_template_products` tp ON tp.`template_id` = t.`id`
             WHERE tp.`product_id` = ? AND t.`is_active` = 1 AND t.`type` = ? AND t.`integration_id` = ? ORDER BY t.`sort_order`, t.`name`, t.`id`',
            [$productId, $type, (int) $product['integration_id']],
        ));
    }

    /**
     * A template the product offers (null when it is not offered, inactive, of
     * another integration or guest type).
     *
     * @return array<string, mixed>|null
     */
    public function offeredTemplate(int $productId, int $templateId): ?array
    {
        foreach ($this->offeredBy($productId) as $template) {
            if ($template['id'] === $templateId) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public function productIds(int $templateId): array
    {
        return array_map(static fn (array $row): int => (int) $row['product_id'], $this->db->fetchAll('SELECT `product_id` FROM `nx_server_template_products` WHERE `template_id` = ? ORDER BY `product_id`', [$templateId]));
    }

    /**
     * Products that may offer templates of the given integration and type.
     *
     * @return array<int, string> product ID => name
     */
    public function eligibleProducts(int $integrationId, string $type): array
    {
        $category = $type === self::TYPE_LXC ? ProductCategory::LXC : ProductCategory::VPS;
        $products = [];
        foreach ($this->db->fetchAll('SELECT `id`, `name` FROM `nx_products` WHERE `category` = ? AND `integration_id` = ? ORDER BY `name`', [$category, $integrationId]) as $row) {
            $products[(int) $row['id']] = (string) $row['name'];
        }

        return $products;
    }

    /**
     * @return array<int, string> Proxmox integrations (ID => name)
     */
    public function integrations(): array
    {
        $options = [];
        foreach ($this->db->fetchAll('SELECT `id`, `name` FROM `nx_integrations` WHERE `type` = ? ORDER BY `name`', [IntegrationType::PROXMOX]) as $row) {
            $options[(int) $row['id']] = (string) $row['name'];
        }

        return $options;
    }

    /**
     * Validates the form input of a template.
     *
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, products: list<int>}
     *
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        $data = Validator::validate($input, [
            'integration_id' => 'required|int|min:1',
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:500',
            'icon' => 'required|in:' . implode(',', self::ICONS),
            'type' => 'required|in:kvm,lxc',
            'source' => 'required|string|max:255',
            'cloud_init_user' => 'nullable|string|max:32',
            'ssh_keys' => 'bool',
            'sort_order' => 'nullable|int|min:-1000|max:1000',
            'is_active' => 'bool',
            'products' => 'nullable|array|max:500|ids',
        ], [
            'integration_id' => 'Proxmox integration', 'name' => 'Name', 'description' => 'Description', 'icon' => 'Icon', 'type' => 'Type',
            'source' => 'Template', 'cloud_init_user' => 'Default cloud-init user', 'sort_order' => 'Sort order', 'products' => 'Products',
        ]);

        if (!array_key_exists((int) $data['integration_id'], $this->integrations())) {
            throw ValidationException::withMessages(['integration_id' => 'Choose a Proxmox integration.']);
        }
        if (preg_match('/[\x00-\x1F\x7F<>]/', (string) $data['name']) === 1) {
            throw ValidationException::withMessages(['name' => 'The name contains characters that are not allowed.']);
        }
        $source = (string) $data['source'];
        if ($data['type'] === self::TYPE_KVM && preg_match(self::KVM_SOURCE, $source) !== 1) {
            throw ValidationException::withMessages(['source' => 'Enter the VM ID of a cloud-init template (e.g. 9000).']);
        }
        if ($data['type'] === self::TYPE_LXC && preg_match(self::LXC_SOURCE, $source) !== 1) {
            throw ValidationException::withMessages(['source' => 'Enter an OS template volume, e.g. local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst.']);
        }
        $user = trim((string) ($data['cloud_init_user'] ?? ''));
        if ($user !== '' && ($data['type'] !== self::TYPE_KVM || preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user) !== 1)) {
            throw ValidationException::withMessages(['cloud_init_user' => 'Only KVM templates have a cloud-init user (lowercase letters, digits, - and _).']);
        }

        $products = $data['products'] ?? [];
        $eligible = $this->eligibleProducts((int) $data['integration_id'], (string) $data['type']);
        foreach ($products as $productId) {
            if (!array_key_exists($productId, $eligible)) {
                throw ValidationException::withMessages(['products' => 'Choose products of the same Proxmox integration and server type.']);
            }
        }

        return [
            'data' => [
                'integration_id' => (int) $data['integration_id'],
                'name' => (string) $data['name'],
                'description' => ($data['description'] ?? '') !== '' ? (string) $data['description'] : null,
                'icon' => (string) $data['icon'],
                'type' => (string) $data['type'],
                'source' => $source,
                'cloud_init_user' => $user !== '' ? $user : null,
                'ssh_keys' => $data['ssh_keys'] ? 1 : 0,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => $data['is_active'] ? 1 : 0,
            ],
            'products' => $products,
        ];
    }

    /**
     * @param array<string, mixed> $data validated data
     * @param list<int> $products
     */
    public function save(?int $id, array $data, array $products): int
    {
        return $this->db->transaction(function () use ($id, $data, $products): int {
            $now = $this->clock->nowString();
            if ($id === null) {
                $id = $this->db->insert('nx_server_templates', $data + ['created_at' => $now, 'updated_at' => $now]);
            } else {
                $this->db->update('nx_server_templates', $data + ['updated_at' => $now], ['id' => $id]);
            }
            $this->db->delete('nx_server_template_products', ['template_id' => $id]);
            foreach ($products as $productId) {
                $this->db->insert('nx_server_template_products', ['template_id' => $id, 'product_id' => $productId]);
            }

            return $id;
        });
    }

    /**
     * Deletes a template. Services installed with it keep running; a
     * reinstall then offers the remaining templates only.
     */
    public function delete(int $id): void
    {
        $this->db->delete('nx_server_templates', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function cast(array $row): array
    {
        foreach (['id', 'integration_id', 'sort_order'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['ssh_keys'] = (bool) $row['ssh_keys'];
        $row['is_active'] = (bool) $row['is_active'];
        if (isset($row['product_count'])) {
            $row['product_count'] = (int) $row['product_count'];
        }

        return $row;
    }
}
