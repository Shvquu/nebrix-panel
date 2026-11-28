<?php

declare(strict_types=1);

namespace Nebrix\Tests\Integration\Integrations;

use GuzzleHttp\ClientInterface;
use Nebrix\Billing\OrderException;
use Nebrix\Billing\OrderService;
use Nebrix\Core\Config;
use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\IntegrationLogger;
use Nebrix\Integration\IntegrationManager;
use Nebrix\Integration\IntegrationRepository;
use Nebrix\Integration\Skrime\SkrimeClient;
use Nebrix\Provisioning\DomainProvisioner;
use Nebrix\Provisioning\RetryLaterException;
use Nebrix\Provisioning\ServiceActionRunner;
use Nebrix\Repository\ProductRepository;
use Nebrix\Repository\UserRepository;
use Nebrix\Security\UrlGuard;
use Nebrix\Service\ServiceRepository;
use Nebrix\Tests\Support\FakeHttp;
use Nebrix\Tests\Support\FakeSkrime;

/**
 * Domains through SKRIME against a stateful fake of its API.
 */
final class SkrimeDomainTest extends IntegrationTestCase
{
    private FakeSkrime $skrime;

    private int $integrationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skrime = new FakeSkrime();
        $this->skrime->register($this->http);
        $container = $this->app->container();
        $container->set(UrlGuard::class, fn (): UrlGuard => new UrlGuard(false, false, static fn (string $host): array => $host === 'skrime.eu' ? ['203.0.113.90'] : []));
        $container->set(IntegrationManager::class, fn ($c): IntegrationManager => new IntegrationManager($c->get(IntegrationRepository::class), $c->get(ClientInterface::class), $c->get(UrlGuard::class), $c->get(IntegrationLogger::class), $c->get(Config::class)));
        $this->integrationId = $this->integration();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function integration(array $config = []): int
    {
        return $this->createIntegration('skrime', $config + ['environment' => 'live', 'nameservers' => "ns1.example-dns.de\nns2.example-dns.de", 'accept_terms' => true, 'verify_tls' => true], ['api_key' => FakeSkrime::KEY]);
    }

    private function customer(string $country = 'DE'): \Nebrix\Auth\User
    {
        $user = $this->createUser();
        $this->db->update('nx_users', ['first_name' => 'Max', 'last_name' => 'Muster', 'company' => 'Muster GmbH', 'address_line1' => 'Hauptstraße 5a', 'postcode' => '45879', 'city' => 'Gelsenkirchen', 'country' => $country, 'phone' => '0209 123456'], ['id' => $user->id]);

        return $this->app->container()->get(UserRepository::class)->find($user->id) ?? $user;
    }

    private function service(\Nebrix\Auth\User $user, string $domain, ?int $integrationId = null, array $options = []): \Nebrix\Service\Service
    {
        $tld = substr($domain, strrpos($domain, '.') + 1);

        return $this->createService($user, 'domain', $integrationId ?? $this->integrationId, ['tld' => $tld, '_options' => ['domain' => $domain] + $options]);
    }

    public function testConnectionTestShowsAccountAndTermsState(): void
    {
        $manager = $this->app->container()->get(IntegrationManager::class);
        $repository = $this->app->container()->get(IntegrationRepository::class);
        $result = $manager->testConnection($repository->find($this->integrationId) ?? throw new \RuntimeException('missing'));
        self::assertTrue($result->isHealthy());
        self::assertStringContainsString('Reseller GmbH', $result->message);
        self::assertStringContainsString('25.00 EUR', $result->message);
        self::assertSame('Bearer ' . FakeSkrime::KEY, $this->http->last()->getHeaderLine('Authorization'));
        self::assertSame('/api/account/user', $this->http->last()->getUri()->getPath(), 'Live environment.');

        $withoutTerms = $this->integration(['accept_terms' => false, 'environment' => 'sandbox']);
        $result = $manager->testConnection($repository->find($withoutTerms) ?? throw new \RuntimeException('missing'));
        self::assertSame('degraded', $result->status);
        self::assertStringContainsString('sandbox', $result->message);
        self::assertStringStartsWith('/sandbox/', $this->http->last()->getUri()->getPath());

        $wrongKey = $this->createIntegration('skrime', ['environment' => 'live', 'accept_terms' => true, 'verify_tls' => true], ['api_key' => 'skr_wrong_key_0000000000']);
        $result = $manager->testConnection($repository->find($wrongKey) ?? throw new \RuntimeException('missing'));
        self::assertSame('down', $result->status);
        self::assertStringContainsString('Unauthorized access', $result->message);
    }

