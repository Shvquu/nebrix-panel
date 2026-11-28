<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Authorization\Permission;
use Nebrix\Billing\Sales\SaleService;
use Nebrix\Catalog\ProductCategory;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\ValidationException;
use Nebrix\Settings\SettingsRepository;

/**
 * Global sales (Sales → Sales). Viewing needs products.view, changes
 * products.manage – like coupons.
 */
final class SaleController extends Controller
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
        private readonly Clock $clock,
    ) {
    }

    public function index(Request $request): Response
    {
        $sales = $this->sales->all();
        foreach ($sales as $key => $sale) {
            $sales[$key]['state'] = $this->sales->state($sale);
        }

        return $this->render($request, 'admin/sales/index.twig', ['sales' => $sales, 'categories' => ProductCategory::labels()]);
    }

    public function create(Request $request): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);

        return $this->form($request, null);
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->form($request, $this->sales->find($id) ?? throw HttpException::notFound());
    }

    public function store(Request $request): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);
        $id = $this->sales->save(null, $this->input($request), $this->user($request)->id);
        $this->audit($request, 'sale.created', 'sale', $id);
        $this->flash($request, 'success', 'Sale saved.');

        return $this->redirect('/admin/sales/' . $id);
    }

    public function update(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);
        $this->sales->find($id) ?? throw HttpException::notFound();
        $this->sales->save($id, $this->input($request), $this->user($request)->id);
        $this->audit($request, 'sale.updated', 'sale', $id);
        $this->flash($request, 'success', 'Sale saved.');

        return $this->redirect('/admin/sales/' . $id);
    }

    /**
     * @param array<string, mixed>|null $sale
     */
    private function form(Request $request, ?array $sale): Response
    {
        $products = [];
        foreach ($this->db->fetchAll("SELECT `id`, `name`, `category` FROM `nx_products` WHERE `category` <> 'domain' OR `sort_order` < 1000 ORDER BY `is_active` DESC, `category`, `name` LIMIT 500") as $product) {
            $products[(string) $product['id']] = $product['name'];
        }

        return $this->render($request, 'admin/sales/form.twig', [
            'sale' => $sale,
            'state' => $sale !== null ? $this->sales->state($sale) : null,
            'categories' => ProductCategory::labels(),
            'products' => $products,
            'starts_at' => $sale !== null ? $this->toLocal((string) $sale['starts_at']) : '',
            'ends_at' => $sale !== null ? $this->toLocal((string) $sale['ends_at']) : '',
        ]);
    }

    /**
     * @return array{name: string, banner_text: string|null, discount_bp: int, permanent: bool, scope: string, categories: list<string>, product_ids: list<int>, starts_at: string, ends_at: string, show_banner: bool, is_active: bool}
     */
    private function input(Request $request): array
    {
        $data = $this->validate($request, [
            'name' => 'required|string|max:100|regex:/^[^<>]+$/',
            'banner_text' => 'nullable|string|max:255',
            'percent' => 'required|decimal|between:1,90',
            'permanent' => 'required|in:1,0',
            'scope' => 'required|in:all,categories,products',
            'categories' => 'nullable|array',
            'product_ids' => 'nullable|array',
            'starts_at' => 'nullable|string|max:20',
            'ends_at' => 'required|string|max:20',
            'show_banner' => 'bool',
            'is_active' => 'bool',
        ], ['name' => 'Name', 'percent' => 'Discount (%)', 'ends_at' => 'End']);

        $errors = [];
        $categories = array_values(array_unique(array_filter(array_map('strval', is_array($data['categories']) ? $data['categories'] : []), static fn (string $c): bool => ProductCategory::exists($c))));
        $productIds = array_values(array_unique(array_filter(array_map('intval', is_array($data['product_ids']) ? $data['product_ids'] : []), static fn (int $id): bool => $id > 0)));
        if ($data['scope'] === SaleService::SCOPE_CATEGORIES && $categories === []) {
            $errors['categories'] = 'Choose at least one category.';
        }
        if ($data['scope'] === SaleService::SCOPE_PRODUCTS && ($productIds === [] || (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_products` WHERE `id` IN (' . implode(',', $productIds) . ')') !== count($productIds))) {
            $errors['product_ids'] = 'Choose existing products.';
        }
        $start = trim((string) ($data['starts_at'] ?? '')) !== '' ? $this->toUtc((string) $data['starts_at']) : $this->clock->nowString();
        $end = $this->toUtc((string) $data['ends_at']);
        if ($start === null) {
            $errors['starts_at'] = 'Enter a valid date and time.';
        }
        if ($end === null) {
            $errors['ends_at'] = 'Enter a valid date and time.';
        } elseif ($start !== null && $end <= $start) {
            $errors['ends_at'] = 'The end must be after the start.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => trim((string) $data['name']),
            'banner_text' => trim((string) ($data['banner_text'] ?? '')) !== '' ? trim((string) $data['banner_text']) : null,
            'discount_bp' => (int) round((float) $data['percent'] * 100),
            'permanent' => $data['permanent'] === '1',
            'scope' => (string) $data['scope'],
            'categories' => $categories,
            'product_ids' => $productIds,
            'starts_at' => (string) $start,
            'ends_at' => (string) $end,
            'show_banner' => $data['show_banner'] === true,
            'is_active' => $data['is_active'] === true,
        ];
    }

    private function toUtc(string $local): ?string
    {
        try {
            $time = new \DateTimeImmutable($local, $this->zone());
        } catch (\Exception) {
            return null;
        }

        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function toLocal(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($this->zone())->format('Y-m-d\TH:i');
    }

    private function zone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->settings->string('general.timezone') ?: 'UTC');
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }
}
