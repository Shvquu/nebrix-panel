<?php

declare(strict_types=1);

use Nebrix\Controller\Account;
use Nebrix\Http\Router;

/*
 * Billing core: saved payment methods / automatic payments.
 */
return static function (Router $r): void {
    $r->group(['middleware' => ['auth', 'verified']], static function (Router $r): void {
        $r->get('/account/payment-methods', [Account\PaymentMethodController::class, 'index']);
        $r->post('/account/payment-methods/add', [Account\PaymentMethodController::class, 'add']);
        $r->get('/account/payment-methods/return', [Account\PaymentMethodController::class, 'complete']);
        $r->post('/account/payment-methods/autopay', [Account\PaymentMethodController::class, 'autopay']);
        $r->post('/account/payment-methods/{id:\d+}/default', [Account\PaymentMethodController::class, 'makeDefault']);
        $r->post('/account/payment-methods/{id:\d+}/remove', [Account\PaymentMethodController::class, 'remove']);
    });
};
