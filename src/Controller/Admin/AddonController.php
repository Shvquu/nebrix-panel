<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Authorization\Permission;
use Nebrix\Billing\Money;
use Nebrix\Catalog\ProductCategory;
use Nebrix\Database\Connection;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\ValidationException;
use Nebrix\Service\Addon\AddonRepository;
use Nebrix\Service\Addon\AddonService;
use Nebrix\Service\ServiceChangeException;
use Nebrix\Settings\SettingsRepository;

/**
 * Add-on catalog (Sales → Add-ons: products.view / products.manage) and
 * the queue of add-ons staff set up or remove by hand (Services → Add-ons
 * to set up: services.view / services.manage). Every change is
 * audit-logged.
 */
final class AddonController extends Controller
{
    public function __construct(
        private readonly AddonRepository $addons,
        private readonly AddonService $service,
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render($request, 'admin/addons/index.twig', ['addons' => $this->addons->all(), 'kinds' => AddonRepository::kinds(), 'categories' => ProductCategory::labels()]);
    }

    public function create(Request $request): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);

        return $this->form($request, null);
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->form($request, $this->addons->find($id) ?? throw HttpException::notFound());
    }

    public function store(Request $request): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);
        $id = $this->addons->save(null, $this->input($request), $this->user($request)->id);
        $this->audit($request, 'addon.created', 'addon', $id);
        $this->flash($request, 'success', 'Add-on saved.');

        return $this->redirect('/admin/addons/' . $id);
    }

    public function update(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);
        $this->addons->find($id) ?? throw HttpException::notFound();
        $data = $this->input($request);
        $this->addons->save($id, $data, $this->user($request)->id);
        // Booked add-ons keep the price they were booked with.
        $this->audit($request, 'addon.updated', 'addon', $id, ['price' => $data['price'], 'active' => $data['is_active']]);
        $this->flash($request, 'success', 'Add-on saved. Booked add-ons keep their price.');

        return $this->redirect('/admin/addons/' . $id);
    }

    public function queue(Request $request): Response
    {
        return $this->render($request, 'admin/addons/queue.twig', ['entries' => $this->addons->queue(), 'kinds' => AddonRepository::kinds()]);
    }

    public function markDone(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::SERVICES_MANAGE);
        $data = $this->validate($request, ['details' => 'nullable|string|max:1000']);
        try {
            $this->service->markDone($id, (string) ($data['details'] ?? ''), $this->user($request)->id, $request->clientIp());
            $this->flash($request, 'success', 'The add-on has been marked as set up.');
        } catch (ServiceChangeException $exception) {
            $this->flash($request, 'error', $exception->getMessage());
        }

        return $this->redirect('/admin/addon-queue');
    }

    public function markRemoved(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::SERVICES_MANAGE);
        $data = $this->validate($request, ['note' => 'nullable|string|max:500']);
        try {
            $this->service->markRemoved($id, (string) ($data['note'] ?? ''), $this->user($request)->id, $request->clientIp());
            $this->flash($request, 'success', 'The add-on has been marked as removed.');
        } catch (ServiceChangeException $exception) {
            $this->flash($request, 'error', $exception->getMessage());
        }

        return $this->redirect('/admin/addon-queue');
    }

    public function retry(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::SERVICES_MANAGE);
        try {
            $this->service->retry($id, $this->user($request)->id, $request->clientIp());
            $this->flash($request, 'success', 'The automatic set-up has been queued again.');
        } catch (ServiceChangeException $exception) {
            $this->flash($request, 'error', $exception->getMessage());
        }

        return $this->redirect('/admin/addon-queue');
    }

    /**
     * @param array<string, mixed>|null $addon
     */
    private function form(Request $request, ?array $addon): Response
    {
        $currency = (string) ($addon['currency'] ?? ($this->settings->string('general.default_currency') ?: 'EUR'));
        $products = [];
        foreach ($this->db->fetchAll("SELECT `id`, `name` FROM `nx_products` WHERE `category` <> 'domain' ORDER BY `is_active` DESC, `category`, `name` LIMIT 500") as $product) {
            $products[(string) $product['id']] = (string) $product['name'];
        }

        return $this->render($request, 'admin/addons/form.twig', [
            'addon' => $addon,
            'kinds' => AddonRepository::kinds(),
            'categories' => array_diff_key(ProductCategory::labels(), [ProductCategory::DOMAIN => true]),
            'products' => $products,
            'currencies' => array_combine($this->settings->list('billing.supported_currencies'), $this->settings->list('billing.supported_currencies')) ?: ['EUR' => 'EUR'],
            'price' => $addon !== null ? Money::toDecimal((int) $addon['price'], $currency) : '',
            'setup_fee' => $addon !== null ? Money::toDecimal((int) $addon['setup_fee'], $currency) : '0.00',
            'tax_rate' => $addon !== null ? number_format((int) $addon['tax_rate_bp'] / 100, 2, '.', '') : '19.00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        $currencies = implode(',', $this->settings->list('billing.supported_currencies') ?: ['EUR']);
        $data = $this->validate($request, [
            'name' => 'required|string|max:100|regex:/^[^<>]+$/',
            'description' => 'nullable|string|max:2000',
            'kind' => 'required|in:' . implode(',', array_keys(AddonRepository::kinds())),
            'price' => 'required|decimal|min:0',
            'setup_fee' => 'required|decimal|min:0',
            'currency' => 'required|in:' . $currencies,
            'tax_rate' => 'required|decimal|between:0,100',
            'max_quantity' => 'required|int|min:1|max:100',
            'product_ids' => 'nullable|array',
            'categories' => 'nullable|array',
            'sort_order' => 'nullable|int|min:-1000|max:1000',
            'is_active' => 'bool',
        ], ['name' => 'Name', 'price' => 'Price per month (net)', 'setup_fee' => 'Setup fee (net)', 'max_quantity' => 'Maximum quantity per service']);

        $productIds = array_values(array_unique(array_filter(array_map('intval', is_array($data['product_ids']) ? $data['product_ids'] : []), static fn (int $id): bool => $id > 0)));
        $categories = array_values(array_unique(array_filter(array_map('strval', is_array($data['categories']) ? $data['categories'] : []), static fn (string $c): bool => ProductCategory::exists($c) && $c !== ProductCategory::DOMAIN)));
        if ($productIds !== [] && (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_products` WHERE `id` IN (' . implode(',', $productIds) . ')') !== count($productIds)) {
            throw ValidationException::withMessages(['product_ids' => 'Choose existing products.']);
        }
        if ($productIds === [] && $categories === []) {
            throw ValidationException::withMessages(['product_ids' => 'Choose the products or categories that offer this add-on.']);
        }
        $currency = (string) $data['currency'];
        try {
            $price = Money::parse((string) $data['price'], $currency);
            $setup = Money::parse((string) $data['setup_fee'], $currency);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['price' => 'Enter an amount such as 1.50.']);
        }

        return [
            'name' => trim((string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')),
            'kind' => (string) $data['kind'],
            'price' => $price,
            'setup_fee' => $setup,
            'currency' => $currency,
            'tax_rate_bp' => (int) round((float) $data['tax_rate'] * 100),
            'max_quantity' => (int) $data['max_quantity'],
            'product_ids' => $productIds,
            'categories' => $categories,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => $data['is_active'] === true,
        ];
    }
}
