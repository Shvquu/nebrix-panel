<?php

declare(strict_types=1);

namespace Nebrix\Tests\Integration\Integrations;

use GuzzleHttp\ClientInterface;
use Nebrix\Core\Config;
use Nebrix\Domain\Dns\DnsZoneService;
use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\IntegrationLogger;
use Nebrix\Integration\IntegrationManager;
use Nebrix\Integration\IntegrationRepository;
use Nebrix\Provisioning\ServiceStateException;
use Nebrix\Security\UrlGuard;
use Nebrix\Security\ValidationException;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;
use Nebrix\Tests\Support\FakeInwxDns;
use Nebrix\Tests\Support\FakeSkrime;
use Nebrix\Tests\Support\FakeSkrimeDns;

/**
 * DNS records of domains at SKRIME and INWX (fake APIs): validation,
 * ownership, full-zone replacement (SKRIME) and record IDs (INWX).
 */
final class DnsZoneTest extends IntegrationTestCase
{
    private FakeSkrime $skrime;

    private FakeSkrimeDns $skrimeDns;

    private FakeInwxDns $inwx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skrime = new FakeSkrime();
        $this->skrime->register($this->http);
        $this->skrimeDns = new FakeSkrimeDns();
        $this->skrimeDns->register($this->http);
        $this->inwx = new FakeInwxDns();
        $this->inwx->register($this->http);
        $container = $this->app->container();
        $container->set(UrlGuard::class, fn (): UrlGuard => new UrlGuard(false, false, static fn (string $host): array => $host === 'skrime.eu' || str_ends_with($host, 'domrobot.com') ? ['203.0.113.90'] : []));
        $container->set(IntegrationManager::class, fn ($c): IntegrationManager => new IntegrationManager($c->get(IntegrationRepository::class), $c->get(ClientInterface::class), $c->get(UrlGuard::class), $c->get(IntegrationLogger::class), $c->get(Config::class)));
    }

    private function dns(): DnsZoneService
    {
        return $this->app->container()->get(DnsZoneService::class);
    }

    private function skrimeDomain(string $domain = 'muster.de', bool $dns = true): Service
    {
        $integration = $this->createIntegration('skrime', ['environment' => 'live', 'accept_terms' => true, 'dns_management' => $dns], ['api_key' => FakeSkrime::KEY]);
        $productId = '00000000-0000-4000-8000-' . substr(md5($domain), 0, 12);
        $this->skrime->domains[$domain] = ['id' => $productId, 'handle' => 'H', 'state' => 'active', 'autoRenew' => false, 'expireAt' => '2027-11-28 00:00:00', 'nameserver' => ['ns1.skrime.eu', 'ns2.skrime.eu']];
        $service = $this->createService($this->createUser(), 'domain', $integration, ['tld' => 'de', '_options' => ['domain' => $domain]]);
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['submitted' => 'register', 'registry_ref' => $productId, 'registry_env' => 'live', 'domain' => $domain]);
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);

        return $this->reload($service->id);
    }

    private function inwxDomain(string $domain = 'beispiel.de'): Service
    {
        $integration = $this->createIntegration('inwx', ['environment' => 'ote', 'username' => 'reseller', 'nameservers' => "ns.inwx.de\nns2.inwx.de", 'dns_management' => true, 'verify_tls' => true], ['password' => 'inwx-pass']);
        $this->inwx->domains[$domain] = ['domain' => $domain, 'status' => 'OK', 'exDate' => '2027-11-28', 'ns' => ['ns.inwx.de', 'ns2.inwx.de'], 'registrant' => 4711];
        $service = $this->createService($this->createUser(), 'domain', $integration, ['tld' => 'de', '_options' => ['domain' => $domain]]);
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['submitted' => 'register', 'contact_id' => '4711', 'domain' => $domain]);
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);

        return $this->reload($service->id);
    }

    /**
     * @return array<string, string> "TYPE name" => record ID
     */
    private function ids(Service $service): array
    {
        $ids = [];
        foreach ($this->dns()->zone($service)['records'] as $record) {
            $ids[$record['type'] . ' ' . $record['name']] = $record['id'];
        }

        return $ids;
    }

    public function testSkrimeZoneIsReplacedWithAllRecordsKept(): void
    {
        $service = $this->skrimeDomain();
        $original = [
            ['name' => '@', 'type' => 'NS', 'data' => 'ns1.skrime.eu'],
            ['name' => '@', 'type' => 'A', 'data' => '203.0.113.10'],
            ['name' => 'www', 'type' => 'CNAME', 'data' => 'muster.de.'],
            ['name' => '@', 'type' => 'MX', 'data' => '10 mail.muster.de'],
            ['name' => '@', 'type' => 'TXT', 'data' => '"v=spf1 mx -all"'],
            ['name' => '_sip._tcp', 'type' => 'SRV', 'data' => '10 5 5060 sip.muster.de'],
            ['name' => '_25._tcp.mail', 'type' => 'TLSA', 'data' => '3 1 1 abcdef'],
        ];
        $this->skrimeDns->zones['muster.de'] = $original;
        self::assertTrue($this->dns()->available($service));

        $zone = $this->dns()->zone($service);
        self::assertTrue($zone['hosted']);
        self::assertFalse($zone['ttl'], 'SKRIME has no per-record TTL.');
        $byType = array_column($zone['records'], null, 'type');
        self::assertSame(['content' => 'mail.muster.de', 'prio' => 10, 'editable' => true], array_intersect_key($byType['MX'], ['prio' => 1, 'content' => 1, 'editable' => 1]));
        self::assertSame(['content' => '5 5060 sip.muster.de', 'prio' => 10], array_intersect_key($byType['SRV'], ['prio' => 1, 'content' => 1]));
        self::assertSame('v=spf1 mx -all', $byType['TXT']['content']);
        self::assertSame('muster.de', $byType['CNAME']['content']);
        self::assertFalse($byType['NS']['editable']);
        self::assertFalse($byType['TLSA']['editable']);

        $this->dns()->create($service, ['type' => 'A', 'name' => 'Shop.muster.de.', 'content' => '203.0.113.55'], $service->userId, '198.51.100.7');
        self::assertSame(['domain' => 'muster.de', 'records' => [...$original, ['name' => 'shop', 'type' => 'A', 'data' => '203.0.113.55']]], $this->skrimeDns->updates[0], 'All records are sent, the new one appended.');

        $ids = $this->ids($service);
        $this->dns()->update($service, $ids['MX @'], ['type' => 'MX', 'name' => '@', 'content' => 'mx2.muster.de', 'prio' => '20'], $service->userId, null);
        self::assertSame(['name' => '@', 'type' => 'MX', 'data' => '20 mx2.muster.de'], $this->skrimeDns->updates[1]['records'][3]);
        self::assertSame($original[6], $this->skrimeDns->updates[1]['records'][6], 'Unknown types are passed on unchanged.');

        $this->dns()->delete($service, $this->ids($service)['TXT @'], $service->userId, null);
        $types = array_column($this->skrimeDns->updates[2]['records'], 'type');
        self::assertSame(['NS', 'A', 'CNAME', 'MX', 'SRV', 'TLSA', 'A'], $types);

        $actions = array_column($this->db->fetchAll("SELECT action FROM nx_audit_log WHERE target_type = 'service' AND target_id = ? ORDER BY id", [$service->id]), 'action');
        self::assertSame(['domain.dns_record_created', 'domain.dns_record_updated', 'domain.dns_record_deleted'], $actions);
    }

    public function testRecordsAreValidatedStrictly(): void
    {
        $service = $this->skrimeDomain();
        $this->skrimeDns->zones['muster.de'] = [
            ['name' => '@', 'type' => 'NS', 'data' => 'ns1.skrime.eu'],
            ['name' => 'www', 'type' => 'A', 'data' => '203.0.113.10'],
            ['name' => 'blog', 'type' => 'CNAME', 'data' => 'ghs.example.net'],
        ];
        $invalid = [
            'unknown type' => [['type' => 'NS', 'name' => 'sub', 'content' => 'ns.example.net'], 'type'],
            'CNAME at the apex' => [['type' => 'CNAME', 'name' => '@', 'content' => 'example.net'], 'name'],
            'CNAME next to A' => [['type' => 'CNAME', 'name' => 'www', 'content' => 'example.net'], 'name'],
            'A next to CNAME' => [['type' => 'A', 'name' => 'blog', 'content' => '203.0.113.11'], 'name'],
            'CNAME to itself' => [['type' => 'CNAME', 'name' => 'self', 'content' => 'self.muster.de'], 'content'],
            'CNAME to an IP' => [['type' => 'CNAME', 'name' => 'ip', 'content' => '203.0.113.1'], 'content'],
            'invalid IPv4' => [['type' => 'A', 'name' => 'x', 'content' => '203.0.113.300'], 'content'],
            'IPv6 in an A record' => [['type' => 'A', 'name' => 'x', 'content' => '2001:db8::1'], 'content'],
            'invalid IPv6' => [['type' => 'AAAA', 'name' => 'x', 'content' => '2001:db8::zz'], 'content'],
            'MX without priority' => [['type' => 'MX', 'name' => '@', 'content' => 'mail.muster.de'], 'prio'],
            'MX priority too high' => [['type' => 'MX', 'name' => '@', 'content' => 'mail.muster.de', 'prio' => '70000'], 'prio'],
            'MX to an IP' => [['type' => 'MX', 'name' => '@', 'content' => '203.0.113.5', 'prio' => '10'], 'content'],
            'TXT with control characters' => [['type' => 'TXT', 'name' => '@', 'content' => "a\x00b"], 'content'],
            'TXT with umlauts' => [['type' => 'TXT', 'name' => '@', 'content' => 'grüße'], 'content'],
            'TXT too long' => [['type' => 'TXT', 'name' => '@', 'content' => str_repeat('a', 1025)], 'content'],
            'SRV without service' => [['type' => 'SRV', 'name' => 'sip', 'content' => '5 5060 sip.muster.de', 'prio' => '10'], 'name'],
            'SRV incomplete' => [['type' => 'SRV', 'name' => '_sip._tcp', 'content' => '5060 sip.muster.de', 'prio' => '10'], 'content'],
            'SRV port too high' => [['type' => 'SRV', 'name' => '_sip._tcp', 'content' => '5 70000 sip.muster.de', 'prio' => '10'], 'content'],
            'CAA unknown tag' => [['type' => 'CAA', 'name' => '@', 'content' => '0 issuer "letsencrypt.org"'], 'content'],
            'CAA wrong flag' => [['type' => 'CAA', 'name' => '@', 'content' => '1 issue "letsencrypt.org"'], 'content'],
            'CAA iodef not a URL' => [['type' => 'CAA', 'name' => '@', 'content' => '0 iodef "javascript:alert(1)"'], 'content'],
            'name with spaces' => [['type' => 'A', 'name' => 'a b', 'content' => '203.0.113.1'], 'name'],
            'wildcard in the middle' => [['type' => 'A', 'name' => 'a.*.b', 'content' => '203.0.113.1'], 'name'],
            'label too long' => [['type' => 'A', 'name' => str_repeat('a', 64), 'content' => '203.0.113.1'], 'name'],
            'duplicate' => [['type' => 'A', 'name' => 'www', 'content' => '203.0.113.10'], 'content'],
        ];
        foreach ($invalid as $case => [$input, $field]) {
            try {
                $this->dns()->create($service, $input, null, null);
                self::fail('Accepted: ' . $case);
            } catch (ValidationException $exception) {
                self::assertArrayHasKey($field, $exception->errors(), $case);
            }
        }
        self::assertSame([], $this->skrimeDns->updates, 'Nothing was sent.');

        // Valid records of every type.
        $valid = [
            ['type' => 'AAAA', 'name' => 'v6', 'content' => '2001:0db8:0000::0010'],
            ['type' => 'TXT', 'name' => '_dmarc', 'content' => '"v=DMARC1; p=reject"'],
            ['type' => 'SRV', 'name' => '_sip._tcp', 'content' => '5 5060 sip.muster.de.', 'prio' => '10'],
            ['type' => 'CAA', 'name' => '@', 'content' => '0 issue "letsencrypt.org"'],
            ['type' => 'CAA', 'name' => '@', 'content' => '128 iodef "mailto:security@muster.de"'],
            ['type' => 'A', 'name' => '*', 'content' => '203.0.113.20'],
            ['type' => 'CNAME', 'name' => 'mail', 'content' => 'ghs.Example.NET.'],
        ];
        foreach ($valid as $input) {
            $this->dns()->create($service, $input, null, null);
        }
        $added = array_slice($this->skrimeDns->zones['muster.de'], 3);
        self::assertSame([
            ['name' => 'v6', 'type' => 'AAAA', 'data' => '2001:db8::10'],
            ['name' => '_dmarc', 'type' => 'TXT', 'data' => 'v=DMARC1; p=reject'],
            ['name' => '_sip._tcp', 'type' => 'SRV', 'data' => '10 5 5060 sip.muster.de'],
            ['name' => '@', 'type' => 'CAA', 'data' => '0 issue "letsencrypt.org"'],
            ['name' => '@', 'type' => 'CAA', 'data' => '128 iodef "mailto:security@muster.de"'],
            ['name' => '*', 'type' => 'A', 'data' => '203.0.113.20'],
            ['name' => 'mail', 'type' => 'CNAME', 'data' => 'ghs.example.net'],
        ], $added, 'Normalized values.');

        // Records the panel does not edit and unknown IDs are refused.
        $nsId = $this->ids($service)['NS @'];
        foreach ([$nsId => 'cannot be changed', 'abcdef0123456789abcdef01' => 'does not exist'] as $id => $message) {
            try {
                $this->dns()->delete($service, (string) $id, null, null);
                self::fail('Deleted ' . $id);
            } catch (ServiceStateException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function testZonesThatAreNotHostedAndForeignDomainsAreRefused(): void
    {
        $service = $this->skrimeDomain('ohne-zone.de');
        $zone = $this->dns()->zone($service);
        self::assertFalse($zone['hosted']);
        try {
            $this->dns()->create($service, ['type' => 'A', 'name' => 'x', 'content' => '203.0.113.1'], null, null);
            self::fail('Record created without a zone.');
        } catch (ServiceStateException $exception) {
            self::assertStringContainsString('not hosted', $exception->getMessage());
        }

        // Switched off on the integration: not available.
        self::assertFalse($this->dns()->available($this->skrimeDomain('aus.de', false)));

        // The domain is no longer this service's (other product ID at SKRIME).
        $taken = $this->skrimeDomain('uebernommen.de');
        $this->skrimeDns->zones['uebernommen.de'] = [['name' => '@', 'type' => 'A', 'data' => '203.0.113.1']];
        $this->skrime->domains['uebernommen.de']['id'] = '00000000-0000-4000-8000-999999999999';
        try {
            $this->dns()->zone($taken);
            self::fail('Zone of a domain that is not owned.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('no longer', $exception->getMessage());
        }
        self::assertSame([], $this->skrimeDns->updates);

        // Inactive services have no DNS management.
        $this->db->update('nx_services', ['status' => 'suspended'], ['id' => $service->id]);
        self::assertFalse($this->dns()->available($this->reload($service->id)));
    }

    public function testInwxRecordsUseTheNameServerApi(): void
    {
        $service = $this->inwxDomain();
        $this->inwx->zones['beispiel.de'] = [
            ['id' => 101, 'name' => 'beispiel.de', 'type' => 'SOA', 'content' => 'ns.inwx.de hostmaster.inwx.de 2026112801 10800 3600 604800 3600', 'ttl' => 86400, 'prio' => 0],
            ['id' => 102, 'name' => 'beispiel.de', 'type' => 'A', 'content' => '203.0.113.10', 'ttl' => 3600, 'prio' => 0],
            ['id' => 103, 'name' => 'www.beispiel.de', 'type' => 'CNAME', 'content' => 'beispiel.de', 'ttl' => 3600, 'prio' => 0],
            ['id' => 104, 'name' => 'beispiel.de', 'type' => 'MX', 'content' => 'mail.beispiel.de', 'ttl' => 3600, 'prio' => 10],
        ];
        // A zone of another domain in the same INWX account.
        $this->inwx->zones['fremd.de'] = [['id' => 900, 'name' => 'fremd.de', 'type' => 'A', 'content' => '198.51.100.1', 'ttl' => 3600, 'prio' => 0]];

        $zone = $this->dns()->zone($service);
        self::assertTrue($zone['ttl']);
        self::assertSame(['@', 'www', '@'], array_map(static fn (array $r): string => $r['name'], $this->inwxRecordsInOriginalOrder($zone['records'])), 'Fully qualified names are shown relative to the domain.');
        $mx = array_column($zone['records'], null, 'type')['MX'];
        self::assertSame(['id' => '104', 'name' => '@', 'type' => 'MX', 'content' => 'mail.beispiel.de', 'ttl' => 3600, 'prio' => 10, 'editable' => true], $mx);

        $this->dns()->create($service, ['type' => 'TXT', 'name' => '@', 'content' => 'google-site-verification=abc', 'ttl' => '600'], null, null);
        self::assertSame([['domain' => 'beispiel.de', 'type' => 'TXT', 'content' => 'google-site-verification=abc', 'name' => '', 'ttl' => 600]], $this->inwx->callsTo('nameserver.createRecord'));
        $this->dns()->create($service, ['type' => 'MX', 'name' => '@', 'content' => 'backup.beispiel.de', 'prio' => '20'], null, null);
        self::assertSame(['domain' => 'beispiel.de', 'type' => 'MX', 'content' => 'backup.beispiel.de', 'name' => '', 'ttl' => 3600, 'prio' => 20], $this->inwx->callsTo('nameserver.createRecord')[1], 'Default TTL 3600.');

        $this->dns()->update($service, '103', ['type' => 'CNAME', 'name' => 'www', 'content' => 'cdn.example.net', 'ttl' => '300'], null, null);
        self::assertSame([['id' => 103, 'type' => 'CNAME', 'content' => 'cdn.example.net', 'name' => 'www', 'ttl' => 300]], $this->inwx->callsTo('nameserver.updateRecord'));

        foreach (['299', '86401', 'abc'] as $ttl) {
            try {
                $this->dns()->create($service, ['type' => 'A', 'name' => 'ttl', 'content' => '203.0.113.9', 'ttl' => $ttl], null, null);
                self::fail('TTL accepted: ' . $ttl);
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('ttl', $exception->errors());
            }
        }

        // IDs of another domain's zone or the SOA are never used.
        foreach (['900' => 'does not exist', '101' => 'cannot be changed'] as $id => $message) {
            try {
                $this->dns()->delete($service, (string) $id, null, null);
                self::fail('Deleted record ' . $id);
            } catch (ServiceStateException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
        self::assertSame([], $this->inwx->callsTo('nameserver.deleteRecord'));
        self::assertCount(1, $this->inwx->zones['fremd.de']);

        $this->dns()->delete($service, '102', null, null);
        self::assertSame([['id' => 102]], $this->inwx->callsTo('nameserver.deleteRecord'));

        // A domain without a zone at INWX (code 2303).
        unset($this->inwx->zones['beispiel.de']);
        self::assertFalse($this->dns()->zone($service)['hosted']);
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private function inwxRecordsInOriginalOrder(array $records): array
    {
        usort($records, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return array_values(array_filter($records, static fn (array $r): bool => $r['type'] !== 'SOA'));
    }
}
