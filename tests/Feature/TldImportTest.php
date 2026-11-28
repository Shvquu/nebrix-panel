<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use GuzzleHttp\ClientInterface;
use Nebrix\Auth\Role;
use Nebrix\Core\Config;
use Nebrix\Domain\TldImporter;
use Nebrix\Integration\IntegrationLogger;
use Nebrix\Integration\IntegrationManager;
use Nebrix\Integration\IntegrationRepository;
use Nebrix\Security\UrlGuard;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\FakeHttp;
use Nebrix\Tests\Support\FakeSkrime;

/**
 * Admin → Products → Import TLDs against the SKRIME fake.
 */
final class TldImportTest extends FeatureTestCase
{
    private FakeHttp $http;

    private FakeSkrime $skrime;

    private int $integrationId;

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
        $this->integrationId = $this->integration('SKRIME live');
    }

    private function integration(string $name, string $type = 'skrime'): int
    {
        return $this->app->container()->get(IntegrationRepository::class)->create($type, $name, true, $type === 'skrime'
            ? ['environment' => 'live', 'nameservers' => "ns1.example-dns.de\nns2.example-dns.de", 'accept_terms' => true]
            : ['environment' => 'ote', 'username' => 'reseller'], $type === 'skrime' ? ['api_key' => FakeSkrime::KEY] : ['password' => 'inwx-pass']);
    }

    /**
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'integration_id' => (string) $this->integrationId, 'markup_percent' => '20', 'markup_fixed' => '0', 'rounding' => '99',
            'min_price' => '4.99', 'max_cost' => '100', 'tlds' => '', 'tax_rate' => '19.00', 'visible' => '1', 'update_prices' => '0',
        ];
    }

    public function testSellingPriceRules(): void
    {
        $options = ['markup_percent' => 20.0, 'markup_fixed' => 0, 'rounding' => '99', 'min_price' => 0];
        self::assertSame(499, TldImporter::sellingPrice(349, $options), '4.19 → 4.99');
        self::assertSame(1199, TldImporter::sellingPrice(940, $options), '11.28 → 11.99');
        self::assertSame(399, TldImporter::sellingPrice(332, $options), '3.984 → 3.99');
        self::assertSame(499, TldImporter::sellingPrice(333, $options), '3.996 → 4.99: never below the markup');
        self::assertSame(449, TldImporter::sellingPrice(349, ['rounding' => '49'] + $options), '4.19 → 4.49');
        self::assertSame(419, TldImporter::sellingPrice(349, ['rounding' => 'none'] + $options));
        self::assertSame(599, TldImporter::sellingPrice(349, ['markup_fixed' => 100] + $options), '+1.00 fixed → 5.19 → 5.99');
        self::assertSame(990, TldImporter::sellingPrice(100, ['min_price' => 990] + $options), 'minimum price');
        self::assertSame(1000, TldImporter::sellingPrice(1000, ['markup_percent' => 0.0, 'rounding' => 'none'] + $options), 'never below the cost');
    }

    public function testPreviewChangesNothingAndImportCreatesDomainProducts(): void
    {
        $this->actingAs($this->createUser(Role::ADMIN));
        self::assertSame(200, $this->get('/admin/products/tlds')->status());

        $preview = $this->post('/admin/products/tlds/preview', $this->form());
        self::assertSame(200, $preview->status());
        $body = $preview->body();
        foreach (['.de', '.com', '.co.uk', '.xn--6frz82g', '.luxury', '€11.99', '€4.99', '€456.99'] as $text) {
            self::assertStringContainsString($text, $body);
        }
        self::assertStringNotContainsString('.broken', $body, 'Rows with an invalid price are ignored.');
        self::assertStringNotContainsString('<script>', $body);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_products WHERE category = 'domain'"), 'The preview stores nothing.');

        $this->assertRedirect($this->post('/admin/products/tlds/import', $this->form()), '/admin/products?category=domain');
        $products = $this->db->fetchAll("SELECT slug, name, price, setup_fee, billing_cycle, tax_rate_bp, is_visible, is_active, auto_provision, integration_id, provisioning_config FROM nx_products WHERE category = 'domain' ORDER BY slug");
        self::assertSame(['domain-co-uk', 'domain-com', 'domain-de', 'domain-xn--6frz82g'], array_column($products, 'slug'), '.luxury costs more than the maximum.');
        $bySlug = array_column($products, null, 'slug');
        self::assertSame(1199, (int) $bySlug['domain-com']['price'], 'The highest of registration, renewal and transfer price is the cost.');
        self::assertSame(499, (int) $bySlug['domain-de']['price']);
        self::assertSame(799, (int) $bySlug['domain-co-uk']['price']);
        self::assertSame('.co.uk', $bySlug['domain-co-uk']['name']);
        self::assertSame(['tld' => 'co.uk'], json_decode((string) $bySlug['domain-co-uk']['provisioning_config'], true));
        self::assertSame('annually', $bySlug['domain-de']['billing_cycle']);
        self::assertSame(1900, (int) $bySlug['domain-de']['tax_rate_bp']);
        self::assertSame(1, (int) $bySlug['domain-de']['is_visible']);
        self::assertSame(1, (int) $bySlug['domain-de']['auto_provision']);
        self::assertSame($this->integrationId, (int) $bySlug['domain-de']['integration_id']);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'products.tlds_imported'"));

        // The new products can be ordered by customers (store lists them).
        $this->actingAs($this->createUser());
        self::assertStringContainsString('/store/domain-de', $this->get('/store')->body());
    }

    public function testRerunKeepsPricesUnlessAskedAndRespectsOtherProducts(): void
    {
        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/products/tlds/import', $this->form(['tlds' => 'de com']));
        self::assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_products WHERE category = 'domain'"));

        // .co.uk is already sold through INWX, and another product uses the address of .eu.
        $inwx = $this->integration('INWX', 'inwx');
        $this->db->insert('nx_products', ['category' => 'domain', 'name' => 'UK domain', 'slug' => 'uk-domain', 'price' => 1500, 'currency' => 'EUR', 'billing_cycle' => 'annually', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 1, 'integration_id' => $inwx, 'provisioning_config' => '{"tld":".co.uk"}', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->db->insert('nx_products', ['category' => 'nextcloud', 'name' => 'Not a domain', 'slug' => 'domain-eu', 'price' => 100, 'currency' => 'EUR', 'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 0, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => '{}', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->skrime->pricelist[] = ['tld' => 'eu', 'create' => '4.00', 'renew' => '4.00', 'transfer' => '4.00', 'restore' => '10.00', 'offer' => false, 'offerTypes' => []];
        $this->skrime->pricelist[1]['renew'] = '12.00';

        $body = $this->post('/admin/products/tlds/preview', $this->form(['tlds' => 'de com co.uk eu']))->body();
        self::assertStringContainsString('sold through another integration', $body);
        self::assertStringContainsString('product address taken', $body);

        $this->post('/admin/products/tlds/import', $this->form(['tlds' => 'de com co.uk eu']));
        self::assertSame(1199, (int) $this->db->fetchValue("SELECT price FROM nx_products WHERE slug = 'domain-com'"), 'Prices stay unless an update is requested.');
        self::assertSame(1500, (int) $this->db->fetchValue("SELECT price FROM nx_products WHERE slug = 'uk-domain'"));
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_products WHERE slug = 'domain-co-uk'"));
        self::assertSame('nextcloud', $this->db->fetchValue("SELECT category FROM nx_products WHERE slug = 'domain-eu'"));

        $this->post('/admin/products/tlds/import', $this->form(['tlds' => 'com', 'update_prices' => '1']));
        self::assertSame(1499, (int) $this->db->fetchValue("SELECT price FROM nx_products WHERE slug = 'domain-com'"), '12.00 × 1.2 = 14.40 → 14.99');
    }

    public function testOnlyProductManagersAndSkrimeIntegrations(): void
    {
        $this->actingAs($this->createUser());
        self::assertSame(403, $this->post('/admin/products/tlds/import', $this->form())->status());

        $this->actingAs($this->createUser(Role::ADMIN));
        $inwx = $this->integration('INWX', 'inwx');
        $response = $this->post('/admin/products/tlds/import', $this->form(['integration_id' => (string) $inwx]));
        self::assertTrue($response->isRedirect());
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_products WHERE category = 'domain'"));
        self::assertSame([], $this->http->requests('GET', '/api/domain/pricelist'));
    }
}
