<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use GuzzleHttp\ClientInterface;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Core\Config;
use Nebrix\Domain\Dns\DnsZoneService;
use Nebrix\Integration\IntegrationLogger;
use Nebrix\Integration\IntegrationManager;
use Nebrix\Integration\IntegrationRepository;
use Nebrix\Security\UrlGuard;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\FakeHttp;
use Nebrix\Tests\Support\FakeSkrime;
use Nebrix\Tests\Support\FakeSkrimeDns;

/**
 * DNS record pages of domains through full HTTP requests (SKRIME fake):
 * ownership, the manage right of customer groups, rate limits, CSP-safe markup.
 */
final class DnsRecordsTest extends FeatureTestCase
{
    private FakeHttp $http;

    private FakeSkrime $skrime;

    private FakeSkrimeDns $skrimeDns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp();
        $this->skrime = new FakeSkrime();
        $this->skrime->register($this->http);
        $this->skrimeDns = new FakeSkrimeDns();
        $this->skrimeDns->register($this->http);
        $container = $this->app->container();
        $container->set(ClientInterface::class, fn (): ClientInterface => $this->http->client());
        $container->set(UrlGuard::class, fn (): UrlGuard => new UrlGuard(false, false, static fn (string $host): array => $host === 'skrime.eu' ? ['203.0.113.90'] : []));
        $container->set(IntegrationManager::class, fn ($c): IntegrationManager => new IntegrationManager($c->get(IntegrationRepository::class), $c->get(ClientInterface::class), $c->get(UrlGuard::class), $c->get(IntegrationLogger::class), $c->get(Config::class)));
    }

    private function domain(User $owner, string $domain = 'muster.de'): Service
    {
        $integration = $this->app->container()->get(IntegrationRepository::class)->create('skrime', 'SKRIME ' . $domain, true, ['environment' => 'live', 'accept_terms' => true, 'dns_management' => true], ['api_key' => FakeSkrime::KEY]);
        $this->skrime->domains[$domain] = ['id' => 'P-' . md5($domain), 'handle' => 'H', 'state' => 'active', 'autoRenew' => false, 'expireAt' => '2027-11-28 00:00:00', 'nameserver' => ['ns1.skrime.eu', 'ns2.skrime.eu']];
        $this->skrimeDns->zones[$domain] = [['name' => '@', 'type' => 'A', 'data' => '203.0.113.10']];
        $services = $this->app->container()->get(ServiceRepository::class);
        $id = $services->create([
            'user_id' => $owner->id, 'integration_id' => $integration, 'category' => 'domain', 'name' => $domain, 'status' => Service::STATUS_ACTIVE,
            'billing_cycle' => 'annually', 'recurring_amount' => 900, 'tax_rate_bp' => 1900, 'currency' => 'EUR', 'config' => ['tld' => 'de', '_options' => ['domain' => $domain]],
        ]);
        $services->mergeRemote($id, ['submitted' => 'register', 'registry_ref' => 'P-' . md5($domain), 'registry_env' => 'live', 'domain' => $domain]);

        return $services->find($id) ?? throw new \RuntimeException('missing');
    }

    public function testDnsRecordsAreManagedByTheOwnerOnly(): void
    {
        $owner = $this->createUser();
        $service = $this->domain($owner);
        $this->actingAs($owner);

        $show = $this->get('/services/' . $service->id);
        self::assertStringContainsString('href="/services/' . $service->id . '/dns"', $show->body());
        $page = $this->get('/services/' . $service->id . '/dns');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('203.0.113.10', $page->body());
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>|\sstyle=|\son[a-z]+=/i', $page->body(), 'No inline scripts, styles or handlers.');

        $this->serverOverrides = ['HTTP_REFERER' => 'http://localhost/services/' . $service->id . '/dns'];
        $this->assertRedirect($this->post('/services/' . $service->id . '/dns/records', ['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 -all']), '/services/' . $service->id . '/dns');
        self::assertSame(['name' => '@', 'type' => 'TXT', 'data' => 'v=spf1 -all'], $this->skrimeDns->zones['muster.de'][1]);
        self::assertStringContainsString('The DNS record has been added.', $this->get('/services/' . $service->id . '/dns')->body());

        // Editing a record: the form is filled with it.
        $txt = array_column($this->app->container()->get(DnsZoneService::class)->zone($service)['records'], 'id', 'type')['TXT'];
        self::assertStringContainsString('name="record" value="' . $txt . '"', $this->get('/services/' . $service->id . '/dns', ['edit' => $txt])->body());

        // Validation errors are shown on the page, nothing is sent.
        $this->post('/services/' . $service->id . '/dns/records', ['type' => 'CNAME', 'name' => '@', 'content' => 'example.net']);
        self::assertStringContainsString('A CNAME record is not allowed for the domain itself (@).', $this->get('/services/' . $service->id . '/dns')->body());
        self::assertCount(1, $this->skrimeDns->updates);

        // Another customer: 404 for page and changes, no remote call.
        $this->actingAs($this->createUser());
        self::assertSame(404, $this->get('/services/' . $service->id . '/dns')->status());
        self::assertSame(404, $this->post('/services/' . $service->id . '/dns/records', ['type' => 'A', 'name' => 'x', 'content' => '203.0.113.1'])->status());
        self::assertSame(404, $this->post('/services/' . $service->id . '/dns/records/delete', ['record' => $txt])->status());
        self::assertCount(1, $this->skrimeDns->updates);
    }

    public function testDnsChangesAreRateLimitedAndNeedTheManageRight(): void
    {
        $owner = $this->createUser();
        $service = $this->domain($owner);
        $this->app->container()->get(SettingsRepository::class)->set('server.dns_changes_per_hour', 1);
        $this->actingAs($owner);

        $this->post('/services/' . $service->id . '/dns/records', ['type' => 'A', 'name' => 'a', 'content' => '203.0.113.1']);
        self::assertSame(429, $this->post('/services/' . $service->id . '/dns/records', ['type' => 'A', 'name' => 'b', 'content' => '203.0.113.2'])->status());
        self::assertCount(1, $this->skrimeDns->updates);
        self::assertSame(404, $this->post('/services/' . $service->id . '/dns/records/delete', ['record' => '../../x'])->status(), 'Malformed record IDs.');

        $groupId = $this->db->insert('nx_customer_groups', ['name' => 'Read only', 'discount_bp' => 0, 'can_order' => 1, 'can_manage_services' => 0, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $reader = $this->createUser(Role::CUSTOMER, ['customer_group_id' => $groupId]);
        $readOnly = $this->domain($reader, 'lesen.de');
        $this->actingAs($reader);
        $page = $this->get('/services/' . $readOnly->id . '/dns')->body();
        self::assertStringNotContainsString('/dns/records"', $page, 'No forms without the manage right.');
        self::assertSame(403, $this->post('/services/' . $readOnly->id . '/dns/records', ['type' => 'A', 'name' => 'x', 'content' => '203.0.113.1'])->status());
        self::assertCount(1, $this->skrimeDns->updates);
    }
}
