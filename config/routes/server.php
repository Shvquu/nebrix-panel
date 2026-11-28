<?php

declare(strict_types=1);

use Nebrix\Controller\Account\SshKeyController;
use Nebrix\Http\Router;

/*
 * Server self-service: SSH keys of customers.
 */
return static function (Router $r): void {
    $r->group(['middleware' => ['auth', 'verified']], static function (Router $r): void {
        $r->get('/account/ssh-keys', [SshKeyController::class, 'index']);
        $r->post('/account/ssh-keys', [SshKeyController::class, 'store']);
        $r->post('/account/ssh-keys/{id:\d+}/delete', [SshKeyController::class, 'delete']);
    });
};
