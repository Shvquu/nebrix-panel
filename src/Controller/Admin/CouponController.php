<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Authorization\Permission;
use Nebrix\Billing\Coupons\CouponService;
use Nebrix\Billing\Money;
use Nebrix\Database\Connection;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\ValidationException;
use Nebrix\Settings\SettingsRepository;

/**
 * Coupon codes (Sales → Coupons). Viewing needs products.view, changes
 * products.manage.
 */
final class CouponController extends Controller
{
    public function __construct(
        private readonly CouponService $coupons,
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render($request, 'admin/coupons/index.twig', ['coupons' => $this->coupons->all()]);
    }

    public function create(Request $request): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);

        return $this->form($request, null);
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->form($request, $this->coupons->find($id) ?? throw HttpException::notFound());
    }

    public function store(Request $request): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);
        $id = $this->coupons->save(null, $this->input($request, null), $this->user($request)->id);
        $this->audit($request, 'coupon.created', 'coupon', $id);
        $this->flash($request, 'success', 'Coupon saved.');

        return $this->redirect('/admin/coupons/' . $id);
    }

    public function update(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::PRODUCTS_MANAGE);
        $this->coupons->find($id) ?? throw HttpException::notFound();
        $this->coupons->save($id, $this->input($request, $id), $this->user($request)->id);
        $this->audit($request, 'coupon.updated', 'coupon', $id);
        $this->flash($request, 'success', 'Coupon saved.');

        return $this->redirect('/admin/coupons/' . $id);
    }

    /**
     * @param array<string, mixed>|null $coupon
     */
    private function form(Request $request, ?array $coupon): Response
    {
        $products = [];
        foreach ($this->db->fetchAll('SELECT `id`, `name`, `currency` FROM `nx_products` ORDER BY `is_active` DESC, `name` LIMIT 500') as $product) {
            $products[(string) $product['id']] = $product['name'] . ' (' . $product['currency'] . ')';
        }
        $currency = $this->settings->string('general.default_currency') ?: 'EUR';

        return $this->render($request, 'admin/coupons/form.twig', [
            'coupon' => $coupon,
            'products' => $products,
            'selected_products' => $coupon !== null ? array_values(array_filter(explode(',', (string) $coupon['product_ids']))) : [],
            'amount' => $coupon === null ? '' : ($coupon['type'] === CouponService::PERCENT
                ? rtrim(rtrim(number_format((int) $coupon['value'] / 100, 2, '.', ''), '0'), '.')
                : Money::toDecimal((int) $coupon['value'], (string) ($coupon['currency'] ?? $currency))),
            'default_currency' => $currency,
            'redemptions' => $coupon !== null ? $this->coupons->redemptions((int) $coupon['id']) : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request, ?int $id): array
    {
        $data = $this->validate($request, [
            'code' => 'required|string|max:32',
            'description' => 'nullable|string|max:255',
            'type' => 'required|in:percent,fixed',
            'amount' => 'required|string|max:20',
            'currency' => 'nullable|currency',
            'duration' => 'required|in:once,forever',
            'product_ids' => 'nullable|array',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date',
            'max_uses' => 'nullable|int|min:1|max:1000000',
            'max_uses_per_customer' => 'required|int|min:1|max:1000',
            'new_customers_only' => 'bool',
            'is_active' => 'bool',
        ]);

        $code = CouponService::normalize((string) $data['code']);
        $errors = [];
        if (!CouponService::isValidCode($code)) {
            $errors['code'] = 'Use 3–32 letters, digits, dashes or underscores.';
        } elseif ($this->db->fetchValue('SELECT `id` FROM `nx_coupons` WHERE `code` = ? AND `id` <> ?', [$code, $id ?? 0]) !== null) {
            $errors['code'] = 'This code already exists.';
        }
        $amount = trim(str_replace(',', '.', (string) $data['amount']));
        $value = 0;
        if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $amount) !== 1) {
            $errors['amount'] = 'Enter a number, e.g. 10 or 5.50.';
        } elseif ($data['type'] === CouponService::PERCENT) {
            $value = (int) round((float) $amount * 100);
            if ($value < 1 || $value > 10000) {
                $errors['amount'] = 'A percentage must be between 0.01 and 100.';
            }
        } else {
            $value = Money::parse($amount, (string) ($data['currency'] ?? 'EUR'));
            if ($value < 1) {
                $errors['amount'] = 'The amount must be greater than zero.';
            }
            if (($data['currency'] ?? null) === null) {
                $errors['currency'] = 'Choose the currency of the amount.';
            }
        }
        $from = $data['valid_from'] !== null ? (string) $data['valid_from'] . ' 00:00:00' : null;
        $until = $data['valid_until'] !== null ? (string) $data['valid_until'] . ' 23:59:59' : null;
        if ($from !== null && $until !== null && $until < $from) {
            $errors['valid_until'] = 'The end date must be after the start date.';
        }
        $productIds = array_values(array_filter(array_map('intval', is_array($data['product_ids']) ? $data['product_ids'] : []), static fn (int $id): bool => $id > 0));
        if ($productIds !== [] && (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_products` WHERE `id` IN (' . implode(',', $productIds) . ')') !== count(array_unique($productIds))) {
            $errors['product_ids'] = 'Choose existing products.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'code' => $code, 'description' => $data['description'], 'type' => $data['type'], 'value' => $value, 'currency' => $data['currency'],
            'duration' => $data['duration'], 'product_ids' => array_values(array_unique($productIds)), 'valid_from' => $from, 'valid_until' => $until,
            'max_uses' => $data['max_uses'], 'max_uses_per_customer' => $data['max_uses_per_customer'],
            'new_customers_only' => $data['new_customers_only'] === true, 'is_active' => $data['is_active'] === true,
        ];
    }
}
