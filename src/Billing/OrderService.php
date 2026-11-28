<?php

declare(strict_types=1);

namespace Nebrix\Billing;

use Nebrix\Auth\User;
use Nebrix\Catalog\BillingCycle;
use Nebrix\Catalog\ProductCategory;
use Nebrix\Catalog\ProductConfigurator;
use Nebrix\Catalog\ProductPricing;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Provisioning\OrderPrechecks;
use Nebrix\Security\Encrypter;
use Nebrix\Mail\Mailer;
use Nebrix\Payment\PaymentService;
use Nebrix\Repository\CustomerGroupRepository;
use Nebrix\Repository\ProductRepository;
use Nebrix\Security\ValidationException;
use Nebrix\Security\Validator;
use Nebrix\Service\Addon\AddonRepository;
use RuntimeException;

/**
 * Places orders. Prices, tax and discounts are always computed on the
 * server from the product and customer group – never taken from the request.
 *
 * An order has one or more lines (one product each – the store's "buy now"
 * or the cart) with their add-ons. Inside the order transaction the
 * products are locked, stock is checked and taken for all lines at once,
 * every price is calculated again and the coupon is checked with its row
 * locked; any problem leaves no order behind.
 *
 * Price of a line: list price of the chosen billing cycle (configured price
 * for the configurator) − promotion or global sale (the higher one) or a
 * bundle replacing them (if it saves more) − customer-group discount −
 * coupon. Domains: first-year price instead. Add-ons: monthly price ×
 * months of the cycle − group discount.
 */
