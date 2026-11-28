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
use RuntimeException;

/**
 * Places orders. Prices, tax and discounts are always computed on the
 * server from the product and customer group – never taken from the request.
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
    ) {
    }

    /**
     * Net price parts of one order item, before coupons.
     *
     * - list: list price (configured price for the configurator)
     * - promo/promo_bp: running promotion or permanent sale (first payment and every renewal)
     * - first_promo: a sale for the first billing period only (on top of promo, up to the sale's percentage)
     * - group: customer-group discount on the promoted price
     * - first_extra/first_discount: domain first year (registration/transfer) above or below the renewal price
     *
     * @param array<string, mixed> $product
     * @param array{price: int}|null $configuration
     * @return array{list: int, promo_bp: int, promo: int, first_promo: int, first_bp: int, sale: array<string, mixed>|null, group: int, first_extra: int, first_discount: int, setup: int}
     */
    private function priceParts(array $product, ?array $configuration, int $groupDiscountBp, bool $transfer): array
    {
        $list = $configuration['price'] ?? (int) $product['price'];
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
     * @param array<string, mixed> $input customer-supplied product options
     * @return array{order_id: int, invoice_id: int, paid: bool}
     *
     * @throws ValidationException
     * @throws OrderException
     */
    public function place(User $user, int $productId, array $input, string $ip, ?string $couponCode = null): array
    {
        $product = $this->products->find($productId);
        if ($product === null || !$product['is_active'] || !$product['is_visible']
            || ($product['customer_group_id'] !== null && (int) $product['customer_group_id'] !== $user->customerGroupId)) {
            throw new OrderException('This product is not available.');
        }

        $group = $user->customerGroupId !== null ? $this->groups->find($user->customerGroupId) : null;
        if ($group !== null && !(bool) $group['can_order']) {
            throw new OrderException('Your account is not allowed to place orders. Please contact support.');
        }

        if ((int) $this->db->fetchValue('SELECT `credit_balance` FROM `nx_users` WHERE `id` = ?', [$user->id]) < 0) {
            throw new OrderException('Your account has a negative balance. Please settle it before placing new orders.');
        }

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
        $this->prechecks->check($product, $user, $options);

        $discountBp = $group !== null ? (int) $group['discount_bp'] : 0;
        $transfer = is_array($options['auth_code'] ?? null) || (is_string($options['auth_code'] ?? null) && $options['auth_code'] !== '');
        $parts = $this->priceParts($product, $configuration, $discountBp, $transfer);
        $price = $parts['list'];
        $setupFee = $parts['setup'];
        // Tax by customer: reverse charge, OSS rates, small business (TaxService).
        $taxRate = $this->taxes->decide($user)->rate((int) $product['tax_rate_bp']);
        $couponCode = $couponCode !== null && trim($couponCode) !== '' ? $couponCode : null;

        $result = $this->db->transaction(function () use ($user, $product, $options, $price, $setupFee, $parts, $taxRate, $ip, $couponCode, $transfer): array {
            // One order per customer at a time: the coupon checks below (uses per customer, new
            // customers only) then see every order of this customer that committed before.
            // Must be the first statement of the transaction (before any consistent read).
            $this->db->fetchValue('SELECT `id` FROM `nx_users` WHERE `id` = ? FOR UPDATE', [$user->id]);
            if (!$this->products->reserveStock((int) $product['id'])) {
                throw new OrderException('This product is sold out.');
            }

            // Coupon: checked again with the coupon row locked (limits hold under concurrency).
            $base = $parts['promo'] + $parts['group'];
            $coupon = $couponCode !== null ? $this->coupons->lockAndEvaluate($couponCode, $product, $user, $price - $base, $setupFee) : null;
            // A domain's first year above the renewal price is charged once (like a setup fee).
            $setupFee += $parts['first_extra'];
            // A first-period sale never makes the first payment negative (e.g. together with a coupon).
            $firstPromo = max(0, min($parts['first_promo'], $price + $setupFee - $base - $parts['first_discount'] - ($coupon['first'] ?? 0)));
            $discount = $base + $firstPromo + $parts['first_discount'] + ($coupon['first'] ?? 0);
            $recurringDiscount = $base + ($coupon['recurring'] ?? 0);
            $net = $price + $setupFee - $discount;
            $tax = Money::tax($net, $taxRate);

            // Stored on the order and the invoice: written in the customer's language.
            $cycleLabel = $this->translator->translateTo((string) ($user->attribute('language') ?? 'en'), BillingCycle::labels()[(string) $product['billing_cycle']] ?? (string) $product['billing_cycle']);
            $label = (string) ($options['hostname'] ?? $options['server_name'] ?? $options['domain'] ?? '');
            $resources = match (true) {
                isset($options['cores'], $options['memory_gb'], $options['disk_gb']) => ' · ' . $this->translator->translateTo((string) ($user->attribute('language') ?? 'en'), ':cores vCores, :memory GB RAM, :disk GB disk', ['cores' => (string) $options['cores'], 'memory' => (string) $options['memory_gb'], 'disk' => (string) $options['disk_gb']]),
                isset($options['slots']) => ' · ' . $this->translator->translateTo((string) ($user->attribute('language') ?? 'en'), ':slots slots', ['slots' => (string) $options['slots']]),
                default => '',
            };
            $couponNote = $coupon !== null ? ' · ' . $this->translator->translateTo((string) ($user->attribute('language') ?? 'en'), 'Coupon :code', ['code' => (string) $coupon['coupon']['code']]) : '';
            $promoBp = max($parts['promo_bp'], $firstPromo > 0 ? $parts['first_bp'] : 0);
            $promoNote = $promoBp > 0 ? ' · ' . ($parts['sale'] !== null ? (string) $parts['sale']['name'] . ' ' : '') . $this->translator->translateTo((string) ($user->attribute('language') ?? 'en'), 'Promotion −:percent %', ['percent' => rtrim(rtrim(number_format($promoBp / 100, 2, '.', ''), '0'), '.')]) : '';
            $domainNote = $product['category'] === ProductCategory::DOMAIN ? ' · ' . $this->translator->translateTo((string) ($user->attribute('language') ?? 'en'), $transfer ? 'Transfer' : 'Registration') : '';
            [$orderId] = $this->orders->create([
                'number' => $this->invoices->nextNumber('order'),
                'user_id' => $user->id,
                'status' => OrderRepository::STATUS_PENDING,
                'currency' => (string) $product['currency'],
                'subtotal' => $price + $setupFee,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $net + $tax,
                'ip' => $ip,
                'coupon_id' => $coupon !== null ? (int) $coupon['coupon']['id'] : null,
                'coupon_code' => $coupon !== null ? (string) $coupon['coupon']['code'] : null,
                'coupon_discount' => (int) ($coupon['first'] ?? 0),
            ], [[
                'product_id' => (int) $product['id'],
                'description' => mb_substr(trim(sprintf('%s (%s)%s', $product['name'], $cycleLabel, $label !== '' ? ' – ' . $label : '')) . $domainNote . $resources . $promoNote . $couponNote, 0, 255),
                'quantity' => 1,
                'unit_price' => $price,
                'setup_fee' => $setupFee,
                'discount' => $discount,
                'recurring_discount' => $recurringDiscount,
                'promo_bp' => $parts['promo_bp'] > 0 ? $parts['promo_bp'] : null,
                'sale_id' => $parts['sale'] !== null ? (int) $parts['sale']['id'] : null,
                'tax_rate_bp' => $taxRate,
                'billing_cycle' => (string) $product['billing_cycle'],
                'options' => $options,
            ]]);

            if ($coupon !== null) {
                $this->coupons->redeem((int) $coupon['coupon']['id'], $user->id, $orderId, (int) $coupon['first']);
            }

            $invoiceId = $this->invoices->createForOrder($orderId, $user);
            $paid = false;
            if ($net + $tax === 0) {
                // Free products are settled immediately through a zero-amount transaction.
                $paid = $this->payments->recordPayment('free', 'free-invoice-' . $invoiceId, $invoiceId, 0, (string) $product['currency'])->isSuccessful();
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
     * @return array{price: int, setup_fee: int, discount: int, net: int, tax: int, total: int, recurring: int, tax_rate_bp: int, coupon_code: string|null, coupon_discount: int, coupon_recurring: int, coupon_duration: string|null, coupon_error: string|null, configuration: array<string, int>|null, promo_bp: int, promo_discount: int, first_promo: int, first_bp: int, sale_name: string|null, group_discount: int, first_extra: int, first_discount: int}
     */
    public function quote(array $product, ?int $customerGroupId, ?User $user = null, ?string $couponCode = null, array $configuration = [], bool $transfer = false): array
    {
        $group = $customerGroupId !== null ? $this->groups->find($customerGroupId) : null;
        // Previews clamp the choice into the offered range; orders validate it strictly.
        $choice = ProductConfigurator::enabled($product) ? ProductConfigurator::resolve($product, $configuration, false) : null;
        $parts = $this->priceParts($product, $choice, $group !== null ? (int) $group['discount_bp'] : 0, $transfer);
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
