<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Authorization\Permission;
use Nebrix\Billing\Bundles\BundleService;
use Nebrix\Billing\Money;
use Nebrix\Catalog\ProductCategory;
use Nebrix\Database\Connection;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\ValidationException;
use Nebrix\Settings\SettingsRepository;

/**
 * Bundles (Sales → Bundles). Viewing needs products.view, changes
 * products.manage – like sales and coupons. Changes are audit-logged.
 */
final class BundleController extends Controller
{
    public function __construct(
        private readonly BundleService $bundles,
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function index(Request $request): Response
    {
        $bundles = $this->bundles->all();
        foreach ($bundles as $key => $bundle) {
            $bundles[$key]['state'] = $this->bundles->state($bundle);
        }

        return $this->render($request, 'admin/bundles/index.twig', ['bundles' => $bundles, 'categories' => ProductCategory::labels(), 'products' => $this->productNames()]);
    }

    public function create(Request $request): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);

        return $this->form($request, null);
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->form($request, $this->bundles->find($id) ?? throw HttpException::notFound());
    }

    public function store(Request $request): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);
        $id = $this->bundles->save(null, $this->input($request), $this->user($request)->id);
        $this->audit($request, 'bundle.created', 'bundle', $id);
        $this->flash($request, 'success', 'Bundle saved.');

        return $this->redirect('/admin/bundles/' . $id);
    }

    public function update(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);
        $this->bundles->find($id) ?? throw HttpException::notFound();
        $this->bundles->save($id, $this->input($request), $this->user($request)->id);
        $this->audit($request, 'bundle.updated', 'bundle', $id);
        $this->flash($request, 'success', 'Bundle saved.');

        return $this->redirect('/admin/bundles/' . $id);
    }

    /**
     * @param array<string, mixed>|null $bundle
     */
    private function form(Request $request, ?array $bundle): Response
    {
        $products = [];
        $categories = [];
        foreach ($bundle['components'] ?? [] as $component) {
            if (isset($component['product_id'])) {
                $products[] = (string) $component['product_id'];
            } elseif (isset($component['category'])) {
                $categories[] = (string) $component['category'];
            }
        }
        $currency = (string) ($bundle['currency'] ?? ($this->settings->string('general.default_currency') ?: 'EUR'));

        return $this->render($request, 'admin/bundles/form.twig', [
            'bundle' => $bundle,
            'state' => $bundle !== null ? $this->bundles->state($bundle) : null,
            'categories' => ProductCategory::labels(),
            'products' => $this->productNames(),
            'selected_products' => $products,
            'selected_categories' => $categories,
            'amount' => $bundle !== null ? ($bundle['discount_type'] === BundleService::PERCENT ? (string) $bundle['percent'] : Money::toDecimal((int) $bundle['discount_value'], $currency)) : '',
            'currencies' => array_combine($this->settings->list('billing.supported_currencies'), $this->settings->list('billing.supported_currencies')) ?: ['EUR' => 'EUR'],
            'currency' => $currency,
            'starts_at' => $this->toLocal($bundle['starts_at'] ?? null),
            'ends_at' => $this->toLocal($bundle['ends_at'] ?? null),
        ]);
    }

    /**
     * @return array{name: string, description: string|null, components: list<array{product_id?: int, category?: string}>, discount_type: string, discount_value: int, currency: string|null, permanent: bool, starts_at: string|null, ends_at: string|null, is_active: bool}
     */
    private function input(Request $request): array
    {
        $currencies = implode(',', $this->settings->list('billing.supported_currencies') ?: ['EUR']);
        $data = $this->validate($request, [
            'name' => 'required|string|max:100|regex:/^[^<>]+$/',
            'description' => 'nullable|string|max:255|regex:/^[^<>]*$/',
            'product_ids' => 'nullable|array',
            'categories' => 'nullable|array',
            'discount_type' => 'required|in:percent,fixed',
            'amount' => 'required|decimal|min:0',
            'currency' => 'nullable|in:' . $currencies,
            'permanent' => 'required|in:1,0',
            'starts_at' => 'nullable|string|max:20',
            'ends_at' => 'nullable|string|max:20',
            'is_active' => 'bool',
        ], ['name' => 'Name', 'amount' => 'Discount', 'discount_type' => 'Discount type']);

        $errors = [];
        $productIds = array_values(array_unique(array_filter(array_map('intval', is_array($data['product_ids']) ? $data['product_ids'] : []), static fn (int $id): bool => $id > 0)));
        $categories = array_values(array_unique(array_filter(array_map('strval', is_array($data['categories']) ? $data['categories'] : []), static fn (string $c): bool => ProductCategory::exists($c))));
        if ($productIds !== [] && (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_products` WHERE `id` IN (' . implode(',', $productIds) . ')') !== count($productIds)) {
            $errors['product_ids'] = 'Choose existing products.';
        }
        $components = [...array_map(static fn (int $id): array => ['product_id' => $id], $productIds), ...array_map(static fn (string $category): array => ['category' => $category], $categories)];
        if (count($components) < 2 || count($components) > BundleService::MAX_COMPONENTS) {
            $errors['product_ids'] = 'A bundle needs 2 to 5 products or categories.';
        }

        $percent = $data['discount_type'] === BundleService::PERCENT;
        $currency = (string) ($data['currency'] ?? '');
        $value = 0;
        if ($percent) {
            $value = (int) round((float) $data['amount'] * 100);
            if ($value < 100 || $value > BundleService::MAX_BP) {
                $errors['amount'] = 'Enter a discount between 1 and 90 %.';
            }
        } else {
            if ($currency === '') {
                $errors['currency'] = 'Choose the currency of the amount.';
            } else {
                try {
                    $value = Money::parse((string) $data['amount'], $currency);
                } catch (\InvalidArgumentException) {
                    $value = 0;
                }
                if ($value <= 0) {
                    $errors['amount'] = 'Enter an amount above 0.';
                }
            }
        }
        $start = trim((string) ($data['starts_at'] ?? '')) !== '' ? $this->toUtc((string) $data['starts_at']) : null;
        $end = trim((string) ($data['ends_at'] ?? '')) !== '' ? $this->toUtc((string) $data['ends_at']) : null;
        if (trim((string) ($data['starts_at'] ?? '')) !== '' && $start === null) {
            $errors['starts_at'] = 'Enter a valid date and time.';
        }
        if (trim((string) ($data['ends_at'] ?? '')) !== '' && $end === null) {
            $errors['ends_at'] = 'Enter a valid date and time.';
        } elseif ($start !== null && $end !== null && $end <= $start) {
            $errors['ends_at'] = 'The end must be after the start.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => trim((string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')) !== '' ? trim((string) $data['description']) : null,
            'components' => $components,
            'discount_type' => $percent ? BundleService::PERCENT : BundleService::FIXED,
            'discount_value' => $value,
            'currency' => $percent ? null : $currency,
            'permanent' => $data['permanent'] === '1',
            'starts_at' => $start,
            'ends_at' => $end,
            'is_active' => $data['is_active'] === true,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function productNames(): array
    {
        $products = [];
        foreach ($this->db->fetchAll("SELECT `id`, `name` FROM `nx_products` WHERE `category` <> 'domain' OR `sort_order` < 1000 ORDER BY `is_active` DESC, `category`, `name` LIMIT 500") as $product) {
            $products[(string) $product['id']] = (string) $product['name'];
        }

        return $products;
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

    private function toLocal(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }

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
