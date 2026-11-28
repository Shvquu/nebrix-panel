<?php

declare(strict_types=1);

use Nebrix\Controller\Account\SshKeyController;
use Nebrix\Controller\Admin\IpPoolController;
use Nebrix\Controller\Admin\ServerController as AdminServerController;
use Nebrix\Controller\Admin\ServerTemplateController;
use Nebrix\Controller\Customer\ServerController;
use Nebrix\Http\Router;

/*
 * Server self-service: SSH keys, snapshots, resource graphs, reverse DNS
 * (customers) and server templates, graphs, additional IPv4 addresses and
 * the reverse DNS setting of IP pools (staff).
 */
return static function (Router $r): void {
    $r->group(['middleware' => ['auth', 'verified']], static function (Router $r): void {
        $r->get('/account/ssh-keys', [SshKeyController::class, 'index']);
        $r->post('/account/ssh-keys', [SshKeyController::class, 'store']);
        $r->post('/account/ssh-keys/{id:\d+}/delete', [SshKeyController::class, 'delete']);

        $r->get('/services/{id:\d+}/snapshots', [ServerController::class, 'snapshots']);
        $r->post('/services/{id:\d+}/snapshots', [ServerController::class, 'createSnapshot']);
        $r->post('/services/{id:\d+}/snapshots/rollback', [ServerController::class, 'rollbackSnapshot']);
        $r->post('/services/{id:\d+}/snapshots/delete', [ServerController::class, 'deleteSnapshot']);

        $r->get('/services/{id:\d+}/graphs', [ServerController::class, 'graphs']);
        $r->get('/services/{id:\d+}/graphs.json', [ServerController::class, 'graphData']);

        $r->get('/services/{id:\d+}/rdns', [ServerController::class, 'reverseDns']);
        $r->post('/services/{id:\d+}/rdns', [ServerController::class, 'setReverseDns']);
        $r->post('/services/{id:\d+}/rdns/delete', [ServerController::class, 'deleteReverseDns']);
    });

    $r->group(['prefix' => '/admin', 'middleware' => ['auth', 'staff']], static function (Router $r): void {
        // Changes additionally require products.manage (checked in the controller).
        $r->group(['middleware' => ['permission:products.view']], static function (Router $r): void {
            $r->get('/server-templates', [ServerTemplateController::class, 'index']);
            $r->get('/server-templates/create', [ServerTemplateController::class, 'create']);
            $r->post('/server-templates', [ServerTemplateController::class, 'store']);
            $r->get('/server-templates/{id:\d+}', [ServerTemplateController::class, 'edit']);
            $r->post('/server-templates/{id:\d+}', [ServerTemplateController::class, 'update']);
            $r->post('/server-templates/{id:\d+}/delete', [ServerTemplateController::class, 'delete']);
        });

        // Changes additionally require services.manage (checked in the controller).
        $r->group(['middleware' => ['permission:services.view']], static function (Router $r): void {
            $r->get('/services/{id:\d+}/graphs', [AdminServerController::class, 'graphs']);
            $r->get('/services/{id:\d+}/graphs.json', [AdminServerController::class, 'graphData']);
            $r->post('/services/{id:\d+}/ipv4', [AdminServerController::class, 'addIpv4']);
            $r->post('/services/{id:\d+}/ipv4/{address:\d+}/remove', [AdminServerController::class, 'removeIpv4']);
        });

        $r->post('/ip-pools/{id:\d+}/rdns', [IpPoolController::class, 'updateRdns'], ['middleware' => ['permission:integrations.view']]);
    });
};