    public function testRegistrationSendsTheRegistrantAndEnablesAutoRenewal(): void
    {
        $user = $this->customer();
        $service = $this->service($user, 'muster-shop.de');
        $result = $this->app->container()->get(DomainProvisioner::class)->provision($this->reload($service->id), $user);

        self::assertSame('domain:muster-shop.de', $result->externalId);
        self::assertCount(1, $this->skrime->orders);
        $order = $this->skrime->orders[0]['body'];
        self::assertSame([
            'company' => 'Muster GmbH', 'firstname' => 'Max', 'lastname' => 'Muster', 'street' => 'Hauptstraße', 'number' => '5a',
            'postcode' => '45879', 'city' => 'Gelsenkirchen', 'country' => 'DE', 'email' => $user->email, 'phone' => '+49.209123456',
        ], $order['contact']);
        self::assertSame([true, true, ['ns1.example-dns.de', 'ns2.example-dns.de']], [$order['tos'], $order['cancellation'], $order['nameserver']]);
        self::assertArrayNotHasKey('authCode', $order);
        self::assertTrue($this->skrime->domains['muster-shop.de']['autoRenew'], 'Renewal follows the panel billing once the domain is active.');
        self::assertTrue($this->reload($service->id)->remote['auto_renew']);

        // A retry neither orders again nor touches the renewal again.
        $autoRenewCalls = count($this->http->requests('POST', '/api/product/autorenew'));
        $this->app->container()->get(DomainProvisioner::class)->provision($this->reload($service->id), $user);
        self::assertCount(1, $this->skrime->orders);
        self::assertCount($autoRenewCalls, $this->http->requests('POST', '/api/product/autorenew'));

        // Status: expiry and name servers; no auth code is fetched for it.
        $status = $this->app->container()->get(DomainProvisioner::class)->status($this->reload($service->id));
        self::assertSame(['active', '2027-11-28 10:00:00', 'ns1.example-dns.de, ns2.example-dns.de'], [$status['state'], $status['connection']['Paid until (registry)'], $status['connection']['Name servers']]);
        self::assertSame([], $this->http->requests('GET', '/api/domain/authcode'));
    }

    public function testTransferWaitsUntilSkrimeReportsTheDomainActive(): void
    {
        $user = $this->customer();
        $this->skrime->registeredElsewhere[] = 'umzug.de';
        $encrypted = $this->app->container()->get(\Nebrix\Security\Encrypter::class)->encrypt('Tr4nsfer-Code', 'order-option:auth_code');
        $service = $this->service($user, 'umzug.de', null, ['auth_code' => ['encrypted' => $encrypted]]);
        $provisioner = $this->app->container()->get(DomainProvisioner::class);
        try {
            $provisioner->provision($this->reload($service->id), $user);
            self::fail('A pending transfer is awaited.');
        } catch (RetryLaterException $exception) {
            self::assertStringContainsString('transfer', $exception->getMessage());
        }
        self::assertSame('Tr4nsfer-Code', $this->skrime->orders[0]['body']['authCode']);
        self::assertArrayNotHasKey('auth_code', $this->reload($service->id)->config['_options'], 'The auth code is removed after submission.');
        self::assertFalse($this->skrime->domains['umzug.de']['autoRenew']);

        $this->skrime->domains['umzug.de']['state'] = 'active';
        self::assertSame('domain:umzug.de', $provisioner->provision($this->reload($service->id), $user)->externalId);
        self::assertCount(1, $this->skrime->orders, 'Never ordered twice.');
        self::assertTrue($this->skrime->domains['umzug.de']['autoRenew']);
    }

