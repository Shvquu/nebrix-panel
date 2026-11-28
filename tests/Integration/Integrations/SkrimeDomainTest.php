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
        self::assertFalse($this->skrime->domains['muster-shop.de']['autoRenew'], "SKRIME's own renewal stays off: the panel renews paid periods.");
        self::assertTrue($this->reload($service->id)->remote['auto_renew']);
        self::assertSame([$this->skrime->domains['muster-shop.de']['id'], 'live'], [$this->reload($service->id)->remote['registry_ref'], $this->reload($service->id)->remote['registry_env']], 'Ownership is tied to the product ID of the order.');

        // A retry neither orders again nor touches the renewal again.
        $autoRenewCalls = count($this->http->requests('POST', '/api/product/autorenew'));
        $this->app->container()->get(DomainProvisioner::class)->provision($this->reload($service->id), $user);
        self::assertCount(1, $this->skrime->orders);
        self::assertCount($autoRenewCalls, $this->http->requests('POST', '/api/product/autorenew'));

        // Status: expiry and name servers; no auth code is fetched for it.
        $status = $this->app->container()->get(DomainProvisioner::class)->status($this->reload($service->id));
        self::assertSame(['active', $this->skrime->domains['muster-shop.de']['expireAt'], 'ns1.example-dns.de, ns2.example-dns.de'], [$status['state'], $status['connection']['Paid until (registry)'], $status['connection']['Name servers']]);
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
        self::assertFalse($this->skrime->domains['umzug.de']['autoRenew']);
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
        self::assertFalse($this->skrime->domains['aktionen.de']['autoRenew'], 'Unsuspended domains are renewed by the panel, not by SKRIME.');
        $provisioner->terminate($this->reload($service->id));
        self::assertFalse($this->skrime->domains['aktionen.de']['autoRenew'], 'Termination lets the domain end with its term.');
        self::assertArrayHasKey('aktionen.de', $this->skrime->domains, 'Never deleted immediately.');

        // Blocked by SKRIME (e.g. overdue): a clear error, nothing is ordered again.
        $this->skrime->domains['aktionen.de']['blocked'] = true;
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

        // Without the accepted terms nothing is ordered – and nothing is left to adopt later.
        $noTerms = $this->integration(['accept_terms' => false]);
        $blocked = $this->service($user, 'ohne-agb.de', $noTerms);
        try {
            $this->app->container()->get(DomainProvisioner::class)->provision($this->reload($blocked->id), $user);
            self::fail('Ordered without accepted terms.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('not available right now', $exception->getMessage());
        }
        self::assertSame([], $this->skrime->orders);
        self::assertArrayNotHasKey('submitting', $this->reload($blocked->id)->remote);

        // Refused before payment: terms not accepted, registered premium domains, .it, missing house number, sandbox for customers.
        $products = $this->app->container()->get(ProductRepository::class);
        $product = fn (int $integrationId, string $tld): int => $products->create([
            'category' => 'domain', 'name' => '.' . $tld, 'slug' => 'p-' . bin2hex(random_bytes(4)), 'price' => 900, 'currency' => 'EUR', 'billing_cycle' => 'annually',
            'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 1, 'integration_id' => $integrationId, 'provisioning_config' => ['tld' => $tld],
        ]);
        $this->skrime->premium[] = 'teuer.de';
        $this->skrime->registeredElsewhere[] = 'teuer.de';
        $noNumber = $this->customer();
        $this->db->update('nx_users', ['address_line1' => 'Am Markt'], ['id' => $noNumber->id]);
        $noNumber = $this->app->container()->get(UserRepository::class)->find($noNumber->id) ?? $noNumber;
        $sandbox = $this->integration(['environment' => 'sandbox']);
        foreach ([
            [$this->customer(), $product($noTerms, 'de'), ['domain' => 'frei.de'], 'not available right now'],
            [$this->customer(), $product($this->integrationId, 'de'), ['domain' => 'teuer.de', 'auth_code' => 'X'], 'premium domain'],
            [$this->customer(), $product($this->integrationId, 'it'), ['domain' => 'pizza.it'], '.it domains cannot be ordered'],
            [$noNumber, $product($this->integrationId, 'de'), ['domain' => 'frei.de'], 'house number'],
            [$this->customer(), $product($sandbox, 'de'), ['domain' => 'frei.de'], 'not available right now'],
        ] as [$customer, $productId, $options, $message]) {
            try {
                $orders->place($customer, $productId, $options, '127.0.0.1');
                self::fail('Accepted: ' . $message);
            } catch (OrderException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
        // Staff may test the sandbox through the shop.
        $staff = $this->customer();
        $this->db->update('nx_users', ['role_id' => (int) $this->db->fetchValue("SELECT id FROM nx_roles WHERE slug = 'admin'")], ['id' => $staff->id]);
        $staff = $this->app->container()->get(UserRepository::class)->find($staff->id) ?? $staff;
        self::assertIsArray($orders->place($staff, $product($sandbox, 'de'), ['domain' => 'frei.de'], '127.0.0.1'));
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

    public function testAFailedOrderCanNeverAdoptSomeoneElsesDomainLater(): void
    {
        $attacker = $this->customer();
        $provisioner = $this->app->container()->get(DomainProvisioner::class);
        $encrypted = $this->app->container()->get(\Nebrix\Security\Encrypter::class)->encrypt('junk-code', 'order-option:auth_code');
        // Junk transfer: SKRIME refuses (400), the service fails without a pending order.
        $primed = $this->service($attacker, 'victim-shop.de', null, ['auth_code' => ['encrypted' => $encrypted]]);
        try {
            $provisioner->provision($this->reload($primed->id), $attacker);
            self::fail('Refused transfer accepted.');
        } catch (\Nebrix\Domain\OrderRejectedException) {
        }
        self::assertArrayNotHasKey('submitting', $this->reload($primed->id)->remote, 'A refusal leaves nothing pending.');
        $this->db->update('nx_services', ['status' => 'failed'], ['id' => $primed->id]);

        // The victim registers it (or the operator adds it to the account) …
        $victim = $this->customer();
        $this->db->update('nx_users', ['first_name' => 'Vera', 'last_name' => 'Opfer'], ['id' => $victim->id]);
        $victim = $this->app->container()->get(UserRepository::class)->find($victim->id) ?? $victim;
        $own = $this->service($victim, 'victim-shop.de');
        $provisioner->provision($this->reload($own->id), $victim);
        $this->db->update('nx_services', ['status' => 'terminated'], ['id' => $own->id]);

        // … and staff retry the attacker's failed order: it is refused, not adopted.
        try {
            $provisioner->provision($this->reload($primed->id), $attacker);
            self::fail('Foreign domain adopted.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('not registered by this service', $exception->getMessage());
        }
        // Even a service that was left "submitting" (crash before the request) needs the product ID or the registrant.
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($primed->id, ['submitting' => 'register', 'submitting_at' => time()]);
        try {
            $provisioner->provision($this->reload($primed->id), $attacker);
            self::fail('Foreign domain adopted after a forged pending order.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('not registered by this service', $exception->getMessage());
        }
        self::assertNotSame($this->skrime->domains['victim-shop.de']['authcode'], $this->app->container()->get(ServiceRepository::class)->credentials($this->reload($primed->id))['auth_code'] ?? null);
    }

    public function testALostOrderAnswerIsNeverOrderedTwiceAndIsRecognisedSafely(): void
    {
        $user = $this->customer();
        $provisioner = $this->app->container()->get(DomainProvisioner::class);
        $service = $this->service($user, 'verloren.de');
        $this->skrime->loseNextOrderAnswer = true;
        try {
            $provisioner->provision($this->reload($service->id), $user);
            self::fail('A lost answer is not a success.');
        } catch (IntegrationException $exception) {
            self::assertNotInstanceOf(\Nebrix\Domain\OrderRejectedException::class, $exception);
        }
        self::assertSame('register', $this->reload($service->id)->remote['submitting']);

        // SKRIME hides the product for now (404): the retry waits instead of ordering again.
        $this->skrime->domains['verloren.de']['suspended'] = true;
        try {
            $provisioner->provision($this->reload($service->id), $user);
            self::fail('Expected a wait.');
        } catch (RetryLaterException $exception) {
            self::assertStringContainsString('answer to the order was lost', $exception->getMessage());
        }
        self::assertCount(1, $this->skrime->orders, 'Never a second order.');

        // Visible again: recognised by creation time and registrant, the product ID is stored.
        unset($this->skrime->domains['verloren.de']['suspended']);
        self::assertSame('domain:verloren.de', $provisioner->provision($this->reload($service->id), $user)->externalId);
        self::assertSame($this->skrime->domains['verloren.de']['id'], $this->reload($service->id)->remote['registry_ref']);
        self::assertCount(1, $this->skrime->orders);

        // With another registrant it is not taken.
        $other = $this->customer();
        $this->db->update('nx_users', ['last_name' => 'Anders'], ['id' => $other->id]);
        $other = $this->app->container()->get(UserRepository::class)->find($other->id) ?? $other;
        $second = $this->service($other, 'zweite.de');
        $this->skrime->loseNextOrderAnswer = true;
        try {
            $provisioner->provision($this->reload($second->id), $other);
        } catch (IntegrationException) {
        }
        $this->skrime->domains['zweite.de']['contact']['lastname'] = 'Fremd';
        try {
            $provisioner->provision($this->reload($second->id), $other);
            self::fail('Adopted with a different registrant.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('not registered by this service', $exception->getMessage());
        }

        // After the deadline staff are asked to check instead of ordering again.
        $third = $this->service($user, 'dritte.de');
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($third->id, ['domain' => 'dritte.de', 'submitting' => 'register', 'submitting_at' => time() - 3 * 86400]);
        try {
            $provisioner->provision($this->reload($third->id), $user);
            self::fail('Expected a failure.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('Check the order at the registrar', $exception->getMessage());
        }
        self::assertCount(2, $this->skrime->orders);
    }

    public function testPaidPeriodsAreRenewedOnceAndOnlyWhenPaid(): void
    {
        $user = $this->customer();
        $provisioner = $this->app->container()->get(DomainProvisioner::class);
        $service = $this->service($user, 'verlaengern.de');
        $provisioner->provision($this->reload($service->id), $user);
        $expiry = $this->skrime->domains['verlaengern.de']['expireAt'];
        $this->db->update('nx_services', ['status' => 'active', 'next_due_at' => gmdate('Y-m-d H:i:s', (int) strtotime($expiry . ' UTC') - 86400)], ['id' => $service->id]);
        $renewals = $this->app->container()->get(\Nebrix\Provisioning\DomainRenewals::class);

        // Paid until before the registry expiry: nothing to renew.
        self::assertSame(['checked' => 1, 'renewed' => 0, 'failed' => 0], $renewals->process());

        // The customer paid the next year: renewed exactly once.
        $this->db->update('nx_services', ['next_due_at' => gmdate('Y-m-d H:i:s', (int) strtotime($expiry . ' UTC') + 300 * 86400)], ['id' => $service->id]);
        self::assertSame(1, $renewals->process()['renewed']);
        self::assertSame(['verlaengern.de'], $this->skrime->renewals);
        self::assertSame(0, $renewals->process()['renewed'], 'The new expiry covers the paid period.');

        // A lost renewal answer is never repeated from the same expiry date.
        $this->db->update('nx_services', ['next_due_at' => gmdate('Y-m-d H:i:s', (int) strtotime($this->skrime->domains['verlaengern.de']['expireAt'] . ' UTC') + 86400)], ['id' => $service->id]);
        $this->http->on('POST', '/api/product/renew', static fn (): \GuzzleHttp\Psr7\Response => new \GuzzleHttp\Psr7\Response(504, [], 'timeout'));
        self::assertSame(1, $renewals->process()['failed']);
        self::assertSame(0, $renewals->process()['failed'] + $renewals->process()['renewed'], 'Not requested again.');
        self::assertCount(2, $this->http->requests('POST', '/api/product/renew'));

        // Suspended (unpaid) services are not renewed.
        $this->db->update('nx_services', ['status' => 'suspended'], ['id' => $service->id]);
        self::assertSame(0, $renewals->process()['checked']);
    }

    public function testTheEnvironmentOfAnOrderIsRemembered(): void
    {
        $user = $this->customer();
        $sandbox = $this->integration(['environment' => 'sandbox']);
        $service = $this->service($user, 'umgebung.de', $sandbox);
        try {
            $this->app->container()->get(DomainProvisioner::class)->provision($this->reload($service->id), $user);
        } catch (RetryLaterException) {
        }
        self::assertSame('sandbox', $this->reload($service->id)->remote['registry_env']);
        // The integration is switched to live: the sandbox order is not continued there.
        $this->app->container()->get(IntegrationRepository::class)->storeGenerated($sandbox, [], ['environment' => 'live']);
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('ordered in the sandbox environment');
        $this->app->container()->get(DomainProvisioner::class)->provision($this->reload($service->id), $user);
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
