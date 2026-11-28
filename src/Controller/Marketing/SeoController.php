<?php

declare(strict_types=1);

namespace Nebrix\Controller\Marketing;

use Nebrix\Authorization\Permission;
use Nebrix\Database\Connection;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Marketing\BlogPosts;
use Nebrix\Marketing\LandingPages;
use Nebrix\Marketing\MediaStore;
use Nebrix\Marketing\Seo;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Website\WebsiteCatalog;

/**
 * /sitemap.xml, /robots.txt and the images of the marketing area (/media/…).
 *
 * The sitemap lists only public content (pages with public products,
 * published landing pages, public blog posts) and exists only while the
 * website is switched on. robots.txt keeps search engines out of the
 * customer area, the administration and the API.
 */
final class SeoController extends Controller
{
    /** Areas that are never indexed (listed explicitly in addition to "Disallow: /"). */
    private const PRIVATE_AREAS = ['/admin', '/api/', '/account', '/dashboard', '/services', '/invoices', '/orders', '/tickets', '/store',
        '/transactions', '/login', '/register', '/password', '/two-factor', '/email', '/logout', '/webhooks', '/r/'];

    public function __construct(
        private readonly WebsiteCatalog $catalog,
        private readonly SettingsRepository $settings,
        private readonly Seo $seo,
        private readonly LandingPages $landingPages,
        private readonly BlogPosts $posts,
        private readonly MediaStore $media,
        private readonly Connection $db,
    ) {
    }

    public function sitemap(Request $request): Response
    {
        if (!$this->settings->bool('website.enabled')) {
            throw HttpException::notFound();
        }
        $catalog = $this->catalog->forRequest();
        $productsChanged = [];
        foreach ($this->db->fetchAll('SELECT `category`, MAX(`updated_at`) AS `changed` FROM `nx_products` WHERE `is_active` = 1 AND `is_visible` = 1 AND `customer_group_id` IS NULL GROUP BY `category`') as $row) {
            $productsChanged[(string) $row['category']] = (string) $row['changed'];
        }
        $newest = $productsChanged !== [] ? max($productsChanged) : null;

        $urls = [['/', $newest]];
        $pages = $catalog->pages();
        if ($pages !== []) {
            $urls[] = ['/products', $newest];
        }
        foreach ($pages as $key => $page) {
            $changed = array_values(array_filter(array_map(static fn (string $category): ?string => $productsChanged[$category] ?? null, $page['categories'])));
            $urls[] = ['/products/' . $key, $changed !== [] ? max($changed) : null];
        }
        if (trim($this->settings->string('website.about_text')) !== '') {
            $changed = $this->db->fetchValue("SELECT `updated_at` FROM `nx_settings` WHERE `key` = 'website.about_text'");
            $urls[] = ['/about', is_string($changed) ? $changed : null];
        }
        foreach ($this->landingPages->published() as $page) {
            $urls[] = ['/' . $page['slug'], $page['updated_at']];
        }
        if ($this->settings->bool('blog.enabled')) {
            $posts = $this->posts->sitemap();
            if ($posts !== []) {
                $urls[] = ['/blog', max(array_map(static fn (array $p): string => max($p['published_at'], $p['updated_at']), $posts))];
            }
            foreach ($posts as $post) {
                $urls[] = ['/blog/' . $post['slug'], max($post['published_at'], $post['updated_at'])];
            }
        }

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ($urls as [$path, $changed]) {
            $xml->startElement('url');
            $xml->writeElement('loc', $this->seo->url($path));
            if ($changed !== null && $changed !== '') {
                $xml->writeElement('lastmod', Seo::iso($changed));
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        return new Response($xml->outputMemory(), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function robots(Request $request): Response
    {
        $lines = ['User-agent: *'];
        $website = $this->settings->bool('website.enabled');
        if ($website) {
            $lines[] = 'Allow: /$';
            $lines[] = 'Allow: /products';
            $lines[] = 'Allow: /about';
            if ($this->settings->bool('blog.enabled')) {
                $lines[] = 'Allow: /blog';
            }
            $lines[] = 'Allow: /media/';
            foreach ($this->landingPages->published() as $page) {
                $lines[] = 'Allow: /' . $page['slug'] . '$';
            }
        }
        $lines[] = 'Allow: /status';
        foreach (self::PRIVATE_AREAS as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        $lines[] = 'Disallow: /';
        if ($website) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $this->seo->url('/sitemap.xml');
        }

        return new Response(implode("\n", $lines) . "\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * An uploaded image: public while it belongs to published content (the
     * preview image for social networks, the cover of a public blog post);
     * covers of drafts and scheduled posts only for staff with content.view.
     */
    public function media(Request $request, string $name): Response
    {
        $file = $this->media->path($name) ?? throw HttpException::notFound();
        $public = $this->settings->string('seo.og_image') === $name || $this->posts->isPublicCover($name);
        if (!$public && !($this->posts->isCover($name) && $this->can($request, Permission::CONTENT_VIEW))) {
            throw HttpException::notFound();
        }

        return new Response((string) file_get_contents($file), 200, [
            'Content-Type' => MediaStore::mime($name),
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            // Social networks and chat apps may show the preview image from other origins.
            'Cross-Origin-Resource-Policy' => $public ? 'cross-origin' : 'same-origin',
            'Cache-Control' => $public ? 'public, max-age=86400' : 'private, no-store',
        ]);
    }
}