class OrderService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly CustomerGroupRepository $groups,
        private readonly OrderRepository $orders,
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
        private readonly Mailer $mailer,
        private readonly Connection $db,
        private readonly Config $config,
        private readonly Encrypter $encrypter,
        private readonly OrderPrechecks $prechecks,
        private readonly \Nebrix\Billing\Tax\TaxService $taxes,
        private readonly \Nebrix\View\Translator $translator,
        private readonly \Nebrix\Billing\Coupons\CouponService $coupons,
        private readonly \Nebrix\Core\Clock $clock,
        private readonly Sales\SaleService $sales,
        private readonly Bundles\BundleService $bundles,
        private readonly AddonRepository $addons,
    ) {
    }

    /**
     * Net price parts of one order item, before coupons.
     *
     * - list: list price of the billing cycle (configured price for the configurator)
     * - promo/promo_bp: running promotion or permanent sale (first payment and every renewal)
     * - first_promo: a sale for the first billing period only (on top of promo, up to the sale's percentage)
     * - group: customer-group discount on the promoted price
     * - first_extra/first_discount: domain first year (registration/transfer) above or below the renewal price
     *
     * @param array<string, mixed> $product
     * @param array{price: int}|null $configuration
     * @return array{list: int, promo_bp: int, promo: int, first_promo: int, first_bp: int, sale: array<string, mixed>|null, group: int, first_extra: int, first_discount: int, setup: int}
     */
    private function priceParts(array $product, ?array $configuration, int $groupDiscountBp, bool $transfer, ?string $cycle = null): array
    {
        $cycle ??= (string) $product['billing_cycle'];
        // Promotions, sales and the group discount are percentages of the price of the chosen cycle.
        $list = ProductPricing::cyclePrice($product, $cycle, $configuration['price'] ?? (int) $product['price']);
        $discounts = ProductPricing::discounts($product, Sales\SaleService::bestFor($this->sales->running(), $product), $this->clock->now());
        $promo = Money::discount($list, $discounts['permanent_bp']);
        $group = Money::discount($list - $promo, $groupDiscountBp);
        $renewalNet = $list - $promo - $group;
        $first = ProductPricing::domainFirstYear($product, $transfer);
        $delta = $first !== null ? $first - $renewalNet : 0;
        // A domain's own first-year price is already the special offer: a first-period sale does not add to it.
        $firstPromo = $first === null ? Money::discount($list, $discounts['first_bp']) - $promo : 0;

        return [
            'list' => $list, 'promo_bp' => $discounts['permanent_bp'], 'promo' => $promo, 'first_promo' => max(0, $firstPromo),
            'first_bp' => $discounts['first_bp'], 'sale' => $discounts['sale'], 'group' => $group,
            'first_extra' => max(0, $delta), 'first_discount' => max(0, -$delta), 'setup' => (int) $product['setup_fee'],
        ];
    }

    /**
     * "Buy now": one product with its add-ons in one order.
     *
     * @param array<string, mixed> $input customer-supplied product options
     * @param array<int|string, mixed> $addons add-on ID => quantity
     * @return array{order_id: int, invoice_id: int, paid: bool}
     *
     * @throws ValidationException
     * @throws OrderException
     */
    public function place(User $user, int $productId, array $input, string $ip, ?string $couponCode = null, ?string $cycle = null, array $addons = []): array
    {
        $product = $this->orderable($user, $productId);
        $this->assertMayOrder($user);
        $line = $this->prepareLine($user, $product, $input, $cycle, $addons);

        return $this->submit($user, [$line], $ip, $couponCode);
    }

    /**
     * Checkout of several lines (the cart): one order, one invoice.
     *
     * @param list<array{product_id: int, input: array<string, mixed>, cycle: string|null, addons: array<int|string, mixed>}> $items
     * @param callable(): void|null $inTransaction runs inside the order transaction after the order was created (e.g. empty the cart)
     * @param int|null $expectedTotal the total the customer confirmed: the order is refused when the prices changed since
     * @return array{order_id: int, invoice_id: int, paid: bool}
     *
     * @throws ValidationException
     * @throws OrderException
     */
    public function checkout(User $user, array $items, string $ip, ?string $couponCode = null, ?callable $inTransaction = null, ?int $expectedTotal = null): array
    {
        if ($items === []) {
            throw new OrderException('Your cart is empty.');
        }
        $products = [];
        foreach ($items as $item) {
            $products[] = $this->orderable($user, $item['product_id']);
        }
        $this->assertMayOrder($user);
        $lines = [];
        foreach ($items as $index => $item) {
            $lines[] = $this->prepareLine($user, $products[$index], $item['input'], $item['cycle'], $item['addons']);
        }

        return $this->submit($user, $lines, $ip, $couponCode, $inTransaction, $expectedTotal);
    }

    /**
     * Cart preview: every item checked like at checkout (without the
     * provisioners' prechecks) and priced; items that cannot be ordered as
     * they are get a message instead.
     *
     * @param array<int, array{product_id: int, input: array<string, mixed>, cycle: string|null, addons: array<int|string, mixed>}> $items by cart item ID
     * @return array{lines: array<int, array<string, mixed>>, problems: array<int, string>, prices: array<string, mixed>|null, coupon_error: string|null}
     */
    public function preview(User $user, array $items, ?string $couponCode): array
    {
        $lines = [];
        $problems = [];
        $units = [];
        foreach ($items as $key => $item) {
            try {
                $product = $this->orderable($user, $item['product_id']);
                $units[(int) $product['id']] = ($units[(int) $product['id']] ?? 0) + 1;
                if ($product['stock'] !== null && (int) $product['stock'] < $units[(int) $product['id']]) {
                    throw new OrderException('This product is sold out.');
                }
                $lines[$key] = $this->prepareLine($user, $product, $item['input'], $item['cycle'], $item['addons'], false);
            } catch (OrderException $exception) {
                $problems[$key] = $exception->getMessage();
            } catch (ValidationException $exception) {
                $problems[$key] = $exception->first();
            }
        }
        if ($lines === []) {
            return ['lines' => [], 'problems' => $problems, 'prices' => null, 'coupon_error' => null];
        }

        $keys = array_keys($lines);
        $list = array_values($lines);
        $couponError = null;
        $code = $couponCode !== null && trim($couponCode) !== '' ? $couponCode : null;
        try {
            try {
                $prices = $this->calculate($user, $list, $code !== null ? fn (array $couponLines): array => $this->coupons->evaluateLines($code, $couponLines, $user) : null);
            } catch (Coupons\CouponException $exception) {
                $couponError = $exception->getMessage();
                $prices = $this->calculate($user, $list);
            }
        } catch (OrderException $exception) {
            // e.g. products in different currencies
            return ['lines' => [], 'problems' => $problems + array_fill_keys($keys, $exception->getMessage()), 'prices' => null, 'coupon_error' => null];
        }
        $priced = [];
        foreach ($prices['lines'] as $position => $line) {
            $priced[$keys[$position]] = $line;
        }
        $prices['lines'] = $priced;

        return ['lines' => $priced, 'problems' => $problems, 'prices' => $prices, 'coupon_error' => $couponError];
    }

    /**
     * A product the customer may order (active, visible, group).
     *
     * @return array<string, mixed>
     *
     * @throws OrderException
     */
    public function orderable(User $user, int $productId): array
    {
        $product = $this->products->find($productId);
        if ($product === null || !self::available($product, $user)) {
            throw new OrderException('This product is not available.');
        }

        return $product;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function available(array $product, User $user): bool
    {
        return (bool) $product['is_active'] && (bool) $product['is_visible']
            && ($product['customer_group_id'] === null || (int) $product['customer_group_id'] === $user->customerGroupId);
    }

    /**
     * Group may order, balance not negative.
     *
     * @throws OrderException
     */
    public function assertMayOrder(User $user): void
    {
        $group = $user->customerGroupId !== null ? $this->groups->find($user->customerGroupId) : null;
        if ($group !== null && !(bool) $group['can_order']) {
            throw new OrderException('Your account is not allowed to place orders. Please contact support.');
        }

        if ((int) $this->db->fetchValue('SELECT `credit_balance` FROM `nx_users` WHERE `id` = ?', [$user->id]) < 0) {
            throw new OrderException('Your account has a negative balance. Please settle it before placing new orders.');
        }
    }

    /**
     * Validates one line: options (secret ones encrypted), configurator
     * choice, billing cycle, add-ons and – unless switched off for a cart
     * preview – the provisioner's order precheck.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $input
     * @param array<int|string, mixed> $addons add-on ID => quantity
     * @return array{product: array<string, mixed>, options: array<string, mixed>, configuration: array<string, int>|null, cycle: string, transfer: bool, addons: list<array{addon: array<string, mixed>, quantity: int}>}
     *
     * @throws ValidationException
     * @throws OrderException
     */
    public function prepareLine(User $user, array $product, array $input, ?string $cycle, array $addons = [], bool $precheck = true): array
    {
        $category = ProductCategory::get((string) $product['category']);
        $options = Validator::validate($input, array_map(static fn (array $o): string => $o['rules'], $category['options']), array_map(static fn (array $o): string => $o['label'], $category['options']));
        // Secret options (e.g. a domain transfer auth code) are never stored in plaintext.
        foreach ($category['options'] as $name => $definition) {
            if (($definition['secret'] ?? false) === true && is_string($options[$name] ?? null) && $options[$name] !== '') {
                $options[$name] = ['encrypted' => $this->encrypter->encrypt($options[$name], 'order-option:' . $name)];
            }
        }
        // Configurable servers: the customer's resources (validated) and their price.
        $configuration = ProductConfigurator::enabled($product) ? ProductConfigurator::resolve($product, $input) : null;
        if ($configuration !== null) {
            foreach (ProductConfigurator::optionNames((string) $product['category']) as $name) {
                $options[$name] = $configuration[$name];
            }
        }
        $cycle = $cycle !== null && $cycle !== '' ? $cycle : (string) $product['billing_cycle'];
        if (!ProductPricing::offersCycle($product, $cycle)) {
            throw new OrderException('This billing cycle is not available for this product.');
        }
        $booked = $this->validateAddons($product, $addons, $cycle);
        if ($precheck) {
            $this->prechecks->check($product, $user, $options);
        }
        $transfer = is_array($options['auth_code'] ?? null) || (is_string($options['auth_code'] ?? null) && $options['auth_code'] !== '');

        return ['product' => $product, 'options' => $options, 'configuration' => $configuration, 'cycle' => $cycle, 'transfer' => $transfer, 'addons' => $booked];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int|string, mixed> $addons add-on ID => quantity (0 = not booked)
     * @return list<array{addon: array<string, mixed>, quantity: int}>
     *
     * @throws OrderException
     */
    private function validateAddons(array $product, array $addons, string $cycle): array
    {
        $booked = [];
        foreach ($addons as $id => $quantity) {
            $quantity = is_int($quantity) || (is_string($quantity) && preg_match('/^\d{1,3}$/', $quantity) === 1) ? (int) $quantity : -1;
            if ($quantity === 0) {
                continue;
            }
            $addon = is_int($id) || ctype_digit((string) $id) ? $this->addons->find((int) $id) : null;
            if ($addon === null || $quantity < 1 || $quantity > (int) $addon['max_quantity'] || !AddonRepository::offers($addon, $product) || !BillingCycle::isRecurring($cycle)) {
                throw new OrderException('This add-on is not available for this product.');
            }
            $booked[] = ['addon' => $addon, 'quantity' => $quantity];
        }

        return $booked;
    }

    /**
     * Prices of prepared lines (cart preview and checkout): every line with
     * its parts, the bundle, the coupon and the totals.
     *
     * @param list<array{product: array<string, mixed>, options: array<string, mixed>, configuration: array<string, int>|null, cycle: string, transfer: bool, addons: list<array{addon: array<string, mixed>, quantity: int}>}> $lines
     * @param (callable(array<int, array{product: array<string, mixed>, base: int, setup: int}>): array{coupon: array<string, mixed>, lines: array<int|string, array{first: int, recurring: int}>, first: int, recurring: int})|null $coupon
     * @return array{lines: list<array<string, mixed>>, bundle: array<string, mixed>|null, bundle_discount: int, coupon: array<string, mixed>|null, coupon_discount: int, subtotal: int, discount: int, net: int, tax: int, total: int, currency: string}
     */
    public function calculate(User $user, array $lines, ?callable $coupon = null): array
    {
        $group = $user->customerGroupId !== null ? $this->groups->find($user->customerGroupId) : null;
        $groupBp = $group !== null ? (int) $group['discount_bp'] : 0;
        $decision = $this->taxes->decide($user);
        $currency = (string) ($lines[0]['product']['currency'] ?? 'EUR');

        $rows = [];
        $candidates = [];
        foreach ($lines as $index => $line) {
            if (strtoupper((string) $line['product']['currency']) !== strtoupper($currency)) {
                throw new OrderException('All products of an order must have the same currency.');
            }
            $parts = $this->priceParts($line['product'], $line['configuration'], $groupBp, $line['transfer'], $line['cycle']);
            $rows[$index] = [
                'parts' => $parts,
                'permanent' => $parts['promo'],
                'first' => $parts['first_promo'],
                'group' => $parts['group'],
                'promo_bp' => $parts['promo_bp'],
                'first_bp' => $parts['first_bp'],
                'sale' => $parts['sale'],
                'bundle' => false,
            ];
            // Domains with their own first-year price take part in no bundle.
            if (ProductPricing::domainFirstYear($line['product'], $line['transfer']) === null) {
                $candidates[$index] = ['product' => $line['product'], 'list' => $parts['list'], 'promo' => $parts['promo'] + $parts['first_promo']];
            }
        }

        // Bundle: replaces the promotions of the items it covers when it saves more.
        $bundle = count($candidates) >= 1 ? Bundles\BundleService::best($this->bundles->running(), $candidates, $currency) : null;
        if ($bundle !== null) {
            foreach ($bundle['shares'] as $index => $share) {
                $list = $rows[$index]['parts']['list'];
                $permanent = (bool) $bundle['bundle']['permanent'];
                $rows[$index]['permanent'] = $permanent ? $share : 0;
                $rows[$index]['first'] = $permanent ? 0 : $share;
                $rows[$index]['group'] = Money::discount($list - ($permanent ? $share : 0), $groupBp);
                $rows[$index]['promo_bp'] = 0;
                $rows[$index]['first_bp'] = 0;
                $rows[$index]['sale'] = null;
                $rows[$index]['bundle'] = true;
            }
        }

        // Coupon: on the price after promotion/bundle and group discount (plus the setup fee).
        $couponLines = [];
        foreach ($rows as $index => $row) {
            $couponLines[$index] = ['product' => $lines[$index]['product'], 'base' => $row['parts']['list'] - $row['permanent'] - $row['group'], 'setup' => $row['parts']['setup']];
        }
        $couponResult = $coupon !== null ? $coupon($couponLines) : null;

        $result = [];
        $bundleDiscount = 0;
        foreach ($rows as $index => $row) {
            $parts = $row['parts'];
            $price = $parts['list'];
            $couponFirst = (int) ($couponResult['lines'][$index]['first'] ?? 0);
            $couponRecurring = (int) ($couponResult['lines'][$index]['recurring'] ?? 0);
            $base = $row['permanent'] + $row['group'];
            // A domain's first year above the renewal price is charged once (like a setup fee).
            $setupFee = $parts['setup'] + $parts['first_extra'];
            // A first-period discount never makes the first payment negative (e.g. together with a coupon).
            $firstPromo = max(0, min($row['first'], $price + $setupFee - $base - $parts['first_discount'] - $couponFirst));
            $discount = $base + $firstPromo + $parts['first_discount'] + $couponFirst;
            $net = $price + $setupFee - $discount;
            $taxRate = $decision->rate((int) $lines[$index]['product']['tax_rate_bp']);
            $itemBundle = $row['bundle'] ? ($bundle !== null && $bundle['bundle']['permanent'] ? $row['permanent'] : $firstPromo) : 0;
            $bundleDiscount += $itemBundle;

            $addonRows = [];
            foreach ($lines[$index]['addons'] as $booked) {
                $addonRows[] = $this->addonPrice($booked['addon'], $booked['quantity'], $lines[$index]['cycle'], $groupBp, $decision->rate((int) $booked['addon']['tax_rate_bp']));
            }

            $result[] = [
                'index' => $index,
                'product' => $lines[$index]['product'],
                'cycle' => $lines[$index]['cycle'],
                'price' => $price,
                'setup_fee' => $setupFee,
                'discount' => $discount,
                'recurring_discount' => $base + $couponRecurring,
                'net' => $net,
                'tax_rate_bp' => $taxRate,
                'tax' => Money::tax($net, $taxRate),
                'recurring' => $price - $base - $couponRecurring,
                'promo_bp' => $row['promo_bp'],
                'promo' => $row['bundle'] ? 0 : $row['permanent'],
                'first_promo' => $row['bundle'] ? 0 : $firstPromo,
                'first_bp' => $row['first_bp'],
                'sale' => $row['sale'],
                'group' => $row['group'],
                'first_extra' => $parts['first_extra'],
                'first_discount' => $parts['first_discount'],
                'coupon' => $couponFirst,
                'coupon_recurring' => $couponRecurring,
                'bundle_discount' => $itemBundle,
                'addons' => $addonRows,
            ];
        }

        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        foreach ($result as $line) {
            $subtotal += $line['price'] + $line['setup_fee'];
            $discount += $line['discount'];
            $tax += $line['tax'];
            foreach ($line['addons'] as $addon) {
                $subtotal += $addon['price'] + $addon['setup_fee'];
                $discount += $addon['discount'];
                $tax += $addon['tax'];
            }
        }

        return [
            'lines' => $result,
            'bundle' => $bundle !== null ? $bundle['bundle'] : null,
            'bundle_discount' => $bundleDiscount,
            'coupon' => $couponResult !== null ? $couponResult['coupon'] : null,
            'coupon_discount' => (int) ($couponResult['first'] ?? 0),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'net' => $subtotal - $discount,
            'tax' => $tax,
            'total' => $subtotal - $discount + $tax,
            'currency' => $currency,
        ];
    }

    /**
     * Price of an add-on for one billing period of its service.
     *
     * @param array<string, mixed> $addon
     * @return array{addon: array<string, mixed>, quantity: int, price: int, setup_fee: int, discount: int, net: int, tax_rate_bp: int, tax: int, recurring: int}
     */
    public function addonPrice(array $addon, int $quantity, string $cycle, int $groupBp, int $taxRate): array
    {
        $price = (int) $addon['price'] * $quantity * max(1, BillingCycle::months($cycle));
        $setup = (int) $addon['setup_fee'] * $quantity;
        $group = Money::discount($price, $groupBp);
        $net = $price + $setup - $group;

        return ['addon' => $addon, 'quantity' => $quantity, 'price' => $price, 'setup_fee' => $setup, 'discount' => $group, 'net' => $net, 'tax_rate_bp' => $taxRate, 'tax' => Money::tax($net, $taxRate), 'recurring' => $price - $group];
    }

    /**
     * Creates the order of prepared lines in one transaction.
     *
     * @param list<array{product: array<string, mixed>, options: array<string, mixed>, configuration: array<string, int>|null, cycle: string, transfer: bool, addons: list<array{addon: array<string, mixed>, quantity: int}>}> $lines
     * @param callable(): void|null $inTransaction
     * @return array{order_id: int, invoice_id: int, paid: bool}
     */
    private function submit(User $user, array $lines, string $ip, ?string $couponCode, ?callable $inTransaction = null, ?int $expectedTotal = null): array
    {
        $couponCode = $couponCode !== null && trim($couponCode) !== '' ? $couponCode : null;

        $result = $this->db->transaction(function () use ($user, $lines, $ip, $couponCode, $inTransaction, $expectedTotal): array {
            // One order per customer at a time: the coupon checks below (uses per customer, new
            // customers only) then see every order of this customer that committed before.
            // Must be the first statement of the transaction (before any consistent read).
            $this->db->fetchValue('SELECT `id` FROM `nx_users` WHERE `id` = ? FOR UPDATE', [$user->id]);

            // Products locked: availability, stock and prices are those of this moment.
            $locked = $this->products->lockMany(array_map(static fn (array $line): int => (int) $line['product']['id'], $lines));
            $units = [];
            foreach ($lines as $index => $line) {
                $product = $locked[(int) $line['product']['id']] ?? null;
                if ($product === null || !self::available($product, $user)) {
                    throw new OrderException('This product is not available.');
                }
                if (!ProductPricing::offersCycle($product, $line['cycle'])) {
                    throw new OrderException('This billing cycle is not available for this product.');
                }
                if ($line['configuration'] !== null) {
                    // The configurator may have changed since the line was checked.
                    if (!ProductConfigurator::enabled($product)) {
                        throw new OrderException('The configuration of this product has changed. Please check your order.');
                    }
                    $lines[$index]['configuration'] = ProductConfigurator::resolve($product, $line['configuration']);
                }
                $lines[$index]['product'] = $product;
                $units[(int) $product['id']] = ($units[(int) $product['id']] ?? 0) + 1;
            }
            foreach ($units as $id => $count) {
                if ($locked[$id]['stock'] !== null && (int) $locked[$id]['stock'] < $count) {
                    throw new OrderException('This product is sold out.');
                }
            }

            // Coupon: checked again with the coupon row locked (limits hold under concurrency).
            $prices = $this->calculate($user, $lines, $couponCode !== null
                ? fn (array $couponLines): array => $this->coupons->lockAndEvaluateLines($couponCode, $couponLines, $user)
                : null);
            if ($expectedTotal !== null && $prices['total'] !== $expectedTotal) {
                throw new OrderException('The prices in your cart have changed. Please check the new total.');
            }
            foreach ($units as $id => $count) {
                if (!$this->products->takeStock($id, $count)) {
                    throw new OrderException('This product is sold out.');
                }
            }

            $language = (string) ($user->attribute('language') ?? 'en');
            $coupon = $prices['coupon'];
            $couponNote = $coupon !== null ? ' · ' . $this->translator->translateTo($language, 'Coupon :code', ['code' => (string) $coupon['code']]) : '';
            $items = [];
            foreach ($prices['lines'] as $priced) {
                $line = $lines[$priced['index']];
                $product = $line['product'];
                $options = $line['options'];
                // Stored on the order and the invoice: written in the customer's language.
                $cycleLabel = $this->translator->translateTo($language, BillingCycle::labels()[$line['cycle']] ?? $line['cycle']);
                $label = (string) ($options['hostname'] ?? $options['server_name'] ?? $options['domain'] ?? '');
                $resources = '';
                if ($line['configuration'] !== null) {
                    $labels = [];
                    foreach (ProductConfigurator::summary((string) $product['category'], $options) as $part) {
                        $labels[] = $this->translator->translateTo($language, $part['text'], $part['params']);
                    }
                    $resources = ' · ' . implode(', ', $labels);
                }
                $promoBp = max($priced['promo_bp'], $priced['first_promo'] > 0 ? $priced['first_bp'] : 0);
                $promoNote = $promoBp > 0 ? ' · ' . ($priced['sale'] !== null ? (string) $priced['sale']['name'] . ' ' : '') . $this->translator->translateTo($language, 'Promotion −:percent %', ['percent' => rtrim(rtrim(number_format($promoBp / 100, 2, '.', ''), '0'), '.')]) : '';
                $bundleNote = $priced['bundle_discount'] > 0 && $prices['bundle'] !== null ? ' · ' . $this->translator->translateTo($language, 'Bundle :name', ['name' => (string) $prices['bundle']['name']]) : '';
                $domainNote = $product['category'] === ProductCategory::DOMAIN ? ' · ' . $this->translator->translateTo($language, $line['transfer'] ? 'Transfer' : 'Registration') : '';
                $parent = count($items);
                $items[] = [
                    'product_id' => (int) $product['id'],
                    'description' => mb_substr(trim(sprintf('%s (%s)%s', $product['name'], $cycleLabel, $label !== '' ? ' – ' . $label : '')) . $domainNote . $resources . $promoNote . $bundleNote . ($priced['coupon'] > 0 || $priced['coupon_recurring'] > 0 || count($prices['lines']) === 1 ? $couponNote : ''), 0, 255),
                    'quantity' => 1,
                    'unit_price' => $priced['price'],
                    'setup_fee' => $priced['setup_fee'],
                    'discount' => $priced['discount'],
                    'recurring_discount' => $priced['recurring_discount'],
                    'bundle_discount' => $priced['bundle_discount'],
                    'promo_bp' => $priced['promo_bp'] > 0 ? $priced['promo_bp'] : null,
                    'sale_id' => $priced['sale'] !== null ? (int) $priced['sale']['id'] : null,
                    'tax_rate_bp' => $priced['tax_rate_bp'],
                    'billing_cycle' => $line['cycle'],
                    'options' => $options,
                ];
                foreach ($priced['addons'] as $addon) {
                    $items[] = [
                        'product_id' => null,
                        'addon_id' => (int) $addon['addon']['id'],
                        '_parent' => $parent,
                        'description' => mb_substr($this->translator->translateTo($language, 'Add-on :name × :quantity (:cycle) for :service', [
                            'name' => (string) $addon['addon']['name'],
                            'quantity' => (string) $addon['quantity'],
                            'cycle' => $cycleLabel,
                            'service' => $label !== '' ? $label : (string) $product['name'],
                        ]), 0, 255),
                        'quantity' => $addon['quantity'],
                        'unit_price' => $addon['price'],
                        'setup_fee' => $addon['setup_fee'],
                        'discount' => $addon['discount'],
                        'recurring_discount' => $addon['discount'],
                        'tax_rate_bp' => $addon['tax_rate_bp'],
                        'billing_cycle' => $line['cycle'],
                        'options' => null,
                    ];
                }
            }

            [$orderId] = $this->orders->create([
                'number' => $this->invoices->nextNumber('order'),
                'user_id' => $user->id,
                'status' => OrderRepository::STATUS_PENDING,
                'currency' => $prices['currency'],
                'subtotal' => $prices['subtotal'],
                'discount' => $prices['discount'],
                'tax' => $prices['tax'],
                'total' => $prices['total'],
                'ip' => $ip,
                'coupon_id' => $coupon !== null ? (int) $coupon['id'] : null,
                'coupon_code' => $coupon !== null ? (string) $coupon['code'] : null,
                'coupon_discount' => $prices['coupon_discount'],
                'bundle_id' => $prices['bundle'] !== null && $prices['bundle_discount'] > 0 ? (int) $prices['bundle']['id'] : null,
                'bundle_name' => $prices['bundle'] !== null && $prices['bundle_discount'] > 0 ? (string) $prices['bundle']['name'] : null,
                'bundle_discount' => $prices['bundle_discount'],
            ], $items);

            if ($coupon !== null) {
                $this->coupons->redeem((int) $coupon['id'], $user->id, $orderId, $prices['coupon_discount']);
            }

            $invoiceId = $this->invoices->createForOrder($orderId, $user);
            $paid = false;
            if ($prices['total'] === 0) {
                // Free products are settled immediately through a zero-amount transaction.
                $paid = $this->payments->recordPayment('free', 'free-invoice-' . $invoiceId, $invoiceId, 0, $prices['currency'])->isSuccessful();
            }
            if ($inTransaction !== null) {
                $inTransaction();
            }

            return ['order_id' => $orderId, 'invoice_id' => $invoiceId, 'paid' => $paid];
        });

        $order = $this->orders->find($result['order_id']);
        if ($order !== null) {
            $this->mailer->queue($user->email, $user->displayName(), 'order_confirmation', [
                'name' => $user->displayName(),
                'order_number' => (string) $order['number'],
                'order_total' => Money::format((int) $order['total'], (string) $order['currency']),
                'invoice_url' => rtrim($this->config->string('app.url'), '/') . '/invoices/' . $result['invoice_id'],
            ], null, [], $result['invoice_id']);
        }

        return $result;
    }

    /**
     * Price preview for the order page, optionally with a coupon code.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $configuration configurator choice (e.g. cores, memory_gb, disk_gb or slots)
     * @param bool $transfer domains: price of a transfer instead of a registration
     * @param string|null $cycle billing cycle (null = the product's own)
     * @return array{price: int, setup_fee: int, discount: int, net: int, tax: int, total: int, recurring: int, tax_rate_bp: int, coupon_code: string|null, coupon_discount: int, coupon_recurring: int, coupon_duration: string|null, coupon_error: string|null, configuration: array<string, int>|null, promo_bp: int, promo_discount: int, first_promo: int, first_bp: int, sale_name: string|null, group_discount: int, first_extra: int, first_discount: int, cycle: string}
     */
    public function quote(array $product, ?int $customerGroupId, ?User $user = null, ?string $couponCode = null, array $configuration = [], bool $transfer = false, ?string $cycle = null): array
    {
        $cycle = $cycle !== null && ProductPricing::offersCycle($product, $cycle) ? $cycle : (string) $product['billing_cycle'];
        $group = $customerGroupId !== null ? $this->groups->find($customerGroupId) : null;
        // Previews clamp the choice into the offered range; orders validate it strictly.
        $choice = ProductConfigurator::enabled($product) ? ProductConfigurator::resolve($product, $configuration, false) : null;
        $parts = $this->priceParts($product, $choice, $group !== null ? (int) $group['discount_bp'] : 0, $transfer, $cycle);
        $price = $parts['list'];
        $setupFee = $parts['setup'];
        $base = $parts['promo'] + $parts['group'];

        $coupon = null;
        $couponError = null;
        if ($user !== null && $couponCode !== null && trim($couponCode) !== '') {
            try {
                $coupon = $this->coupons->evaluate($couponCode, $product, $user, $price - $base, $setupFee);
            } catch (Coupons\CouponException $exception) {
                $couponError = $exception->getMessage();
            }
        }

        $firstPromo = max(0, min($parts['first_promo'], $price + $setupFee + $parts['first_extra'] - $base - $parts['first_discount'] - ($coupon['first'] ?? 0)));
        $discount = $base + $firstPromo + $parts['first_discount'] + ($coupon['first'] ?? 0);
        $net = $price + $setupFee + $parts['first_extra'] - $discount;
        $rate = $user !== null ? $this->taxes->decide($user)->rate((int) $product['tax_rate_bp']) : (int) $product['tax_rate_bp'];
        $tax = Money::tax($net, $rate);
        $recurringNet = $price - $base - ($coupon['recurring'] ?? 0);

        return [
            'price' => $price,
            'setup_fee' => $setupFee,
            'discount' => $discount,
            'net' => $net,
            'tax' => $tax,
            'total' => $net + $tax,
            'recurring' => $recurringNet + Money::tax($recurringNet, $rate),
            'tax_rate_bp' => $rate,
            'coupon_code' => $coupon !== null ? (string) $coupon['coupon']['code'] : null,
            'coupon_discount' => (int) ($coupon['first'] ?? 0),
            'coupon_recurring' => (int) ($coupon['recurring'] ?? 0),
            'coupon_duration' => $coupon !== null ? (string) $coupon['coupon']['duration'] : null,
            'coupon_error' => $couponError,
            'configuration' => $choice,
            'promo_bp' => $parts['promo_bp'],
            'promo_discount' => $parts['promo'],
            'first_promo' => $firstPromo,
            'first_bp' => $parts['first_bp'],
            'sale_name' => $parts['sale'] !== null ? (string) $parts['sale']['name'] : null,
            'group_discount' => $parts['group'],
            'first_extra' => $parts['first_extra'],
            'first_discount' => $parts['first_discount'],
            'cycle' => $cycle,
        ];
    }

    public function cancel(int $orderId): void
    {
        $invoice = $this->db->fetchOne("SELECT `id` FROM `nx_invoices` WHERE `order_id` = ? AND `type` = 'order' AND `status` = 'unpaid'", [$orderId]);
        if ($invoice === null) {
            throw new RuntimeException('Only unpaid orders can be cancelled.');
        }
        $this->invoices->cancel((int) $invoice['id']);
    }
}
