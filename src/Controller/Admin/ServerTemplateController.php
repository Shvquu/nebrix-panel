<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Authorization\Permission;
use Nebrix\Database\Connection;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Server\Templates\ServerTemplateRepository;

/**
 * Admin → Server templates: operating system / application templates per
 * Proxmox integration and the products that offer them. Viewing needs
 * products.view, changes products.manage; every change is audit-logged.
 */
final class ServerTemplateController extends Controller
{
    public function __construct(
        private readonly ServerTemplateRepository $templates,
        private readonly Connection $db,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render($request, 'admin/server_templates/index.twig', ['templates' => $this->templates->all()]);
    }

    public function create(Request $request): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);

        return $this->form($request, null);
    }

    public function store(Request $request): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);
        $valid = $this->templates->validate($request->all());
        $id = $this->templates->save(null, $valid['data'], $valid['products']);
        $this->audit($request, 'server_template.created', 'server_template', $id, ['name' => $valid['data']['name'], 'type' => $valid['data']['type'], 'source' => $valid['data']['source'], 'products' => $valid['products']]);
        $this->flash($request, 'success', 'The template has been saved.');

        return $this->redirect('/admin/server-templates');
    }

    public function edit(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);

        return $this->form($request, $this->templates->find($id) ?? throw HttpException::notFound());
    }

    public function update(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);
        $this->templates->find($id) ?? throw HttpException::notFound();
        $valid = $this->templates->validate($request->all());
        $this->templates->save($id, $valid['data'], $valid['products']);
        $this->audit($request, 'server_template.updated', 'server_template', $id, ['name' => $valid['data']['name'], 'type' => $valid['data']['type'], 'source' => $valid['data']['source'], 'active' => $valid['data']['is_active'], 'products' => $valid['products']]);
        $this->flash($request, 'success', 'The template has been saved.');

        return $this->redirect('/admin/server-templates');
    }

    public function delete(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);
        $template = $this->templates->find($id) ?? throw HttpException::notFound();
        $this->templates->delete($id);
        $this->audit($request, 'server_template.deleted', 'server_template', $id, ['name' => $template['name']]);
        $this->flash($request, 'success', 'The template has been deleted. Installed servers are not affected.');

        return $this->redirect('/admin/server-templates');
    }

    /**
     * @param array<string, mixed>|null $template
     */
    private function form(Request $request, ?array $template): Response
    {
        // Every VPS/LXC product of a Proxmox integration; the save checks that they match the template.
        $products = [];
        foreach ($this->db->fetchAll(
            "SELECT p.`id`, p.`name`, p.`category`, i.`name` AS integration_name FROM `nx_products` p JOIN `nx_integrations` i ON i.`id` = p.`integration_id`
             WHERE p.`category` IN ('vps', 'lxc') AND i.`type` = 'proxmox' ORDER BY i.`name`, p.`category`, p.`name`",
        ) as $row) {
            $products[(int) $row['id']] = sprintf('%s (%s, %s)', $row['name'], $row['integration_name'], $row['category'] === 'lxc' ? 'LXC' : 'KVM');
        }

        return $this->render($request, 'admin/server_templates/form.twig', [
            'template' => $template,
            'integrations' => $this->templates->integrations(),
            'icons' => array_combine(ServerTemplateRepository::ICONS, ServerTemplateRepository::ICONS),
            'products' => $products,
            'selected_products' => $template !== null ? $this->templates->productIds((int) $template['id']) : [],
        ]);
    }
}
