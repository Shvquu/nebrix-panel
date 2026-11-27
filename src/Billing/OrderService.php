<?php

declare(strict_types=1);

namespace Nebrix\Billing;

use Nebrix\Auth\User;
use Nebrix\Catalog\BillingCycle;
use Nebrix\Catalog\ProductCategory;
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
    ) {
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
        $this->prechecks->check($product, $user, $options);

        $discountBp = $group !== null ? (int) $group['discount_bp'] : 0;
        $price = (int) $product['price'];
        $setupFee = (int) $product['setup_fee'];
        $groupDiscount = Money::discount($price, $discountBp);
        // Tax by customer: reverse charge, OSS rates, small business (TaxService).
        $taxRate = $this->taxes->decide($user)->rate((int) $product['tax_rate_bp']);
        $couponCode = $couponCode !== null && trim($couponCode) !== '' ? $couponCode : null;

        $result = $this->db->transaction(function () use ($user, $product, $options, $price, $setupFee, $groupDiscount, $taxRate, $ip, $couponCode): array {
            // One order per customer at a time: the coupon checks below (uses per customer, new
            // customers only) then see every order of this customer that committed before.
            // Must be the first statement of the transaction (before any consistent read).
            $this->db->fetchValue('SELECT `id` FROM `nx_users` WHERE `id` = ? FOR UPDATE', [$user->id]);
            if (!$this->products->reserveStock((int) $product['id'])) {
                throw new OrderException('This product is sold out.');
            }

            // Coupon: checked again with the coupon row locked (limits hold under concurrency).
            $coupon = $couponCode !== null ? $this->coupons->lockAndEvaluate($couponCode, $product, $user, $price - $groupDiscount, $setupFee) : null;
            $discount = $groupDiscount + ($coupon['first'] ?? 0);
            $recurringDiscount = $groupDiscount + ($coupon['recurring'] ?? 0);
            $net = $price + $setupFee - $discount;
            $tax = Money::tax($net, $taxRate);

            // Stored on the order and the invoice: written in the customer's language.
            $cycleLabel = $this->translator->translateTo((string) ($user->attribute('language') ?? 'en'), BillingCycle::labels()[(string) $product['billing_cycle']] ?? (string) $product['billing_cycle']);
            $label = (string) ($options['hostname'] ?? $options['server_name'] ?? $options['domain'] ?? '');
            $couponNote = $coupon !== null ? ' · ' . $this->translator->translateTo((string) ($user->attribute('language') ?? 'en'), 'Coupon :code', ['code' => (string) $coupon['coupon']['code']]) : '';
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
                'description' => mb_substr(trim(sprintf('%s (%s)%s', $product['name'], $cycleLabel, $label !== '' ? ' – ' . $label : '')) . $couponNote, 0, 255),
                'quantity' => 1,
                'unit_price' => $price,
                'setup_fee' => $setupFee,
                'discount' => $discount,
                'recurring_discount' => $recurringDiscount,
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
     * @return array{price: int, setup_fee: int, discount: int, net: int, tax: int, total: int, recurring: int, tax_rate_bp: int, coupon_code: string|null, coupon_discount: int, coupon_recurring: int, coupon_duration: string|null, coupon_error: string|null}
     */
    public function quote(array $product, ?int $customerGroupId, ?User $user = null, ?string $couponCode = null): array
    {
        $group = $customerGroupId !== null ? $this->groups->find($customerGroupId) : null;
        $price = (int) $product['price'];
        $setupFee = (int) $product['setup_fee'];
        $groupDiscount = Money::discount($price, $group !== null ? (int) $group['discount_bp'] : 0);

        $coupon = null;
        $couponError = null;
        if ($user !== null && $couponCode !== null && trim($couponCode) !== '') {
            try {
                $coupon = $this->coupons->evaluate($couponCode, $product, $user, $price - $groupDiscount, $setupFee);
            } catch (Coupons\CouponException $exception) {
                $couponError = $exception->getMessage();
            }
        }

        $discount = $groupDiscount + ($coupon['first'] ?? 0);
        $net = $price + $setupFee - $discount;
        $rate = $user !== null ? $this->taxes->decide($user)->rate((int) $product['tax_rate_bp']) : (int) $product['tax_rate_bp'];
        $tax = Money::tax($net, $rate);
        $recurringNet = $price - $groupDiscount - ($coupon['recurring'] ?? 0);

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
