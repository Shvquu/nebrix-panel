<?php

declare(strict_types=1);

namespace Nebrix\Controller\Marketing;

use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Marketing\LandingPages;
use Nebrix\Marketing\Markdown;
use Nebrix\Marketing\Seo;
use Nebrix\Marketing\SiteContext;
use Nebrix\Website\WebsiteCatalog;
use Twig\Markup;

/**
 * Public landing pages (/{slug}). The route is a fallback route: it is only
 * matched when no other route matches the address, and unknown or
 * unpublished pages answer 404.
 */
final class LandingPageController extends Controller
{
    public function __construct(
        private readonly LandingPages $pages,
        private readonly WebsiteCatalog $catalog,
        private readonly SiteContext $site,
        private readonly Seo $seo,
    ) {
    }

    public function show(Request $request, string $slug): Response
    {
        if (!$this->site->websiteEnabled()) {
            throw HttpException::notFound();
        }
        $page = $this->pages->findPublished($slug) ?? throw HttpException::notFound();

        return $this->present($request, $page, false);
    }

    /**
     * Renders a landing page; also used for the staff preview of drafts
     * (no canonical address, not indexed).
     *
     * @param array<string, mixed> $page
     */
    public function present(Request $request, array $page, bool $preview): Response
    {
        $catalog = $this->catalog->forRequest();
        $key = (string) ($page['website_page'] ?? '');
        $line = trim((string) ($page['product_line'] ?? ''));
        $tabs = [];
        if (isset(WebsiteCatalog::PAGES[$key])) {
            foreach ($catalog->tabs($key) as $tab) {
                if ($line === '' || $tab['line'] === $line) {
                    $tabs[] = $tab;
                }
            }
        }
        $faq = LandingPages::faq(isset($page['faq']) ? (string) $page['faq'] : null);
        $path = '/' . $page['slug'];
        $title = trim((string) ($page['seo_title'] ?? '')) !== '' ? (string) $page['seo_title'] : (string) $page['hero_title'];
        $products = [];
        foreach ($tabs as $tab) {
            foreach ($tab['products'] as $product) {
                $products[] = ['price' => (int) ($key === 'domains' ? $product['register_gross'] : $product['price_gross'])] + $product;
            }
        }
        $description = trim((string) ($page['meta_description'] ?? '')) !== '' ? (string) $page['meta_description'] : (string) ($page['hero_teaser'] ?? '');

        return $this->render($request, 'website/landing.twig', [
            'page' => $page,
            'page_title' => $title,
            'intro' => trim((string) ($page['intro'] ?? '')) !== '' ? new Markup(Markdown::toHtml((string) $page['intro'], $this->seo->baseUrl()), 'UTF-8') : null,
            'features' => LandingPages::features(isset($page['features']) ? (string) $page['features'] : null),
            'faq' => $faq,
            'tabs' => $tabs,
            'catalog_page' => isset(WebsiteCatalog::PAGES[$key]) ? WebsiteCatalog::PAGES[$key] + ['key' => $key] : null,
            'preview' => $preview,
            'seo' => $this->seo->page($path, [
                $this->seo->breadcrumbs([[$this->translate('Home'), '/'], [(string) $page['hero_title'], $path]]),
                ...$this->seo->products($products, $path, $description),
                $this->seo->faq($faq),
            ], ['description' => $description, 'noindex' => $preview]),
        ] + $this->site->layout($catalog));
    }

    private function translate(string $text): string
    {
        return $this->container->get(\Nebrix\View\Translator::class)->translate($text);
    }
}
