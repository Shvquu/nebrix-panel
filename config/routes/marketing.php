<?php

declare(strict_types=1);

use Nebrix\Controller\Admin;
use Nebrix\Controller\Marketing;
use Nebrix\Http\Router;

/*
 * Marketing: SEO (sitemap, robots.txt, images), landing pages and blog.
 */
return static function (Router $r): void {
    $r->get('/sitemap.xml', [Marketing\SeoController::class, 'sitemap']);
    $r->get('/robots.txt', [Marketing\SeoController::class, 'robots']);
    $r->get('/media/{name:[a-f0-9]{32}\.(?:png|jpg)}', [Marketing\SeoController::class, 'media']);

    $r->get('/blog', [Marketing\BlogController::class, 'index']);
    $r->get('/blog/feed.xml', [Marketing\BlogController::class, 'feed']);
    $r->get('/blog/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', [Marketing\BlogController::class, 'show']);

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
    });

    // Landing pages: matched only when no other route matches the address
    // ("api" stays the JSON 404 of the REST API).
    $r->get('/{slug:(?!api$)[a-z0-9]+(?:-[a-z0-9]+)*}', [Marketing\LandingPageController::class, 'show'], ['fallback' => true]);
};
