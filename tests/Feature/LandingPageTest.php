<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use Nebrix\Auth\Role;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\MarketingTestHelpers;

/**
 * Landing pages: administration with permissions and audit log, address
 * checks against the route table, drafts only in the staff preview, the
 * packages of the chosen product page and line, FAQ data.
 */
final class LandingPageTest extends FeatureTestCase
{
    use MarketingTestHelpers;

    /**
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'slug' => 'teamspeak-server-mieten',
            'status' => 'published',
            'seo_title' => 'TeamSpeak Server mieten',
            'meta_description' => 'Eigener TeamSpeak-Server ab 1 € im Monat.',
            'hero_title' => 'TeamSpeak Server *mieten*',
            'hero_teaser' => 'In Sekunden bereit.',
            'intro' => "## Warum bei uns?\nSchnell und **sicher**. <script>alert(1)</script>\n[Mehr](javascript:alert(1))",
            'features' => "DDoS-Schutz | Inklusive\nDeutschland | Standort Frankfurt",
            'faq' => "Wie schnell ist der Server bereit?\nSofort nach der Zahlung.\n\nKann ich die Slots ändern?\nJa, jederzeit im Kundenbereich.",
            'website_page' => 'teamspeak',
            'product_line' => '',
        ];
    }

    public function testStaffCreateAndPublishLandingPagesWithPackagesAndFaq(): void
    {
        $this->websiteProduct('teamspeak', 'TS 32', 199, ['slots' => 32], ['website_line' => 'Classic']);
        $this->websiteProduct('teamspeak', 'TS 64', 399, ['slots' => 64], ['website_line' => 'Premium', 'stock' => 0]);
        $this->actingAs($this->createUser(Role::ADMIN));
        self::assertSame(200, $this->get('/admin/landing-pages')->status());
        self::assertSame(200, $this->get('/admin/landing-pages/create')->status());

        $response = $this->post('/admin/landing-pages', $this->form(['status' => 'draft']));
        $id = (int) $this->db->fetchValue("SELECT id FROM nx_landing_pages WHERE slug = 'teamspeak-server-mieten'");
        $this->assertRedirect($response, '/admin/landing-pages/' . $id);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'landing_page.created'"));

        // Drafts are not public – only the staff preview shows them (not indexed).
        $staff = $this->cookies;
        $this->cookies = [];
        self::assertSame(404, $this->get('/teamspeak-server-mieten')->status());
        self::assertStringNotContainsString('teamspeak-server-mieten', $this->get('/sitemap.xml')->body());
        $this->cookies = $staff;
        $preview = $this->get('/admin/landing-pages/' . $id . '/preview');
        self::assertSame(200, $preview->status());
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $preview->body());
        self::assertStringNotContainsString('rel="canonical"', $preview->body());

        $this->post('/admin/landing-pages/' . $id, $this->form());
        self::assertSame('published', $this->db->fetchValue('SELECT status FROM nx_landing_pages WHERE id = ?', [$id]));
        self::assertNotNull($this->db->fetchValue('SELECT published_at FROM nx_landing_pages WHERE id = ?', [$id]));

        $this->cookies = [];
        $page = $this->get('/teamspeak-server-mieten');
        self::assertSame(200, $page->status());
        $body = $page->body();
        self::assertStringContainsString('<title>TeamSpeak Server mieten · ', $body);
        self::assertStringContainsString('<meta name="description" content="Eigener TeamSpeak-Server ab 1 € im Monat.">', $body);
        self::assertStringContainsString('<link rel="canonical" href="http://localhost/teamspeak-server-mieten">', $body);
        self::assertStringContainsString('TeamSpeak Server <span class="site-accent">mieten</span>', $body);
        self::assertStringContainsString('<h2>Warum bei uns?</h2>', $body);
        self::assertStringContainsString('<strong>sicher</strong>', $body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body, 'HTML in Markdown is shown as text.');
        self::assertStringNotContainsString('<script>alert(1)', $body);
        self::assertStringNotContainsString('href="javascript:', $body);
        self::assertStringContainsString('<h3>DDoS-Schutz</h3><p>Inklusive</p>', $body);
        // The packages of the product page, with the existing partials (order link, sold out).
        self::assertStringContainsString('TS 32', $body);
        self::assertStringContainsString('href="/store/ts-32-', $body);
        self::assertStringContainsString('TS 64', $body);
        self::assertStringContainsString('site-btn-disabled', $body);
        self::assertStringContainsString('<summary>Wie schnell ist der Server bereit?</summary>', $body);

        $graph = $this->jsonLd($body);
        $faq = $this->nodes($graph, 'FAQPage')[0]['mainEntity'];
        self::assertCount(2, $faq);
        self::assertSame('Kann ich die Slots ändern?', $faq[1]['name']);
        self::assertSame('Ja, jederzeit im Kundenbereich.', $faq[1]['acceptedAnswer']['text']);
        $offers = array_column($this->nodes($graph, 'Product'), 'offers', 'name');
        self::assertSame('2.37', $offers['TS 32']['price']);
        self::assertSame('https://schema.org/OutOfStock', $offers['TS 64']['availability']);
        self::assertNotEmpty($this->nodes($graph, 'BreadcrumbList'));
        self::assertStringContainsString('http://localhost/teamspeak-server-mieten', $this->get('/sitemap.xml')->body());

        // Only one product line.
        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/landing-pages/' . $id, $this->form(['product_line' => 'Premium']));
        $this->cookies = [];
        $body = $this->get('/teamspeak-server-mieten')->body();
        self::assertStringContainsString('TS 64', $body);
        self::assertStringNotContainsString('TS 32', $body);

        // The website switched off: no landing pages either.
        $this->app->container()->get(SettingsRepository::class)->set('website.enabled', false);
        self::assertSame(404, $this->get('/teamspeak-server-mieten')->status());
    }

    public function testAddressesMustNotCollideWithRoutesOrReservedAreas(): void
    {
        $this->actingAs($this->createUser(Role::ADMIN));
        foreach (['about', 'products', 'login', 'status', 'dashboard', 'blog', 'newsletter', 'admin', 'api', 'assets', 'sitemap'] as $slug) {
            $this->post('/admin/landing-pages', $this->form(['slug' => $slug]));
            self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_landing_pages WHERE slug = ?', [$slug]), $slug . ' must be refused.');
        }
        foreach (['Minecraft', 'minecraft_server', 'minecraft--server', '-minecraft', '../etc'] as $slug) {
            $this->post('/admin/landing-pages', $this->form(['slug' => $slug]));
        }
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_landing_pages'), 'Only lowercase letters, numbers and single dashes.');

        $this->post('/admin/landing-pages', $this->form(['slug' => 'minecraft-server-mieten']));
        $this->post('/admin/landing-pages', $this->form(['slug' => 'minecraft-server-mieten', 'hero_title' => 'Duplicate']));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_landing_pages WHERE slug = 'minecraft-server-mieten'"));

        // Existing routes keep working and win over landing pages; unknown addresses stay 404 (also for POST).
        $this->cookies = [];
        self::assertSame(200, $this->get('/status')->status());
        self::assertSame(404, $this->get('/unknown-page')->status());
        self::assertSame(404, $this->post('/unknown-page')->status());
        $api = $this->get('/api');
        self::assertSame(404, $api->status());
        self::assertStringStartsWith('application/json', (string) $api->header('Content-Type'), 'Unknown API addresses keep their JSON errors.');
    }

    public function testPermissionsForViewingAndChanging(): void
    {
        $id = $this->db->insert('nx_landing_pages', ['slug' => 'entwurf', 'status' => 'draft', 'hero_title' => 'Entwurf', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);

        $this->actingAs($this->createUser());
        self::assertSame(403, $this->get('/admin/landing-pages')->status());
        self::assertSame(403, $this->get('/admin/landing-pages/' . $id . '/preview')->status());

        $this->actingAs($this->staffWith(['tickets.view']));
        self::assertSame(403, $this->get('/admin/landing-pages')->status());
        self::assertSame(403, $this->post('/admin/landing-pages', $this->form())->status());

        // content.view: list, form and preview, but no changes.
        $this->actingAs($this->staffWith(['content.view']));
        self::assertSame(200, $this->get('/admin/landing-pages')->status());
        self::assertSame(200, $this->get('/admin/landing-pages/' . $id)->status());
        self::assertSame(200, $this->get('/admin/landing-pages/' . $id . '/preview')->status());
        self::assertSame(403, $this->get('/admin/landing-pages/create')->status());
        self::assertSame(403, $this->post('/admin/landing-pages', $this->form())->status());
        self::assertSame(403, $this->post('/admin/landing-pages/' . $id, $this->form())->status());
        self::assertSame(403, $this->post('/admin/landing-pages/' . $id . '/delete')->status());
        self::assertSame('draft', $this->db->fetchValue('SELECT status FROM nx_landing_pages WHERE id = ?', [$id]));

        $this->actingAs($this->staffWith(['content.view', 'content.manage']));
        $this->assertRedirect($this->post('/admin/landing-pages/' . $id . '/delete'), '/admin/landing-pages');
        self::assertNull($this->db->fetchValue('SELECT id FROM nx_landing_pages WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'landing_page.deleted'"));
        self::assertSame(404, $this->get('/admin/landing-pages/' . $id)->status());
    }
}
