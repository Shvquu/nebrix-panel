<?php

declare(strict_types=1);

use Nebrix\Controller\Account\NotificationController;
use Nebrix\Controller\Admin\CannedResponseController;
use Nebrix\Controller\Admin\HelpCenterController;
use Nebrix\Controller\Admin\TicketController as AdminTicketController;
use Nebrix\Controller\Customer\TicketController;
use Nebrix\Controller\HelpController;
use Nebrix\Controller\InboundMailController;
use Nebrix\Http\Router;

/*
 * Support area: help center, ticket attachments and ratings, inbound e-mail replies, notification preferences.
 */
return static function (Router $r): void {
    // Help center (public). The fixed pages come before the category route.
    $r->get('/help', [HelpController::class, 'index']);
    $r->get('/help/search', [HelpController::class, 'search']);
    $r->get('/help/suggest', [HelpController::class, 'suggest']);
    $r->post('/help/articles/{id:\d+}/vote', [HelpController::class, 'vote']);
    $r->get('/help/{category:[a-z0-9-]{1,80}}', [HelpController::class, 'category']);
    $r->get('/help/{category:[a-z0-9-]{1,80}}/{article:[a-z0-9-]{1,120}}', [HelpController::class, 'article']);

    // Inbound e-mail replies: authenticated by the HMAC signature, therefore no CSRF token.
    $r->post('/webhooks/inbound-mail', [InboundMailController::class, 'receive'], ['csrf' => false]);

    // Customer area
    $r->group(['middleware' => ['auth', 'verified']], static function (Router $r): void {
        $r->get('/tickets/{id:\d+}/attachments/{attachment:\d+}', [TicketController::class, 'attachment']);
        $r->post('/tickets/{id:\d+}/rating', [TicketController::class, 'rate']);
        $r->get('/account/notifications', [NotificationController::class, 'show']);
        $r->post('/account/notifications', [NotificationController::class, 'update']);
    });

    // Administration
    $r->group(['prefix' => '/admin', 'middleware' => ['auth', 'staff']], static function (Router $r): void {
        $r->get('/tickets/{id:\d+}/attachments/{attachment:\d+}', [AdminTicketController::class, 'attachment'], ['middleware' => ['permission:tickets.view']]);

        $r->group(['middleware' => ['permission:help_center.manage']], static function (Router $r): void {
            $r->get('/help', [HelpCenterController::class, 'index']);
            $r->get('/help/categories/create', [HelpCenterController::class, 'createCategory']);
            $r->post('/help/categories', [HelpCenterController::class, 'storeCategory']);
            $r->get('/help/categories/{id:\d+}', [HelpCenterController::class, 'editCategory']);
            $r->post('/help/categories/{id:\d+}', [HelpCenterController::class, 'updateCategory']);
            $r->post('/help/categories/{id:\d+}/delete', [HelpCenterController::class, 'deleteCategory']);
            $r->get('/help/articles/create', [HelpCenterController::class, 'createArticle']);
            $r->post('/help/articles', [HelpCenterController::class, 'storeArticle']);
            $r->get('/help/articles/{id:\d+}', [HelpCenterController::class, 'editArticle']);
            $r->post('/help/articles/{id:\d+}', [HelpCenterController::class, 'updateArticle']);
            $r->post('/help/articles/{id:\d+}/delete', [HelpCenterController::class, 'deleteArticle']);
        });

        $r->group(['middleware' => ['permission:canned_responses.manage']], static function (Router $r): void {
            $r->get('/canned-responses', [CannedResponseController::class, 'index']);
            $r->get('/canned-responses/create', [CannedResponseController::class, 'create']);
            $r->post('/canned-responses', [CannedResponseController::class, 'store']);
            $r->get('/canned-responses/{id:\d+}', [CannedResponseController::class, 'edit']);
            $r->post('/canned-responses/{id:\d+}', [CannedResponseController::class, 'update']);
            $r->post('/canned-responses/{id:\d+}/delete', [CannedResponseController::class, 'delete']);
        });
    });
};
