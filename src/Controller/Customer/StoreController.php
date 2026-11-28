<?php

declare(strict_types=1);

namespace Nebrix\Controller\Customer;

use Nebrix\Billing\OrderException;
use Nebrix\Billing\OrderService;
use Nebrix\Billing\Coupons\CouponService;
use Nebrix\Catalog\BillingCycle;
use Nebrix\Catalog\ProductCategory;
use Nebrix\Catalog\ProductConfigurator;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Repository\ProductRepository;
use Nebrix\Security\RateLimiter;

final class StoreController extends Controller
{
    private const DOMAIN_CARDS = 12;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly OrderService $orders,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $groups = ProductCategory::groups();
        $group = $request->string('group');
        $group = array_key_exists($group, $groups) ? $group : '';
        $search = mb_substr(trim($request->string('search')), 0, 100);
        $category = $request->string('category');
        $category = array_key_exists($category, ProductCategory::labels()) ? $category : '';
        $grouped = [];
        $moreDomains = 0;
        foreach ($this->products->orderable($user->customerGroupId) as $product) {
            if ($group !== '' && !in_array((string) $product['category'], $groups[$group]['categories'], true)) {
                continue;
            }
            if ($category !== '' && $product['category'] !== $category) {
                continue;
            }
            if ($search !== '' && mb_stripos($product['name'] . ' ' . $product['description'] . ' ' . $product['features'], $search) === false) {
                continue;
            }
            // Hundreds of imported TLDs: the most popular ones as cards, the rest through the search.
            if ($search === '' && $product['category'] === ProductCategory::DOMAIN && count($grouped[ProductCategory::DOMAIN] ?? []) >= self::DOMAIN_CARDS) {
                $moreDomains++;
                continue;
            }
            $product['quote'] = $this->orders->quote($product, $user->customerGroupId, $user);
            // Section headings of the website ("# Network") are not repeated in the shop cards.
            $product['feature_list'] = array_values(array_filter(array_map('trim', explode("\n", (string) $product['features'])), static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')));
            $grouped[(string) $product['category']][] = $product;
        }

        return $this->render($request, 'customer/store/index.twig', [
            'grouped' => $grouped,
            'more_domains' => $moreDomains,
            'groups' => $groups,
            'group' => $group,
            'search' => $search,
            'categories' => ProductCategory::labels(),
            'cycles' => BillingCycle::labels(),
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $user = $this->user($request);
        $product = $this->findOrderable($slug, $user->customerGroupId);
        $coupon = mb_substr(trim($request->string('coupon')), 0, 40);
        // Checking codes is limited, so that codes cannot be guessed by trying.
        if ($coupon !== '' && !$this->limiter->attempt('coupon-check:' . $user->id, 20, 3600)) {
            throw HttpException::tooManyRequests('Too many coupon checks. Please try again later.');
        }

        // A configuration chosen on the website or carried over by the coupon form.
        $configuration = [];
        foreach (ProductConfigurator::optionNames((string) $product['category']) as $name) {
            $configuration[$name] = mb_substr($request->string($name), 0, 10);
        }

        // Values carried over from the website (e.g. the domain found by the domain search).
        $prefill = [];
        foreach (ProductCategory::get((string) $product['category'])['options'] as $name => $definition) {
            $value = $request->query($name);
            if (($definition['secret'] ?? false) !== true && is_string($value) && strlen($value) <= 253) {
                $prefill[$name] = $value;
            }
        }

        return $this->render($request, 'customer/store/order.twig', [
            'product' => $product,
            'prefill' => $prefill,
            'options' => ProductCategory::get((string) $product['category'])['options'],
            'ranges' => ProductConfigurator::ranges($product),
            'quote' => $this->orders->quote($product, $user->customerGroupId, $user, $coupon !== '' ? $coupon : null, $configuration),
            // Domains: the first year of a transfer can cost something else than a registration.
            'transfer_quote' => $product['category'] === ProductCategory::DOMAIN ? $this->orders->quote($product, $user->customerGroupId, $user, $coupon !== '' ? $coupon : null, [], true) : null,
            'coupon' => $coupon,
            'cycles' => BillingCycle::labels(),
            'require_legal' => true,
        ]);
    }

    public function order(Request $request, string $slug): Response
    {
        $user = $this->user($request);
        $product = $this->findOrderable($slug, $user->customerGroupId);
        $this->validate($request, ['accept_terms' => 'bool']);
        if ($request->input('accept_terms') !== '1') {
            throw \Nebrix\Security\ValidationException::withMessages(['accept_terms' => 'Please accept the terms to place the order.']);
        }

        $key = 'order:' . $user->id;
        if (!$this->limiter->attempt($key, 10, 3600)) {
            throw HttpException::tooManyRequests('You have placed many orders in a short time. Please try again later.');
        }

        $options = [];
        foreach (array_keys(ProductCategory::get((string) $product['category'])['options']) as $name) {
            $options[$name] = $request->input($name);
        }
        if (ProductConfigurator::enabled($product)) {
            foreach (ProductConfigurator::optionNames((string) $product['category']) as $name) {
                $options[$name] = $request->input($name);
            }
        }

        try {
            $coupon = mb_substr(trim($request->string('coupon')), 0, 40);
            $result = $this->orders->place($user, (int) $product['id'], $options, $request->clientIp(), $coupon !== '' ? $coupon : null);
        } catch (\Nebrix\Billing\Coupons\CouponException $exception) {
            // Back to the order page: the customer can correct or remove the code.
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/store/' . $product['slug'] . $this->configurationQuery($product, $options));
        } catch (OrderException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/store');
        }

        $this->audit($request, 'order.placed', 'order', $result['order_id'], ['product' => (int) $product['id'], 'coupon' => $coupon !== '' ? CouponService::normalize($coupon) : null]);
        $this->flash($request, 'success', $result['paid']
            ? 'Thank you for your order! Your service is being set up.'
            : 'Thank you for your order! Please pay the invoice to start the setup.');

        return $this->redirect('/invoices/' . $result['invoice_id']);
    }

    /**
     * Keeps the chosen configuration when the customer is sent back to the order page.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $options
     */
    private function configurationQuery(array $product, array $options): string
    {
        if (!ProductConfigurator::enabled($product)) {
            return '';
        }
        $query = [];
        foreach (ProductConfigurator::optionNames((string) $product['category']) as $name) {
            if (is_string($options[$name] ?? null) && preg_match('/^\d{1,6}$/', $options[$name]) === 1) {
                $query[$name] = $options[$name];
            }
        }

        return $query !== [] ? '?' . http_build_query($query) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrderable(string $slug, ?int $groupId): array
    {
        $product = $this->products->findBySlug($slug);
        if ($product === null || !$product['is_active'] || !$product['is_visible']
            || ($product['customer_group_id'] !== null && (int) $product['customer_group_id'] !== $groupId)
            || ($product['stock'] !== null && (int) $product['stock'] <= 0)) {
            throw HttpException::notFound();
        }

        return $product;
    }
}
