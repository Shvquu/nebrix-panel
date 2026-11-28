<?php

declare(strict_types=1);

use Nebrix\Controller\Customer;
use Nebrix\Http\Router;

/*
 * Orders area: cart and checkout, add-ons of services, billing cycle of a
 * service.
 */
return static function (Router $r): void {
    $r->group(['middleware' => ['auth', 'verified']], static function (Router $r): void {
        $r->get('/cart', [Customer\CartController::class, 'index']);
        $r->post('/cart/items', [Customer\CartController::class, 'add']);
        $r->post('/cart/items/{id:\d+}', [Customer\CartController::class, 'update']);
        $r->post('/cart/items/{id:\d+}/remove', [Customer\CartController::class, 'remove']);
        $r->post('/cart/coupon', [Customer\CartController::class, 'coupon']);
        $r->post('/cart/checkout', [Customer\CartController::class, 'checkout']);

        $r->post('/services/{id:\d+}/addons', [Customer\AddonController::class, 'book']);
        $r->get('/services/{id:\d+}/addons/{addon:\d+}/cancel', [Customer\AddonController::class, 'confirmCancel']);
        $r->post('/services/{id:\d+}/addons/{addon:\d+}/cancel', [Customer\AddonController::class, 'cancel']);
        $r->post('/services/{id:\d+}/addons/{addon:\d+}/cancel/withdraw', [Customer\AddonController::class, 'withdrawCancellation']);
        $r->post('/services/{id:\d+}/cycle', [Customer\AddonController::class, 'changeCycle']);
        $r->post('/services/{id:\d+}/cycle/withdraw', [Customer\AddonController::class, 'withdrawCycle']);
    });
};