    public function testActionsSuspensionAndTermination(): void
    {
        $user = $this->customer();
        $service = $this->service($user, 'aktionen.de');
        $provisioner = $this->app->container()->get(DomainProvisioner::class);
        $provisioner->provision($this->reload($service->id), $user);
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        $runner = $this->app->container()->get(ServiceActionRunner::class);

        $runner->run($this->reload($service->id), 'nameservers', ['ns1' => 'NS1.Example.net', 'ns2' => 'ns2.example.net', 'ns3' => ''], $user->id, null);
        self::assertSame(['ns1.example.net', 'ns2.example.net'], $this->skrime->domains['aktionen.de']['nameserver']);

        $runner->run($this->reload($service->id), 'auth_code', [], $user->id, null);
        self::assertSame($this->skrime->domains['aktionen.de']['authcode'], $this->app->container()->get(ServiceRepository::class)->credentials($this->reload($service->id))['auth_code']);
        self::assertCount(1, $this->http->requests('GET', '/api/domain/authcode'), 'Fetched only on request.');

        $provisioner->suspend($this->reload($service->id));
        self::assertFalse($this->skrime->domains['aktionen.de']['autoRenew']);
        $provisioner->unsuspend($this->reload($service->id));
        self::assertTrue($this->skrime->domains['aktionen.de']['autoRenew']);
        $provisioner->terminate($this->reload($service->id));
        self::assertFalse($this->skrime->domains['aktionen.de']['autoRenew'], 'Termination lets the domain end with its term.');
        self::assertArrayHasKey('aktionen.de', $this->skrime->domains, 'Never deleted immediately.');

        // Blocked by SKRIME (e.g. overdue): a clear error, nothing is ordered again.
        $this->skrime->domains['aktionen.de']['suspended'] = true;
        try {
            $provisioner->status($this->reload($service->id));
            self::fail('Blocked product accepted.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('Access blocked due to product suspension', $exception->getMessage());
        }
    }

    public function testOrderChecksAndSafety(): void
    {
        $orders = $this->app->container()->get(OrderService::class);
        $products = $this->app->container()->get(ProductRepository::class);
        $create = fn (string $tld): int => $products->create([
            'category' => 'domain', 'name' => '.' . $tld . ' domain', 'slug' => $tld . '-' . bin2hex(random_bytes(3)), 'price' => 900, 'currency' => 'EUR', 'billing_cycle' => 'annually',
            'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 1, 'integration_id' => $this->integrationId, 'provisioning_config' => ['tld' => $tld],
        ]);
        $this->skrime->premium[] = 'luxus.de';
        $this->skrime->registeredElsewhere[] = 'vergeben.de';
        $de = $create('de');
        $eu = $create('eu');
        foreach ([
            [$this->customer(), $de, ['domain' => 'luxus.de'], 'premium domain'],
            [$this->customer(), $de, ['domain' => 'vergeben.de'], 'already registered'],
            [$this->customer('CH'), $eu, ['domain' => 'schweiz.eu'], 'EU or the EEA'],
        ] as [$user, $productId, $options, $message]) {
            try {
                $orders->place($user, $productId, $options, '127.0.0.1');
                self::fail('Accepted: ' . $options['domain']);
            } catch (OrderException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
        self::assertSame([], $this->skrime->orders);

        // A domain that is already in the SKRIME account is never adopted.
        $this->skrime->domains['eigene.de'] = ['id' => 'X', 'handle' => 'H', 'state' => 'active', 'autoRenew' => true, 'expireAt' => '2027-01-01 00:00:00', 'nameserver' => ['a.example', 'b.example'], 'contact' => [], 'authcode' => 'x'];
        $user = $this->customer();
        try {
            $this->app->container()->get(DomainProvisioner::class)->provision($this->reload($this->service($user, 'eigene.de')->id), $user);
            self::fail('Existing domain adopted.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('not registered by this service', $exception->getMessage());
        }

        // Without the accepted terms nothing is ordered.
        $noTerms = $this->integration(['accept_terms' => false]);
        try {
            $this->app->container()->get(DomainProvisioner::class)->provision($this->reload($this->service($user, 'ohne-agb.de', $noTerms)->id), $user);
            self::fail('Ordered without accepted terms.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('accept the SKRIME terms', $exception->getMessage());
        }
        self::assertSame([], $this->skrime->orders);
    }

    public function testSandboxOrdersAreValidatedOnly(): void
    {
        $sandbox = $this->integration(['environment' => 'sandbox', 'nameservers' => '']);
        $user = $this->customer();
        $service = $this->service($user, 'probe.de', $sandbox);
        try {
            $this->app->container()->get(DomainProvisioner::class)->provision($this->reload($service->id), $user);
            self::fail('The sandbox creates no domain to wait for.');
        } catch (RetryLaterException) {
        }
        self::assertSame('/sandbox/domain/order', $this->skrime->orders[0]['path']);
        self::assertSame(['nameserver01.eu', 'nameserver02.eu', 'nameserver03.eu'], $this->skrime->orders[0]['body']['nameserver'], 'Empty setting: the account default name servers.');
        self::assertSame([], $this->skrime->domains);
    }

    public function testStreetsAndContactsAreMappedSafely(): void
    {
        self::assertSame(['Musterstraße', '1a'], SkrimeClient::splitStreet('Musterstraße 1a'));
        self::assertSame(['Am Ring', '12-14'], SkrimeClient::splitStreet('Am Ring 12-14'));
        self::assertSame(['Hauptstr.', '5 / 2'], SkrimeClient::splitStreet('Hauptstr. 5 / 2'));
        self::assertSame(['Main Street', '221'], SkrimeClient::splitStreet('221 Main Street'));
        self::assertSame(['Postfach', ''], SkrimeClient::splitStreet('Postfach'));
        $this->expectException(IntegrationException::class);
        SkrimeClient::contact(['firstname' => 'Max', 'lastname' => '', 'street' => 'A 1', 'postcode' => '1', 'city' => 'B', 'country' => 'DE', 'email' => 'a@b.c', 'phone' => '+49.1']);
    }

    public function testErrorsCarrySkrimesMessage(): void
    {
        $this->http->on('POST', '/api/domain/check', fn (): \GuzzleHttp\Psr7\Response => new \GuzzleHttp\Psr7\Response(429, ['Content-Type' => 'application/json'], '{"response":"Too many requests, please try again later","state":"error","data":[]}'));
        $registrar = $this->app->container()->get(IntegrationManager::class)->registrar($this->app->container()->get(IntegrationRepository::class)->find($this->integrationId) ?? throw new \RuntimeException('missing'));
        try {
            $registrar->isAvailable('test.de');
            self::fail('Error swallowed.');
        } catch (IntegrationException $exception) {
            self::assertSame(429, $exception->httpStatus());
            self::assertStringContainsString('Too many requests', $exception->getMessage());
        }
        unset($registrar);
        self::assertInstanceOf(FakeHttp::class, $this->http);
    }
}
