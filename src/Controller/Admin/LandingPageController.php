<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Authorization\Permission;
use Nebrix\Controller\Marketing\LandingPageController as PublicLandingPages;
use Nebrix\Database\Connection;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Http\Router;
use Nebrix\Marketing\LandingPages;
use Nebrix\Security\ValidationException;
use Nebrix\Security\Validator;
use Nebrix\Website\WebsiteCatalog;

/**
 * Landing pages (Marketing → Landing pages). Viewing and the preview of
 * drafts need content.view, changes content.manage; changes are
 * audit-logged. Addresses must not collide with existing routes.
 */
final class LandingPageController extends Controller
{
    public function __construct(
        private readonly LandingPages $pages,
        private readonly Router $router,
        private readonly Connection $db,
    ) {
    }

    public function index(Request $request): Response
    {
        $pages = $this->pages->all();
        foreach ($pages as $key => $page) {
            // A route added later (e.g. by an update) takes precedence over the landing page.
            $pages[$key]['shadowed'] = $this->routeConflict((string) $page['slug']);
        }

        return $this->render($request, 'admin/marketing/landing_pages/index.twig', ['pages' => $pages, 'catalog_pages' => $this->catalogPages()]);
    }

    public function create(Request $request): Response
    {
        $this->authorize($request, Permission::CONTENT_MANAGE);

        return $this->form($request, null);
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->form($request, $this->pages->find($id) ?? throw HttpException::notFound());
    }

    public function store(Request $request): Response
    {
        $this->authorize($request, Permission::CONTENT_MANAGE);
        $data = $this->input($request, 0);
        $id = $this->pages->save(null, $data, $this->user($request)->id);
        $this->audit($request, 'landing_page.created', 'landing_page', $id, ['slug' => $data['slug'], 'status' => $data['status']]);
        $this->flash($request, 'success', 'Landing page saved.');

        return $this->redirect('/admin/landing-pages/' . $id);
    }

    public function update(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::CONTENT_MANAGE);
        $this->pages->find($id) ?? throw HttpException::notFound();
        $data = $this->input($request, $id);
        $this->pages->save($id, $data, $this->user($request)->id);
        $this->audit($request, 'landing_page.updated', 'landing_page', $id, ['slug' => $data['slug'], 'status' => $data['status']]);
        $this->flash($request, 'success', 'Landing page saved.');

        return $this->redirect('/admin/landing-pages/' . $id);
    }

    public function delete(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::CONTENT_MANAGE);
        $page = $this->pages->find($id) ?? throw HttpException::notFound();
        $this->pages->delete($id);
        $this->audit($request, 'landing_page.deleted', 'landing_page', $id, ['slug' => $page['slug']]);
        $this->flash($request, 'success', 'Landing page deleted.');

        return $this->redirect('/admin/landing-pages');
    }

    /** Staff preview of a page in any state (not indexed, no canonical address). */
    public function preview(Request $request, int $id): Response
    {
        $page = $this->pages->find($id) ?? throw HttpException::notFound();
        $public = $this->container->get(PublicLandingPages::class);
        $public->setContainer($this->container);

        return $public->present($request, $page, true);
    }

    /**
     * @param array<string, mixed>|null $page
     */
    private function form(Request $request, ?array $page): Response
    {
        return $this->render($request, 'admin/marketing/landing_pages/form.twig', [
            'landing' => $page,
            'catalog_pages' => $this->catalogPages(),
            'lines' => $this->productLines(),
            'shadowed' => $page !== null && $this->routeConflict((string) $page['slug']),
        ]);
    }

    /**
     * @return array{slug: string, status: string, seo_title: ?string, meta_description: ?string, hero_title: string, hero_teaser: ?string, intro: ?string, features: ?string, faq: ?string, website_page: ?string, product_line: ?string}
     */
    private function input(Request $request, int $id): array
    {
        $data = $this->validate($request, [
            'slug' => 'required|string|max:100|slug',
            'status' => 'required|in:' . LandingPages::DRAFT . ',' . LandingPages::PUBLISHED,
            'seo_title' => 'nullable|string|max:120',
            'meta_description' => 'nullable|string|max:320',
            'hero_title' => 'required|string|max:150',
            'hero_teaser' => 'nullable|string|max:400',
            'intro' => 'nullable|string|max:20000',
            'features' => 'nullable|string|max:5000',
            'faq' => 'nullable|string|max:20000',
            'website_page' => 'nullable|in:' . implode(',', array_keys(WebsiteCatalog::PAGES)),
            'product_line' => 'nullable|string|max:100',
        ], ['slug' => 'Address', 'hero_title' => 'Headline']);

        $slug = (string) $data['slug'];
        $error = match (true) {
            in_array($slug, LandingPages::RESERVED, true) => 'This address is reserved. Please choose another one.',
            $this->routeConflict($slug) => 'This address is already used by another page. Please choose another one.',
            $this->pages->slugExists($slug, $id) => 'Another landing page already uses this address.',
            default => null,
        };
        if ($error !== null) {
            throw new ValidationException(['slug' => [$error]], Validator::safeInput($request->all()));
        }

        $text = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        return [
            'slug' => $slug,
            'status' => (string) $data['status'],
            'seo_title' => $text($data['seo_title']),
            'meta_description' => $text($data['meta_description']),
            'hero_title' => trim((string) $data['hero_title']),
            'hero_teaser' => $text($data['hero_teaser']),
            'intro' => $text($data['intro']),
            'features' => $text($data['features']),
            'faq' => $text($data['faq']),
            'website_page' => $text($data['website_page']),
            'product_line' => $text($data['product_line']),
        ];
    }

    /** Whether another (non-fallback) route answers GET /{slug}. */
    private function routeConflict(string $slug): bool
    {
        try {
            [$route] = $this->router->match('GET', '/' . $slug);
        } catch (HttpException) {
            return false;
        }

        return !$this->router->isFallback($route);
    }

    /**
     * @return array<string, string>
     */
    private function catalogPages(): array
    {
        return array_map(static fn (array $page): string => $page['title'], WebsiteCatalog::PAGES);
    }

    /**
     * Product lines used by products (website_line).
     *
     * @return array<string, string>
     */
    private function productLines(): array
    {
        $lines = [];
        foreach ($this->db->fetchAll("SELECT DISTINCT `website_line` FROM `nx_products` WHERE `website_line` IS NOT NULL AND `website_line` <> '' ORDER BY `website_line` LIMIT 200") as $row) {
            $lines[(string) $row['website_line']] = (string) $row['website_line'];
        }

        return $lines;
    }
}
