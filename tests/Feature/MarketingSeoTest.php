<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use Nebrix\Auth\Role;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\MarketingTestHelpers;

/**
 * SEO basics: robots.txt, sitemap (public content only), canonical
 * addresses, OpenGraph / Twitter cards with the uploaded preview image and
 * schema.org data (Organization, Product + Offer, BreadcrumbList) that
 * cannot break out of its script element.
 */
final class MarketingSeoTest extends FeatureTestCase
{
    use MarketingTestHelpers;

    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporaryMediaStore();
        $this->settings = $this->app->container()->get(SettingsRepository::class);
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryMediaStore();
        parent::tearDown();
    }

    private function teamspeak(string $name = 'TS 32', int $price = 199, array $extra = []): int
    {
        return $this->websiteProduct('teamspeak', $name, $price, ['slots' => 32], $extra);
    }

    public function testRobotsTxtKeepsPrivateAreasOutAndLinksTheSitemap(): void
    {
        $response = $this->get('/robots.txt');
        self::assertSame(200, $response->status());
        self::assertStringStartsWith('text/plain', (string) $response->header('Content-Type'));
        $body = $response->body();
        foreach (['Disallow: /admin', 'Disallow: /api/', 'Disallow: /account', 'Disallow: /dashboard', 'Disallow: /invoices', 'Disallow: /services', 'Disallow: /tickets', 'Disallow: /'] as $line) {
            self::assertStringContainsString($line . "\n", $body);
        }
        self::assertStringContainsString("Allow: /products\n", $body);
        self::assertStringContainsString("Sitemap: http://localhost/sitemap.xml\n", $body);
        self::assertStringNotContainsString('Allow: /blog', $body, 'The blog is off by default.');

        $this->settings->set('blog.enabled', true);
        $this->db->insert('nx_landing_pages', ['slug' => 'teamspeak-server-mieten', 'status' => 'published', 'hero_title' => 'TeamSpeak', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);
        $this->db->insert('nx_landing_pages', ['slug' => 'geheim', 'status' => 'draft', 'hero_title' => 'Draft', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);
        $body = $this->get('/robots.txt')->body();
        self::assertStringContainsString("Allow: /blog\n", $body);
        self::assertStringContainsString("Allow: /teamspeak-server-mieten$\n", $body);
        self::assertStringNotContainsString('geheim', $body, 'Drafts are not announced.');

        // Website off: nothing but the status page, no sitemap.
        $this->settings->set('website.enabled', false);
        $body = $this->get('/robots.txt')->body();
        self::assertStringNotContainsString('Sitemap:', $body);
        self::assertStringNotContainsString('Allow: /products', $body);
        self::assertStringContainsString("Allow: /status\nDisallow: /admin", $body);
        self::assertSame(404, $this->get('/sitemap.xml')->status());
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/public/robots.txt', 'A static file would hide the generated one.');
    }

    public function testSitemapListsOnlyPublicContent(): void
    {
        $this->teamspeak();
        $this->websiteProduct('lxc', 'Hidden vServer', 300, ['cores' => 1, 'memory_mb' => 1024, 'disk_gb' => 10], ['is_visible' => 0]);
        $this->settings->set('website.about_text', 'Wir sind ein Team.');
        $this->settings->set('blog.enabled', true);
        $now = $this->clock()->now();
        $this->db->insert('nx_landing_pages', ['slug' => 'teamspeak-server-mieten', 'status' => 'published', 'hero_title' => 'TeamSpeak', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-02 10:00:00']);
        $this->db->insert('nx_landing_pages', ['slug' => 'entwurf-seite', 'status' => 'draft', 'hero_title' => 'Draft', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);
        $post = ['title' => 'Post', 'body' => 'Text', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00'];
        $this->db->insert('nx_blog_posts', $post + ['slug' => 'neues-rechenzentrum', 'status' => 'published', 'published_at' => $now->modify('-1 day')->format('Y-m-d H:i:s')]);
        $this->db->insert('nx_blog_posts', $post + ['slug' => 'geplanter-beitrag', 'status' => 'published', 'published_at' => $now->modify('+1 day')->format('Y-m-d H:i:s')]);
        $this->db->insert('nx_blog_posts', $post + ['slug' => 'entwurf-beitrag', 'status' => 'draft', 'published_at' => null]);

        $response = $this->get('/sitemap.xml');
        self::assertSame(200, $response->status());
        self::assertStringStartsWith('application/xml', (string) $response->header('Content-Type'));
        $xml = simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NONET);
        self::assertNotFalse($xml);
        $urls = [];
        foreach ($xml->url as $url) {
            $urls[(string) $url->loc] = (string) $url->lastmod;
        }
        foreach (['/', '/products', '/products/teamspeak', '/about', '/teamspeak-server-mieten', '/blog', '/blog/neues-rechenzentrum'] as $path) {
            self::assertArrayHasKey('http://localhost' . $path, $urls, $path);
        }
        self::assertSame('2026-11-02T10:00:00+00:00', $urls['http://localhost/teamspeak-server-mieten']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $urls['http://localhost/products/teamspeak']);
        $all = implode(' ', array_keys($urls));
        foreach (['entwurf-seite', 'geplanter-beitrag', 'entwurf-beitrag', '/products/vserver'] as $hidden) {
            self::assertStringNotContainsString($hidden, $all, $hidden . ' is not public.');
        }

        $this->settings->set('blog.enabled', false);
        self::assertStringNotContainsString('/blog', $this->get('/sitemap.xml')->body());
    }

    public function testPagesHaveCanonicalOpenGraphAndStructuredData(): void
    {
        $this->teamspeak('TS 32 </script><script>alert(1)</script>', 199);
        $this->teamspeak('TS 64', 399, ['stock' => 0]);
        $this->settings->set('general.company_name', 'Nebrix Hosting');
        $this->settings->set('seo.same_as', "https://www.linkedin.com/company/nebrix\nhttps://de.trustpilot.com/review/nebrix.test");

        $home = $this->get('/');
        $body = $home->body();
        self::assertStringContainsString('<link rel="canonical" href="http://localhost/">', $body);
        self::assertStringContainsString('<meta property="og:url" content="http://localhost/">', $body);
        self::assertStringContainsString('<meta property="og:type" content="website">', $body);
        self::assertStringContainsString('<meta property="og:site_name" content="Nebrix Hosting">', $body);
        self::assertStringContainsString('<meta name="twitter:card" content="summary">', $body, 'No preview image: small card.');
        self::assertStringContainsString('<meta property="og:title" content="Hosting &amp; servers">', $body, 'Escaped once.');
        self::assertStringContainsString('<meta property="og:locale" content="en_US">', $body);
        self::assertStringNotContainsString('og:image', $body);
        $organization = $this->nodes($this->jsonLd($body), 'Organization')[0] ?? [];
        self::assertSame('Nebrix Hosting', $organization['name'] ?? null);
        self::assertSame(['https://www.linkedin.com/company/nebrix', 'https://de.trustpilot.com/review/nebrix.test'], $organization['sameAs'] ?? null);
        $csp = (string) $home->header('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp, 'The JSON-LD data block needs no exception.');

        $this->settings->set('seo.home_description', 'Server & Hosting aus Deutschland');
        self::assertStringContainsString('<meta name="description" content="Server &amp; Hosting aus Deutschland">', $this->get('/')->body());

        $page = $this->get('/products/teamspeak', ['utm_source' => 'x'])->body();
        self::assertStringContainsString('<link rel="canonical" href="http://localhost/products/teamspeak">', $page, 'Without query parameters.');
        self::assertStringNotContainsString('</script><script>alert(1)', $page);
        $graph = $this->jsonLd($page);
        $products = $this->nodes($graph, 'Product');
        self::assertCount(2, $products);
        self::assertSame('TS 32 </script><script>alert(1)</script>', $products[0]['name'], 'The name survives as data.');
        self::assertSame('2.37', $products[0]['offers']['price'], 'The gross price shown on the page (1.99 + 19 % VAT).');
        self::assertSame('EUR', $products[0]['offers']['priceCurrency']);
        self::assertSame('https://schema.org/InStock', $products[0]['offers']['availability']);
        self::assertSame('https://schema.org/OutOfStock', $products[1]['offers']['availability'], 'Sold out.');
        self::assertSame(1, $products[0]['offers']['priceSpecification']['billingDuration']);
        self::assertStringContainsString('€2.37', $page, 'Same price on the page.');
        $crumbs = $this->nodes($graph, 'BreadcrumbList')[0]['itemListElement'];
        self::assertSame(['http://localhost/', 'http://localhost/products', 'http://localhost/products/teamspeak'], array_column($crumbs, 'item'));

        // Small businesses: final prices, no VAT flag.
        $this->settings->set('tax.small_business', true);
        $offer = $this->nodes($this->jsonLd($this->get('/products/teamspeak')->body()), 'Product')[0]['offers'];
        self::assertSame('1.99', $offer['price']);
        self::assertArrayNotHasKey('valueAddedTaxIncluded', $offer['priceSpecification']);
    }

    public function testAllMarketingPagesRenderWithStrictVariables(): void
    {
        // Development installations render with strict_variables: every page must define what it uses.
        $config = $this->app->container()->get(\Nebrix\Core\Config::class);
        $this->app->container()->set(\Nebrix\View\View::class, static fn ($c): \Nebrix\View\View => new \Nebrix\View\View(
            $config->string('paths.views'),
            null,
            true,
            $c->get(SettingsRepository::class),
            $c->get(\Nebrix\Authorization\Gate::class),
            $c->get(\Nebrix\View\Translator::class),
            $config->string('paths.public') . '/assets',
        ));
        $this->settings->set('blog.enabled', true);
        $this->teamspeak();
        $landing = $this->db->insert('nx_landing_pages', ['slug' => 'teamspeak-server-mieten', 'status' => 'published', 'hero_title' => 'TeamSpeak', 'website_page' => 'teamspeak', 'faq' => "Frage?\nAntwort.", 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);
        $post = $this->db->insert('nx_blog_posts', ['slug' => 'hallo', 'title' => 'Hallo', 'body' => 'Text', 'status' => 'published', 'published_at' => '2026-11-01 00:00:00', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);

        foreach (['/', '/products/teamspeak', '/teamspeak-server-mieten', '/blog', '/blog/hallo'] as $path) {
            self::assertSame(200, $this->get($path)->status(), $path . ' did not render.');
        }
        $this->actingAs($this->createUser(Role::SUPER_ADMIN));
        foreach (['/admin/landing-pages', '/admin/landing-pages/create', '/admin/landing-pages/' . $landing, '/admin/landing-pages/' . $landing . '/preview',
            '/admin/blog', '/admin/blog/create', '/admin/blog/' . $post, '/admin/blog/' . $post . '/preview', '/admin/settings/marketing', '/admin'] as $path) {
            $response = $this->get($path);
            self::assertSame(200, $response->status(), $path . ': ' . strip_tags(substr($response->body(), 0, 300)));
        }
    }

    public function testPreviewImageIsUploadedByBrandingStaffAndUsedForSharing(): void
    {
        $this->teamspeak();
        $this->actingAs($this->staffWith(['settings.general']));
        self::assertSame(403, $this->postFile('/admin/seo/og-image', 'file', $this->png())->status());
        self::assertSame(403, $this->get('/admin/settings/marketing')->status());

        $admin = $this->createUser(Role::ADMIN);
        $this->actingAs($admin);
        self::assertSame(200, $this->get('/admin/settings/marketing')->status());
        $rejected = $this->postFile('/admin/seo/og-image', 'file', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        self::assertTrue($rejected->isRedirect());
        self::assertSame('', $this->settings->string('seo.og_image'), 'Only PNG and JPEG (by content).');

        $this->assertRedirect($this->postFile('/admin/seo/og-image', 'file', $this->png()), '/admin/settings/marketing');
        $name = $this->settings->string('seo.og_image');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $name);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'settings.og_image_uploaded'"));

        $this->cookies = [];
        $body = $this->get('/')->body();
        self::assertStringContainsString('<meta property="og:image" content="http://localhost/media/' . $name . '">', $body);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $body);
        $image = $this->get('/media/' . $name);
        self::assertSame(200, $image->status());
        self::assertSame('image/png', $image->header('Content-Type'));
        self::assertSame('nosniff', $image->header('X-Content-Type-Options'));
        self::assertStringStartsWith('attachment', (string) $image->header('Content-Disposition'));
        self::assertSame($this->png(), $image->body());
        self::assertSame(404, $this->get('/media/' . str_repeat('a', 32) . '.png')->status());

        $this->actingAs($admin);
        $this->post('/admin/seo/og-image/remove');
        self::assertSame('', $this->settings->string('seo.og_image'));
        self::assertSame(404, $this->get('/media/' . $name)->status());
    }
}
