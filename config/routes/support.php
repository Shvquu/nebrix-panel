<?php

declare(strict_types=1);

use Nebrix\Controller\Account\NotificationController;
use Nebrix\Http\Router;

/*
 * Support area: notification preferences.
 */
return static function (Router $r): void {
    // Customer area
    $r->group(['middleware' => ['auth', 'verified']], static function (Router $r): void {
        $r->get('/account/notifications', [NotificationController::class, 'show']);
        $r->post('/account/notifications', [NotificationController::class, 'update']);
    });
};
