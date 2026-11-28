<?php

declare(strict_types=1);

namespace Nebrix\Controller\Customer;

use Nebrix\Billing\Cart\CartService;
use Nebrix\Billing\Coupons\CouponException;
use Nebrix\Billing\Coupons\CouponService;
use Nebrix\Billing\OrderException;
use Nebrix\Billing\OrderService;
use Nebrix\Catalog\BillingCycle;
use Nebrix\Catalog\ProductConfigurator;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Repository\ProductRepository;
use Nebrix\Security\RateLimiter;
use Nebrix\Security\ValidationException;

/**
 * Cart of the signed-in customer: several products with their billing
 * cycle, options and add-ons, one coupon, one order and one invoice at
 * checkout. Prices shown here are calculated on every request; the
 * checkout calculates them again inside the order transaction and refuses
 * the order when the total differs from the one the customer confirmed.
 */
final class CartController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrderService $orders,
        private readonly ProductRepository $products,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $items = $this->carts->items($user->id);
        $cart = $this->carts->cart($user->id);
        $preview = $this->orders->preview($user, $this->checkoutItems($items), $cart['coupon_code'] ?? null);
        $entries = [];
        foreach ($items as $item) {
            $entries[] = [
                'item' => $item,
                'product' => $this->products->find($item['product_id']),
                'line' => $preview['lines'][$item['id']] ?? null,
                'problem' => $preview['problems'][$item['id']] ?? null,
            ];
        }

        return $this->render($request, 'customer/cart/index.twig', [
            'entries' => $entries,
            'prices' => $preview['prices'],
            'coupon' => $cart['coupon_code'] ?? null,
            'coupon_error' => $preview['coupon_error'],
            'can_checkout' => $items !== [] && $preview['problems'] === [] && $preview['prices'] !== null,
            'cycles' => BillingCycle::labels(),
        ]);
    }

    public function add(Request $request): Response
    {
        $user = $this->user($request);
        $data = $this->validate($request, ['product_id' => 'required|int|min:1']);
        if (!$this->limiter->attempt('cart:' . $user->id, 60, 3600)) {
            throw HttpException::tooManyRequests();
        }
        try {
            $product = $this->orders->orderable($user, (int) $data['product_id']);
        } catch (OrderException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/store');
        }

        try {
            $this->carts->add($user->id, $product, ...$this->choice($request, $user, $product));
        } catch (OrderException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/store/' . $product['slug']);
        }
        $this->flash($request, 'success', 'The product has been added to your cart.');

        return $this->redirect('/cart');
    }

    public function update(Request $request, int $id): Response
    {
        $user = $this->user($request);
        $item = $this->carts->item($user->id, $id) ?? throw HttpException::notFound();
        if (!$this->limiter->attempt('cart:' . $user->id, 60, 3600)) {
            throw HttpException::tooManyRequests();
        }
        try {
            $product = $this->orders->orderable($user, $item['product_id']);
            $this->carts->update($user->id, $id, $product, ...$this->choice($request, $user, $product));
        } catch (OrderException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/cart');
        }
        $this->flash($request, 'success', 'Your cart has been updated.');

        return $this->redirect('/cart');
    }

    public function remove(Request $request, int $id): Response
    {
        $user = $this->user($request);
        if (!$this->carts->remove($user->id, $id)) {
            throw HttpException::notFound();
        }
        $this->flash($request, 'success', 'The item has been removed from your cart.');

        return $this->redirect('/cart');
    }

    public function coupon(Request $request): Response
    {
        $user = $this->user($request);
        $code = CouponService::normalize(mb_substr($request->string('coupon'), 0, 40));
        if ($code === '') {
            $this->carts->setCoupon($user->id, null);
            $this->flash($request, 'success', 'The coupon code has been removed.');

            return $this->redirect('/cart');
        }
        // Checking codes is limited, so that codes cannot be guessed by trying.
        if (!$this->limiter->attempt('coupon-check:' . $user->id, 20, 3600)) {
            throw HttpException::tooManyRequests('Too many coupon checks. Please try again later.');
        }
        if (!CouponService::isValidCode($code)) {
            $this->flash($request, 'error', 'This coupon code is not valid.');

            return $this->redirect('/cart');
        }
        $this->carts->setCoupon($user->id, $code);

        return $this->redirect('/cart');
    }

    public function checkout(Request $request): Response
    {
        $user = $this->user($request);
        $data = $this->validate($request, ['accept_terms' => 'bool', 'total' => 'required|int|min:0']);
        if ($data['accept_terms'] !== true) {
            throw ValidationException::withMessages(['accept_terms' => 'Please accept the terms to place the order.']);
        }
        if (!$this->limiter->attempt('order:' . $user->id, 10, 3600)) {
            throw HttpException::tooManyRequests('You have placed many orders in a short time. Please try again later.');
        }
        $items = $this->carts->items($user->id);
        if ($items === []) {
            $this->flash($request, 'error', 'Your cart is empty.');

            return $this->redirect('/cart');
        }
        $cart = $this->carts->cart($user->id);
        $coupon = $cart['coupon_code'] ?? null;
        $ids = array_map(static fn (array $item): int => $item['id'], $items);

        try {
            $result = $this->orders->checkout(
                $user,
                array_values($this->checkoutItems($items)),
                $request->clientIp(),
                $coupon,
                fn () => $this->carts->clear($user->id, $ids),
                (int) $data['total'],
            );
        } catch (CouponException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/cart');
        } catch (OrderException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/cart');
        }

        $this->audit($request, 'order.placed', 'order', $result['order_id'], ['items' => count($items), 'products' => array_map(static fn (array $item): int => $item['product_id'], $items), 'coupon' => $coupon]);
        $this->container->get(\Nebrix\Marketing\ReviewInvitations::class)->consentFromForm($request, $user->id, 'checkout');
        $this->flash($request, 'success', $result['paid']
            ? 'Thank you for your order! Your service is being set up.'
            : 'Thank you for your order! Please pay the invoice to start the setup.');

        return $this->redirect('/invoices/' . $result['invoice_id']);
    }

    /**
     * The choice of an order form, checked like an order (with the
     * provisioner's precheck): input, configuration, cycle and add-ons.
     *
     * @param array<string, mixed> $product
     * @return array{0: array<string, mixed>, 1: array<string, int>, 2: string, 3: array<int, int>}
     *
     * @throws OrderException
     * @throws ValidationException
     */
    private function choice(Request $request, \Nebrix\Auth\User $user, array $product): array
    {
        if ($product['stock'] !== null && (int) $product['stock'] <= 0) {
            throw new OrderException('This product is sold out.');
        }
        $input = StoreController::optionsFrom($request, $product);
        $cycle = $request->string('cycle') !== '' ? $request->string('cycle') : null;
        $line = $this->orders->prepareLine($user, $product, $input, $cycle, StoreController::addonsFrom($request));
        $configuration = [];
        foreach (ProductConfigurator::optionNames((string) $product['category']) as $name) {
            if ($line['configuration'] !== null && isset($line['configuration'][$name])) {
                $configuration[$name] = (int) $line['configuration'][$name];
            }
        }
        $addons = [];
        foreach ($line['addons'] as $booked) {
            $addons[(int) $booked['addon']['id']] = $booked['quantity'];
        }

        return [$input, $configuration, $line['cycle'], $addons];
    }

    /**
     * @param list<array{id: int, product_id: int, billing_cycle: string, input: array<string, mixed>, configuration: array<string, int>, addons: array<int, int>}> $items
     * @return array<int, array{product_id: int, input: array<string, mixed>, cycle: string|null, addons: array<int|string, mixed>}>
     */
    private function checkoutItems(array $items): array
    {
        $list = [];
        foreach ($items as $item) {
            $list[$item['id']] = [
                'product_id' => $item['product_id'],
                'input' => $item['input'] + array_map('strval', $item['configuration']),
                'cycle' => $item['billing_cycle'],
                'addons' => $item['addons'],
            ];
        }

        return $list;
    }
}
