<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use GuzzleHttp\ClientInterface;
use Nebrix\Core\Config;
use Nebrix\Integration\IntegrationLogger;
use Nebrix\Integration\IntegrationManager;
use Nebrix\Integration\IntegrationRepository;
use Nebrix\Repository\ProductRepository;
use Nebrix\Security\UrlGuard;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\FakeHttp;
use Nebrix\Tests\Support\FakeSkrime;
use Nebrix\Website\WebsiteCatalog;

/**
 * Public website: start page, product pages with packages and configurator,
 * domain search, Trustpilot, switching the website off.
 */
final class WebsiteTest extends FeatureTestCase
{
    private FakeHttp $http;

    private FakeSkrime $skrime;

    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp();
        $this->skrime = new FakeSkrime();
        $this->skrime->register($this->http);
        $container = $this->app->container();
        $container->set(ClientInterface::class, fn (): ClientInterface => $this->http->client());
        $container->set(UrlGuard::class, fn (): UrlGuard => new UrlGuard(false, false, static fn (string $host): array => $host === 'skrime.eu' ? ['203.0.113.90'] : []));
        $container->set(IntegrationManager::class, fn ($c): IntegrationManager => new IntegrationManager($c->get(IntegrationRepository::class), $c->get(ClientInterface::class), $c->get(UrlGuard::class), $c->get(IntegrationLogger::class), $c->get(Config::class)));
        $this->settings = $container->get(SettingsRepository::class);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $extra
     */
    private function product(string $category, string $name, int $price, array $config = [], array $extra = []): int
    {
        return $this->app->container()->get(ProductRepository::class)->create($extra + [
            'category' => $category, 'name' => $name, 'slug' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '') . '-' . random_int(1, 99999), 'price' => $price, 'setup_fee' => 0,
            'currency' => 'EUR', 'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => $config,
            'features' => "Root access\nIPv6",
        ]);
    }

    private function lxc(string $name, int $price, array $config = [], array $extra = []): int
    {
        return $this->product('lxc', $name, $price, $config + ['ostemplate' => 'local:vztmpl/debian-12.tar.zst', 'storage' => 'local-lvm', 'bridge' => 'vmbr0', 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50, 'rate_mbps' => 0], $extra);
    }

    public function testStartPageShowsPublicProductsWithVat(): void
    {
        $this->lxc('vServer S', 349);
        $this->lxc('Hidden', 100, [], ['is_visible' => 0]);
        $this->lxc('Archived', 100, [], ['is_active' => 0]);
        $this->lxc('Sold out', 100, [], ['stock' => 0]);
        $group = $this->db->insert('nx_customer_groups', ['name' => 'Resellers', 'discount_bp' => 0, 'can_order' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->lxc('Reseller only', 50, [], ['customer_group_id' => $group]);
        $this->product('teamspeak', 'TS 32', 199, ['slots' => 32]);

        $response = $this->get('/');
        self::assertSame(200, $response->status());
        $body = $response->body();
        self::assertStringContainsString('<meta name="robots" content="index, follow">', $body);
        self::assertStringContainsString('href="/products/vserver"', $body);
        self::assertStringContainsString('href="/products/teamspeak"', $body);
        self::assertStringNotContainsString('href="/products/kvm"', $body, 'Pages without products are not linked.');
        self::assertStringContainsString('€4.15', $body, 'Lowest price incl. 19 % VAT: 3.49 → 4.15.');
        self::assertStringNotContainsString('€0.60', $body, 'Reseller and sold-out products are not public.');

        $page = $this->get('/products/vserver')->body();
        self::assertStringContainsString('vServer S', $page);
        self::assertStringContainsString('href="/store/', $page);
        foreach (['Hidden', 'Archived', 'Sold out', 'Reseller only'] as $name) {
            self::assertStringNotContainsString($name, $page);
        }
        self::assertSame(404, $this->get('/products/kvm')->status());
        self::assertSame(404, $this->get('/products/unknown')->status());
        self::assertSame(200, $this->get('/products')->status());
        self::assertSame(404, $this->get('/about')->status(), 'No text, no page.');

        // Small businesses show final prices without VAT.
        $this->settings->set('tax.small_business', true);
        $body = $this->get('/products/vserver')->body();
        self::assertStringContainsString('€3.49', $body);
        self::assertStringContainsString('§ 19 UStG', $body);
    }

    public function testConfiguratorOnTheProductPage(): void
    {
        $this->lxc('vServer S', 349);
        $flex = $this->lxc('vServer Flex', 249, ['cores' => 1, 'memory_mb' => 1024, 'disk_gb' => 10, 'configurator' => true, 'cfg_max_cores' => 8, 'cfg_price_core' => '1.50', 'cfg_max_memory_gb' => 16, 'cfg_price_memory_gb' => '1.00']);
        $slug = (string) $this->db->fetchValue('SELECT slug FROM nx_products WHERE id = ?', [$flex]);

        $body = $this->get('/products/vserver')->body();
        self::assertStringContainsString('data-site-tab="tab-configurator"', $body);
        self::assertStringContainsString('action="/store/' . $slug . '"', $body);
        self::assertStringContainsString('data-base="249" data-rate="1900"', $body);
        self::assertStringContainsString('name="cores" min="1" max="8" step="1" value="1" data-step-price="150"', $body);
        self::assertStringContainsString('<input type="hidden" name="disk_gb" value="10">', $body, 'Resources without a range are fixed.');

        // Ordering needs an account; the configuration survives the sign-in.
        $this->assertRedirect($this->get('/store/' . $slug, ['cores' => '4', 'memory_gb' => '8', 'disk_gb' => '10']), '/login');
        $user = $this->createUser();
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'Valid-Password-123']);
        $this->assertRedirect($response, '/store/' . $slug . '?cores=4&memory_gb=8&disk_gb=10');
    }

    public function testDomainSearchChecksAvailabilityWithALimit(): void
    {
        $integration = $this->app->container()->get(IntegrationRepository::class)->create('skrime', 'SKRIME', true, ['environment' => 'live', 'accept_terms' => true, 'nameservers' => "ns1.example-dns.de\nns2.example-dns.de"], ['api_key' => FakeSkrime::KEY]);
        $de = $this->product('domain', '.de', 499, ['tld' => 'de'], ['billing_cycle' => 'annually', 'integration_id' => $integration, 'slug' => 'domain-de', 'sort_order' => 0]);
        $this->product('domain', '.co.uk', 799, ['tld' => 'co.uk'], ['billing_cycle' => 'annually', 'integration_id' => $integration, 'slug' => 'domain-co-uk', 'sort_order' => 1000]);
        $this->product('domain', '.uk', 699, ['tld' => 'uk'], ['billing_cycle' => 'annually', 'integration_id' => $integration, 'slug' => 'domain-uk', 'sort_order' => 1000]);
        $this->skrime->registeredElsewhere = ['taken.de'];
        $this->skrime->premium = ['gold.de'];

        $body = $this->get('/products/domains', ['domain' => 'https://www.Mein-Projekt.DE/path'])->body();
        self::assertStringContainsString('www.mein-projekt.de', $body);
        self::assertStringContainsString('href="/store/domain-de?domain=www.mein-projekt.de"', $body);
        self::assertStringContainsString('site-result-available', $body);
        self::assertSame(['domain' => 'www.mein-projekt.de'], json_decode((string) $this->http->last()->getBody(), true));

        self::assertStringContainsString('site-result-taken', $this->get('/products/domains', ['domain' => 'taken.de'])->body());
        $premium = $this->get('/products/domains', ['domain' => 'gold.de'])->body();
        self::assertStringContainsString('site-result-premium', $premium);
        self::assertStringNotContainsString('href="/store/domain-de?domain=gold.de"', $premium);
        self::assertStringContainsString('href="/store/domain-co-uk?domain=shop.co.uk"', $this->get('/products/domains', ['domain' => 'shop.co.uk'])->body(), 'The longest ending wins.');
        self::assertStringContainsString('site-result-invalid', $this->get('/products/domains', ['domain' => '<script>alert(1)</script>'])->body());
        self::assertStringContainsString('site-result-unsupported', $this->get('/products/domains', ['domain' => 'example.com'])->body());
        // Umlaut domains need the intl extension; without it they are rejected, never mangled.
        self::assertSame(function_exists('idn_to_ascii') ? 'xn--mller-kva.de' : null, WebsiteCatalog::normaliseDomain('müller.de'));

        // Every check is a registrar request: at most 20 per 10 minutes and network.
        for ($n = 0; $n < 20; $n++) {
            $this->get('/products/domains', ['domain' => 'free' . $n . '.de']);
        }
        $requests = count($this->http->requests('POST', '/api/domain/check'));
        self::assertStringContainsString('site-result-limited', $this->get('/products/domains', ['domain' => 'one-more.de'])->body());
        self::assertSame($requests, count($this->http->requests('POST', '/api/domain/check')));

        // The order page takes the domain over (never a secret option such as the auth code).
        $this->actingAs($this->createUser());
        $order = $this->get('/store/domain-de', ['domain' => 'mein-projekt.de', 'auth_code' => 'SECRET-123'])->body();
        self::assertStringContainsString('value="mein-projekt.de"', $order);
        self::assertStringNotContainsString('SECRET-123', $order);
        unset($de);
    }

    public function testTrustpilotIsLoadedOnlyOnRequestAndAllowedOnlyThere(): void
    {
        $this->lxc('vServer S', 349);
        self::assertStringNotContainsString('trustpilot', strtolower($this->get('/')->body()));

        $this->settings->set('website.trustpilot_domain', 'nebrix.net');
        $response = $this->get('/');
        self::assertStringContainsString('https://de.trustpilot.com/evaluate/nebrix.net', str_replace('www.trustpilot.com', 'de.trustpilot.com', $response->body()));
        self::assertStringNotContainsString('data-trustpilot ', $response->body(), 'Without a TrustBox only links are shown.');
        self::assertStringNotContainsString('widget.trustpilot.com', (string) $response->header('Content-Security-Policy'));

        $this->settings->set('website.trustpilot_business_unit', '0123456789abcdef01234567');
        $this->settings->set('website.trustpilot_template', '56278e9abfbbba0bdcd568bc');
        $response = $this->get('/');
        self::assertStringContainsString('data-business-unit="0123456789abcdef01234567"', $response->body());
        self::assertStringNotContainsString('<script src="https://widget.trustpilot.com', $response->body(), 'Nothing is loaded before the click.');
        $csp = (string) $response->header('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self' https://widget.trustpilot.com", $csp);
        self::assertStringContainsString('frame-src https://widget.trustpilot.com', $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('widget.trustpilot.com', (string) $this->get('/products/vserver')->header('Content-Security-Policy'));
    }

    public function testHeadlineIsEscapedAndTheWebsiteCanBeSwitchedOff(): void
    {
        $this->lxc('vServer S', 349);
        $this->settings->set('website.hero_title', 'Hosting <script>alert(1)</script> mit *Herz*');
        $this->settings->set('website.about_text', "Wir sind ein Team.\n\nSeit 2026 <b>online</b>.");
        $body = $this->get('/')->body();
        self::assertStringContainsString('Hosting &lt;script&gt;alert(1)&lt;/script&gt; mit <span class="site-accent">Herz</span>', $body);
        $about = $this->get('/about')->body();
        self::assertStringContainsString('<p>Wir sind ein Team.</p>', $about);
        self::assertStringContainsString('Seit 2026 &lt;b&gt;online&lt;/b&gt;.', $about);

        $this->settings->set('website.enabled', false);
        $this->assertRedirect($this->get('/'), '/login');
        self::assertSame(404, $this->get('/products')->status());
        self::assertSame(404, $this->get('/products/vserver')->status());
        self::assertSame(404, $this->get('/about')->status());
        $this->actingAs($this->createUser());
        $this->assertRedirect($this->get('/'), '/dashboard');
    }
}
