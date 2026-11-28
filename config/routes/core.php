<?php

declare(strict_types=1);

use Nebrix\Controller\Account;
use Nebrix\Http\Router;

/*
 * Billing core: saved payment methods / automatic payments, team access.
 */
return static function (Router $r): void {
    $r->group(['middleware' => ['auth', 'verified']], static function (Router $r): void {
        $r->get('/account/payment-methods', [Account\PaymentMethodController::class, 'index']);
        $r->post('/account/payment-methods/add', [Account\PaymentMethodController::class, 'add']);
        $r->get('/account/payment-methods/return', [Account\PaymentMethodController::class, 'complete']);
        $r->post('/account/payment-methods/autopay', [Account\PaymentMethodController::class, 'autopay']);
        $r->post('/account/payment-methods/{id:\d+}/default', [Account\PaymentMethodController::class, 'makeDefault']);
        $r->post('/account/payment-methods/{id:\d+}/remove', [Account\PaymentMethodController::class, 'remove']);

        // Team access
        $r->get('/account/team', [Account\TeamController::class, 'index']);
        $r->post('/account/team', [Account\TeamController::class, 'invite']);
        $r->post('/account/team/{id:\d+}/update', [Account\TeamController::class, 'update']);
        $r->post('/account/team/{id:\d+}/remove', [Account\TeamController::class, 'remove']);
        $r->post('/account/team/memberships/{id:\d+}/leave', [Account\TeamController::class, 'leave']);
        $r->get('/team/invitations/{token:[a-f0-9]{64}}', [Account\TeamController::class, 'invitation']);
        $r->post('/team/invitations/{token:[a-f0-9]{64}}/accept', [Account\TeamController::class, 'accept']);
    });
};
