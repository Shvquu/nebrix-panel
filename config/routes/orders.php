<?php

declare(strict_types=1);

use Nebrix\Controller\Admin;
use Nebrix\Controller\Customer;
use Nebrix\Http\Router;

/*
 * Orders area: cart and checkout, add-ons of services, billing cycle of a
 * service; admin: bundles, add-on catalog and the add-on set-up queue.
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

    $r->group(['prefix' => '/admin', 'middleware' => ['auth', 'staff']], static function (Router $r): void {
        $r->group(['middleware' => ['permission:products.view']], static function (Router $r): void {
            $r->get('/bundles', [Admin\BundleController::class, 'index']);
            $r->get('/bundles/create', [Admin\BundleController::class, 'create']);
            $r->post('/bundles', [Admin\BundleController::class, 'store']);
            $r->get('/bundles/{id:\d+}', [Admin\BundleController::class, 'edit']);
            $r->post('/bundles/{id:\d+}', [Admin\BundleController::class, 'update']);
            $r->get('/addons', [Admin\AddonController::class, 'index']);
            $r->get('/addons/create', [Admin\AddonController::class, 'create']);
            $r->post('/addons', [Admin\AddonController::class, 'store']);
            $r->get('/addons/{id:\d+}', [Admin\AddonController::class, 'edit']);
            $r->post('/addons/{id:\d+}', [Admin\AddonController::class, 'update']);
        });
        $r->group(['middleware' => ['permission:services.view']], static function (Router $r): void {
            $r->get('/addon-queue', [Admin\AddonController::class, 'queue']);
            $r->post('/addon-queue/{id:\d+}/done', [Admin\AddonController::class, 'markDone']);
            $r->post('/addon-queue/{id:\d+}/removed', [Admin\AddonController::class, 'markRemoved']);
            $r->post('/addon-queue/{id:\d+}/retry', [Admin\AddonController::class, 'retry']);
        });
    });
};
