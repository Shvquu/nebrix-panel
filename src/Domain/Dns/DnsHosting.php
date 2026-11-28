<?php

declare(strict_types=1);

namespace Nebrix\Domain\Dns;

use Nebrix\Integration\IntegrationException;

/**
 * DNS zones hosted at a registrar (implemented by SKRIME and INWX).
 *
 * Record names are relative to the domain ("@" = the domain itself, "www",
 * "_sip._tcp"). MX and SRV priorities are separate ("prio"); SRV content is
 * "weight port target", CAA content 'flags tag "value"'. Records are
 * identified by an ID that is only valid for the zone it was read from.
 */
interface DnsHosting
{
    /**
     * Records of the domain's zone, or null when the zone is not hosted at the registrar.
     *
     * @return list<array{id: string, name: string, type: string, content: string, ttl: int|null, prio: int|null}>|null
     *
     * @throws IntegrationException
     */
    public function dnsRecords(string $domain): ?array;

    /** Whether records carry their own TTL (otherwise the registrar's default applies). */
    public function supportsDnsTtl(): bool;

    /**
     * @param array{name: string, type: string, content: string, ttl: int|null, prio: int|null} $record
     *
     * @throws IntegrationException
     */
    public function createDnsRecord(string $domain, array $record): void;

    /**
     * @param array{name: string, type: string, content: string, ttl: int|null, prio: int|null} $record
     *
     * @throws IntegrationException
     */
    public function updateDnsRecord(string $domain, string $id, array $record): void;

    /** @throws IntegrationException */
    public function deleteDnsRecord(string $domain, string $id): void;
}
