<?php

declare(strict_types=1);

namespace Nebrix\Controller\Customer;

use Nebrix\Billing\Cart\CartService;
use Nebrix\Billing\OrderException;
use Nebrix\Billing\OrderService;
use Nebrix\Billing\Coupons\CouponService;
use Nebrix\Catalog\BillingCycle;
use Nebrix\Catalog\ProductCategory;
use Nebrix\Catalog\ProductConfigurator;
use Nebrix\Catalog\ProductPricing;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Repository\ProductRepository;
use Nebrix\Security\RateLimiter;
use Nebrix\Service\Addon\AddonRepository;

final class StoreController extends Controller
{
    private const DOMAIN_CARDS = 12;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly OrderService $orders,
        private readonly RateLimiter $limiter,
        private readonly \Nebrix\Billing\Sales\SaleService $sales,
        private readonly AddonRepository $addons,
        private readonly CartService $carts,
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
            // Longer billing cycles with a discount: "save up to …".
            $cycles = ProductPricing::cycles($product);
            $product['cycle_count'] = count($cycles);
            $product['max_cycle_bp'] = $cycles !== [] ? max($cycles) : 0;
            // Section headings of the website ("# Network") are not repeated in the shop cards.
            $product['feature_list'] = array_values(array_filter(array_map('trim', explode("\n", (string) $product['features'])), static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')));
            $grouped[(string) $product['category']][] = $product;
        }

        return $this->render($request, 'customer/store/index.twig', [
            'grouped' => $grouped,
            'more_domains' => $moreDomains,
            'sale' => $this->sales->banner(),
            'groups' => $groups,
            'group' => $group,
            'search' => $search,
            'categories' => ProductCategory::labels(),
            'cycles' => BillingCycle::labels(),
            'cart_count' => $this->carts->count($user->id),
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

        // Editing an item of the cart: its choice is the starting point.
        $cartItem = null;
        if (ctype_digit($request->string('cart_item'))) {
            $cartItem = $this->carts->item($user->id, (int) $request->string('cart_item'));
            if ($cartItem === null || $cartItem['product_id'] !== (int) $product['id']) {
                throw HttpException::notFound();
            }
        }

        // A configuration chosen on the website or carried over by the coupon form.
        $configuration = [];
        foreach (ProductConfigurator::optionNames((string) $product['category']) as $name) {
            $value = mb_substr($request->string($name), 0, 10);
            $configuration[$name] = $value === '' && $cartItem !== null && isset($cartItem['configuration'][$name]) ? (string) $cartItem['configuration'][$name] : $value;
        }

        // Values carried over from the website (e.g. the domain found by the domain search).
        $prefill = [];
        foreach (ProductCategory::get((string) $product['category'])['options'] as $name => $definition) {
            $value = $request->query($name);
            if (($definition['secret'] ?? false) !== true && is_string($value) && strlen($value) <= 253) {
                $prefill[$name] = $value;
            } elseif ($cartItem !== null && ($definition['secret'] ?? false) !== true && isset($cartItem['input'][$name])) {
                $prefill[$name] = $cartItem['input'][$name];
            }
        }

        // Billing cycle: chosen here or on the website (only cycles the product offers).
        $offered = ProductPricing::cycles($product);
        $cycle = $request->string('cycle');
        if (!array_key_exists($cycle, $offered)) {
            $cycle = $cartItem !== null && array_key_exists($cartItem['billing_cycle'], $offered) ? $cartItem['billing_cycle'] : (string) $product['billing_cycle'];
        }
        $code = $coupon !== '' ? $coupon : null;
        $quote = $this->orders->quote($product, $user->customerGroupId, $user, $code, $configuration, false, $cycle);
        $cycleQuotes = [];
        if (count($offered) > 1) {
            $default = $this->orders->quote($product, $user->customerGroupId, $user, null, $configuration);
            $defaultMonthly = $default['recurring'] / max(1, BillingCycle::months((string) $product['billing_cycle']));
            foreach (array_keys($offered) as $offer) {
                $q = $offer === $cycle ? $quote : $this->orders->quote($product, $user->customerGroupId, $user, $code, $configuration, false, $offer);
                $months = max(1, BillingCycle::months($offer));
                $cycleQuotes[$offer] = [
                    'cycle' => $offer,
                    'recurring' => $q['recurring'],
                    'per_month' => intdiv($q['recurring'] * 2 + $months, 2 * $months),
                    'total' => $q['total'],
                    'saving_bp' => $offered[$offer],
                    'saving' => max(0, (int) round($defaultMonthly * $months) - $q['recurring']),
                ];
            }
        }

        $addons = [];
        foreach ($this->addons->offeredFor($product) as $addon) {
            if (BillingCycle::isRecurring($cycle)) {
                $addons[] = $addon + ['period_price' => (int) $addon['price'] * max(1, BillingCycle::months($cycle)), 'selected' => (int) ($cartItem['addons'][(int) $addon['id']] ?? 0)];
            }
        }

        return $this->render($request, 'customer/store/order.twig', [
            'product' => $product,
            'prefill' => $prefill,
            'options' => ProductCategory::get((string) $product['category'])['options'],
            'ranges' => ProductConfigurator::ranges($product),
            'quote' => $quote,
            // Domains: the first year of a transfer can cost something else than a registration.
            'transfer_quote' => $product['category'] === ProductCategory::DOMAIN ? $this->orders->quote($product, $user->customerGroupId, $user, $code, [], true) : null,
            'coupon' => $coupon,
            'cycles' => BillingCycle::labels(),
            'cycle' => $cycle,
            'cycle_quotes' => $cycleQuotes,
            'addons' => $addons,
            'cart_item' => $cartItem,
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

        $options = self::optionsFrom($request, $product);
        $cycle = $request->string('cycle') !== '' ? $request->string('cycle') : null;

        try {
            $coupon = mb_substr(trim($request->string('coupon')), 0, 40);
            $result = $this->orders->place($user, (int) $product['id'], $options, $request->clientIp(), $coupon !== '' ? $coupon : null, $cycle, self::addonsFrom($request));
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
     * The product options and configurator values of an order form.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public static function optionsFrom(Request $request, array $product): array
    {
        $options = [];
        foreach (array_keys(ProductCategory::get((string) $product['category'])['options']) as $name) {
            $options[$name] = $request->input($name);
        }
        if (ProductConfigurator::enabled($product)) {
            foreach (ProductConfigurator::optionNames((string) $product['category']) as $name) {
                $options[$name] = $request->input($name);
            }
        }

        return $options;
    }

    /**
     * Add-ons of an order form: addons[ID] = quantity (validated by the order service).
     *
     * @return array<int|string, mixed>
     */
    public static function addonsFrom(Request $request): array
    {
        $addons = $request->input('addons');

        return is_array($addons) ? array_slice($addons, 0, 20, true) : [];
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
