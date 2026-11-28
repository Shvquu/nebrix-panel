<?php

declare(strict_types=1);

use Nebrix\Controller\Account\CommunicationController;
use Nebrix\Controller\Admin;
use Nebrix\Controller\Marketing;
use Nebrix\Http\Router;

/*
 * Marketing: SEO (sitemap, robots.txt, images), landing pages, blog,
 * newsletter with double opt-in and review invitations.
 */
return static function (Router $r): void {
    $r->get('/sitemap.xml', [Marketing\SeoController::class, 'sitemap']);
    $r->get('/robots.txt', [Marketing\SeoController::class, 'robots']);
    $r->get('/media/{name:[a-f0-9]{32}\.(?:png|jpg)}', [Marketing\SeoController::class, 'media']);

    $r->get('/blog', [Marketing\BlogController::class, 'index']);
    $r->get('/blog/feed.xml', [Marketing\BlogController::class, 'feed']);
    $r->get('/blog/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', [Marketing\BlogController::class, 'show']);

    // Newsletter (double opt-in). Unsubscribing must always work: the links
    // carry a 256-bit token; the POST also accepts RFC 8058 one-click
    // requests of mail clients, which cannot send a CSRF token.
    $r->get('/newsletter', [Marketing\NewsletterController::class, 'show']);
    $r->post('/newsletter', [Marketing\NewsletterController::class, 'subscribe']);
    $r->get('/newsletter/confirm/{token:[a-f0-9]{64}}', [Marketing\NewsletterController::class, 'confirmForm']);
    $r->post('/newsletter/confirm', [Marketing\NewsletterController::class, 'confirm']);
    $r->get('/newsletter/unsubscribe/{token:[a-f0-9]{64}}', [Marketing\NewsletterController::class, 'unsubscribeForm']);
    $r->post('/newsletter/unsubscribe/{token:[a-f0-9]{64}}', [Marketing\NewsletterController::class, 'unsubscribe'], ['csrf' => false]);

    // Opt-out link of the review invitation (also RFC 8058 one-click).
    $r->get('/reviews/opt-out/{token:[a-f0-9]{64}}', [Marketing\ReviewOptOutController::class, 'show']);
    $r->post('/reviews/opt-out/{token:[a-f0-9]{64}}', [Marketing\ReviewOptOutController::class, 'optOut'], ['csrf' => false]);

    $r->group(['middleware' => ['auth', 'verified']], static function (Router $r): void {
        $r->get('/account/communication', [CommunicationController::class, 'show']);
        $r->post('/account/communication', [CommunicationController::class, 'update']);
    });

    $r->group(['prefix' => '/admin', 'middleware' => ['auth', 'staff']], static function (Router $r): void {
        $r->post('/seo/og-image', [Admin\SeoImageController::class, 'upload'], ['middleware' => ['permission:settings.branding']]);
        $r->post('/seo/og-image/remove', [Admin\SeoImageController::class, 'remove'], ['middleware' => ['permission:settings.branding']]);

        // Viewing needs content.view; the controllers re-check content.manage for changes.
        $r->group(['middleware' => ['permission:content.view']], static function (Router $r): void {
            $r->get('/landing-pages', [Admin\LandingPageController::class, 'index']);
            $r->get('/landing-pages/create', [Admin\LandingPageController::class, 'create']);
            $r->post('/landing-pages', [Admin\LandingPageController::class, 'store']);
            $r->get('/landing-pages/{id:\d+}', [Admin\LandingPageController::class, 'edit']);
            $r->post('/landing-pages/{id:\d+}', [Admin\LandingPageController::class, 'update']);
            $r->post('/landing-pages/{id:\d+}/delete', [Admin\LandingPageController::class, 'delete']);
            $r->get('/landing-pages/{id:\d+}/preview', [Admin\LandingPageController::class, 'preview']);

            $r->get('/blog', [Admin\BlogPostController::class, 'index']);
            $r->get('/blog/create', [Admin\BlogPostController::class, 'create']);
            $r->post('/blog', [Admin\BlogPostController::class, 'store']);
            $r->get('/blog/{id:\d+}', [Admin\BlogPostController::class, 'edit']);
            $r->post('/blog/{id:\d+}', [Admin\BlogPostController::class, 'update']);
            $r->post('/blog/{id:\d+}/delete', [Admin\BlogPostController::class, 'delete']);
            $r->get('/blog/{id:\d+}/preview', [Admin\BlogPostController::class, 'preview']);
        });

        // Viewing needs newsletter.view; changes newsletter.manage, the export newsletter.export.
        $r->group(['middleware' => ['permission:newsletter.view']], static function (Router $r): void {
            $r->get('/newsletter', [Admin\NewsletterController::class, 'index']);
            $r->get('/newsletter/subscribers', [Admin\NewsletterController::class, 'subscribers']);
            $r->get('/newsletter/subscribers/export', [Admin\NewsletterController::class, 'export'], ['middleware' => ['permission:newsletter.export']]);
            $r->post('/newsletter/subscribers/{id:\d+}/delete', [Admin\NewsletterController::class, 'deleteSubscriber']);
            $r->get('/newsletter/campaigns/create', [Admin\NewsletterController::class, 'create']);
            $r->post('/newsletter/campaigns', [Admin\NewsletterController::class, 'store']);
            $r->get('/newsletter/campaigns/{id:\d+}', [Admin\NewsletterController::class, 'edit']);
            $r->post('/newsletter/campaigns/{id:\d+}', [Admin\NewsletterController::class, 'update']);
            $r->post('/newsletter/campaigns/{id:\d+}/test', [Admin\NewsletterController::class, 'test']);
            $r->post('/newsletter/campaigns/{id:\d+}/send', [Admin\NewsletterController::class, 'send']);
            $r->post('/newsletter/campaigns/{id:\d+}/cancel', [Admin\NewsletterController::class, 'cancel']);
            $r->post('/newsletter/campaigns/{id:\d+}/delete', [Admin\NewsletterController::class, 'delete']);
        });
    });

    // Landing pages: matched only when no other route matches the address
    // ("api" stays the JSON 404 of the REST API).
    $r->get('/{slug:(?!api$)[a-z0-9]+(?:-[a-z0-9]+)*}', [Marketing\LandingPageController::class, 'show'], ['fallback' => true]);
};
