<?php

declare(strict_types=1);

use Nebrix\Controller\Admin;
use Nebrix\Http\Router;

/*
 * Finance area: accounting export, bank statement import, gift cards,
 * partner program and reseller tiers.
 */
return static function (Router $r): void {
    // -------------------------------------------------------- administration
    $r->group(['prefix' => '/admin', 'middleware' => ['auth', 'staff']], static function (Router $r): void {
        $r->group(['middleware' => ['permission:accounting.export']], static function (Router $r): void {
            $r->get('/accounting', [Admin\AccountingController::class, 'index']);
            $r->post('/accounting/export', [Admin\AccountingController::class, 'download']);
            $r->post('/accounting/lexoffice/sync', [Admin\AccountingController::class, 'syncNow']);
        });
    });
};
