<?php

declare(strict_types=1);

namespace Nebrix\Domain\Dns;

use Nebrix\Audit\AuditLogger;
use Nebrix\Catalog\ProductCategory;
use Nebrix\Database\Connection;
use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\IntegrationManager;
use Nebrix\Integration\IntegrationType;
use Nebrix\Provisioning\DomainProvisioner;
use Nebrix\Provisioning\ProvisionerRegistry;
use Nebrix\Provisioning\ServiceStateException;
use Nebrix\Security\ValidationException;
use Nebrix\Service\Service;

/**
 * DNS records of customers' domains whose zone is hosted at the registrar
 * (SKRIME, INWX), when the integration enables DNS management.
 *
 * Every read and change first checks that the domain still belongs to the
 * service (same ownership check as the other domain actions). Record IDs are
 * only accepted when they belong to the current zone of this domain (no
 * access to other domains of the registrar account). Changes are applied
 * directly at the registrar under a per-domain lock (SKRIME replaces the
 * whole zone, so concurrent changes must not overwrite each other) and are
 * audit-logged. NS and SOA records and types the panel does not edit are
 * shown read-only and passed on unchanged.
 */
class DnsZoneService
{
    public const MAX_RECORDS = 100;

    public function __construct(
        private readonly ProvisionerRegistry $provisioners,
        private readonly IntegrationManager $integrations,
        private readonly Connection $db,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Whether DNS management is offered for the service (no remote call). */
    public function available(Service $service): bool
    {
        if ($service->category !== ProductCategory::DOMAIN || !$service->isActive() || !$this->provisioners->supportsService($service)) {
            return false;
        }
        try {
            $integration = $this->integrations->get((int) $service->integrationId);
        } catch (IntegrationException) {
            return false;
        }

        return in_array($integration->type, [IntegrationType::SKRIME, IntegrationType::INWX], true)
            && $integration->enabled
            && (bool) $integration->config('dns_management', false);
    }

    /**
     * The zone with records marked as editable; "hosted" is false when the
     * registrar has no zone for the domain.
     *
     * @return array{domain: string, hosted: bool, ttl: bool, records: list<array{id: string, name: string, type: string, content: string, ttl: int|null, prio: int|null, editable: bool}>}
     *
     * @throws IntegrationException|ServiceStateException
     */
    public function zone(Service $service): array
    {
        [$domain, $hosting] = $this->hosting($service);
        $records = $hosting->dnsRecords($domain);
        if ($records === null) {
            return ['domain' => $domain, 'hosted' => false, 'ttl' => $hosting->supportsDnsTtl(), 'records' => []];
        }
        $marked = [];
        foreach ($records as $record) {
            $marked[] = $record + ['editable' => in_array($record['type'], DnsRecordValidator::TYPES, true)];
        }
        usort($marked, static fn (array $a, array $b): int => [$a['name'] === '@' ? '' : $a['name'], $a['type'], $a['content']] <=> [$b['name'] === '@' ? '' : $b['name'], $b['type'], $b['content']]);

        return ['domain' => $domain, 'hosted' => true, 'ttl' => $hosting->supportsDnsTtl(), 'records' => $marked];
    }

    /**
     * @param array<string, mixed> $input type, name, content, prio, ttl
     *
     * @throws ValidationException|ServiceStateException|IntegrationException
     */
    public function create(Service $service, array $input, ?int $actorId, ?string $ip): void
    {
        $this->locked($service, function (string $domain, DnsHosting $hosting, array $records) use ($service, $input, $actorId, $ip): void {
            if (count($records) >= self::MAX_RECORDS) {
                throw new ServiceStateException('The zone has reached the maximum number of records.');
            }
            $record = DnsRecordValidator::validate($input, $domain, $records, null, $hosting->supportsDnsTtl());
            $hosting->createDnsRecord($domain, $record);
            $this->audit->log($actorId, 'domain.dns_record_created', 'service', $service->id, self::auditDetails($domain, $record), $ip);
        });
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationException|ServiceStateException|IntegrationException
     */
    public function update(Service $service, string $recordId, array $input, ?int $actorId, ?string $ip): void
    {
        $this->locked($service, function (string $domain, DnsHosting $hosting, array $records) use ($service, $recordId, $input, $actorId, $ip): void {
            $current = self::editable($records, $recordId);
            $record = DnsRecordValidator::validate($input, $domain, $records, $current['id'], $hosting->supportsDnsTtl());
            $hosting->updateDnsRecord($domain, $current['id'], $record);
            $this->audit->log($actorId, 'domain.dns_record_updated', 'service', $service->id, self::auditDetails($domain, $record) + ['before' => mb_substr($current['type'] . ' ' . $current['name'] . ' ' . $current['content'], 0, 200)], $ip);
        });
    }

    /**
     * @throws ServiceStateException|IntegrationException
     */
    public function delete(Service $service, string $recordId, ?int $actorId, ?string $ip): void
    {
        $this->locked($service, function (string $domain, DnsHosting $hosting, array $records) use ($service, $recordId, $actorId, $ip): void {
            $current = self::editable($records, $recordId);
            $hosting->deleteDnsRecord($domain, $current['id']);
            $this->audit->log($actorId, 'domain.dns_record_deleted', 'service', $service->id, self::auditDetails($domain, $current), $ip);
        });
    }

    /**
     * Runs a change with the domain locked and its current records.
     *
     * @param callable(string, DnsHosting, list<array{id: string, name: string, type: string, content: string, ttl: int|null, prio: int|null}>): void $change
     *
     * @throws ValidationException|ServiceStateException|IntegrationException
     */
    private function locked(Service $service, callable $change): void
    {
        [$domain, $hosting] = $this->hosting($service);
        // Lock names are limited to 64 characters: the domain is hashed.
        $this->db->withLock('nebrix-dns-' . substr(hash('sha256', $domain), 0, 40), 10, function () use ($domain, $hosting, $change): void {
            $records = $hosting->dnsRecords($domain) ?? throw new ServiceStateException('The DNS zone of this domain is not hosted with us. Manage the records where the name servers of the domain point to.');
            $change($domain, $hosting, $records);
        });
    }

    /**
     * @return array{0: string, 1: DnsHosting}
     *
     * @throws IntegrationException|ServiceStateException
     */
    private function hosting(Service $service): array
    {
        if (!$this->available($service)) {
            throw new ServiceStateException('DNS management is not available for this domain.');
        }
        $provisioner = $this->provisioners->for($service->category);
        if (!$provisioner instanceof DomainProvisioner) {
            throw new ServiceStateException('DNS management is not available for this domain.');
        }
        [$domain, $registrar] = $provisioner->verifiedDomain($service);
        if (!$registrar instanceof DnsHosting) {
            throw new ServiceStateException('DNS management is not available for this domain.');
        }

        return [$domain, $registrar];
    }

    /**
     * A record of the current zone that customers may change.
     *
     * @param list<array{id: string, name: string, type: string, content: string, ttl: int|null, prio: int|null}> $records
     * @return array{id: string, name: string, type: string, content: string, ttl: int|null, prio: int|null}
     *
     * @throws ServiceStateException
     */
    private static function editable(array $records, string $recordId): array
    {
        foreach ($records as $record) {
            if (hash_equals($record['id'], $recordId)) {
                if (!in_array($record['type'], DnsRecordValidator::TYPES, true)) {
                    throw new ServiceStateException('This record cannot be changed here.');
                }

                return $record;
            }
        }

        throw new ServiceStateException('The DNS record does not exist (anymore).');
    }

    /**
     * @param array{name: string, type: string, content: string, ttl: int|null, prio: int|null} $record
     * @return array<string, mixed>
     */
    private static function auditDetails(string $domain, array $record): array
    {
        return ['domain' => $domain, 'type' => $record['type'], 'name' => $record['name'], 'content' => mb_substr($record['content'], 0, 200), 'prio' => $record['prio'], 'ttl' => $record['ttl']];
    }
}
