<?php

declare(strict_types=1);

use Nebrix\Controller\Account;
use Nebrix\Controller\Admin;
use Nebrix\Http\Router;

/*
 * Finance area: accounting export, bank statement import, gift cards,
 * partner program and reseller tiers.
 */
return static function (Router $r): void {
    // ----------------------------------------------------------- customers
    $r->group(['middleware' => ['auth', 'verified']], static function (Router $r): void {
        $r->get('/account/gift-cards', [Account\GiftCardController::class, 'index']);
        $r->post('/account/gift-cards/redeem', [Account\GiftCardController::class, 'redeem']);
        $r->post('/account/gift-cards/buy', [Account\GiftCardController::class, 'buy']);

    });

    // -------------------------------------------------------- administration
    $r->group(['prefix' => '/admin', 'middleware' => ['auth', 'staff']], static function (Router $r): void {
        $r->group(['middleware' => ['permission:accounting.export']], static function (Router $r): void {
            $r->get('/accounting', [Admin\AccountingController::class, 'index']);
            $r->post('/accounting/export', [Admin\AccountingController::class, 'download']);
            $r->post('/accounting/lexoffice/sync', [Admin\AccountingController::class, 'syncNow']);
        });

        $r->group(['middleware' => ['permission:bank.import']], static function (Router $r): void {
            $r->get('/bank', [Admin\BankImportController::class, 'index']);
            $r->post('/bank/import', [Admin\BankImportController::class, 'import']);
            $r->get('/bank/entries/{id:\d+}', [Admin\BankImportController::class, 'show']);
            $r->post('/bank/entries/{id:\d+}/assign', [Admin\BankImportController::class, 'assign']);
            $r->post('/bank/entries/{id:\d+}/credit', [Admin\BankImportController::class, 'credit']);
            $r->post('/bank/entries/{id:\d+}/ignore', [Admin\BankImportController::class, 'ignore']);
            $r->get('/bank/profiles', [Admin\BankImportController::class, 'profiles']);
            $r->post('/bank/profiles', [Admin\BankImportController::class, 'saveProfile']);
            $r->post('/bank/profiles/{id:\d+}/delete', [Admin\BankImportController::class, 'deleteProfile']);
        });

        $r->group(['middleware' => ['permission:gift_cards.manage']], static function (Router $r): void {
            $r->get('/gift-cards', [Admin\GiftCardController::class, 'index']);
            $r->post('/gift-cards', [Admin\GiftCardController::class, 'store']);
            $r->post('/gift-cards/batch/{batch:[a-f0-9]{16}}/csv', [Admin\GiftCardController::class, 'csv']);
            $r->post('/gift-cards/{id:\d+}/void', [Admin\GiftCardController::class, 'void']);
        });
    });
};
