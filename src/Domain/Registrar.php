<?php

declare(strict_types=1);

namespace Nebrix\Domain;

use Nebrix\Integration\HealthResult;
use Nebrix\Integration\IntegrationException;

/**
 * Domain registrar abstraction (implemented by INWX and SKRIME; other
 * registrars can be added with the same contract).
 */
interface Registrar
{
    public const RENEW = 'renew';
    public const EXPIRE = 'expire';
    public const DELETE = 'delete';

    public function healthCheck(): HealthResult;

    /** @throws IntegrationException */
    public function isAvailable(string $domain): bool;

    /**
     * Domain in the registrar account, or null when it is not in the account.
     *
     * "registrant" is the registrant contact handle (registrars with handles),
     * "reference" the registrar's own ID of the domain (registrars without
     * handles), "created" when that record was created (Y-m-d H:i:s).
     *
     * @return array{status: string, expires: string|null, nameservers: list<string>, auth_code: string|null, registrant: string|null, reference: string|null, created: string|null}|null
     *
     * @throws IntegrationException
     */
    public function info(string $domain): ?array;

    /** Name of the environment (e.g. "live", "ote", "sandbox"); stored with every order. */
    public function environment(): string;

    /** True when orders are only validated and never carried out (no domain appears). */
    public function isSandbox(): bool;

    /**
     * A reason why the domain cannot be ordered with this registrar (shown to
     * the customer), or null.
     *
     * @param array<string, string> $registrant
     * @param list<string> $nameservers
     */
    public function orderBlocker(string $domain, array $registrant, array $nameservers): ?string;

    /**
     * Whether the domain's registrant at the registrar is the given person
     * (used to recognise an order whose answer was lost).
     *
     * @param array<string, string> $registrant
     *
     * @throws IntegrationException
     */
    public function registrantMatches(string $domain, array $registrant): bool;

    /**
     * True when the registrar does not renew by itself and the panel renews
     * each paid period explicitly (renew()).
     */
    public function renewsExplicitly(): bool;

    /**
     * Renews the domain by one period (only for renewsExplicitly()).
     *
     * @return string|null new expiry date when the registrar reports it
     *
     * @throws IntegrationException
     */
    public function renew(string $domain): ?string;

    /**
     * Auth code for a transfer to another provider (fetched only on request).
     *
     * @throws IntegrationException
     */
    public function authCode(string $domain): ?string;

    /**
     * @param array{type: string, name: string, org: string, street: string, city: string, postcode: string, country: string, phone: string, email: string} $contact
     * @return string remote contact handle
     *
     * @throws IntegrationException
     */
    public function createContact(array $contact): string;

    /**
     * @param list<string> $nameservers
     * @param array<string, string> $registrant contact data with first and last name, for registrars
     *                                          that take the registrant inline instead of a handle
     * @return string|null the registrar's ID of the new domain (see info()["reference"]), if it has one
     *
     * @throws OrderRejectedException when the order was refused (nothing ordered)
     * @throws IntegrationException   when the outcome is unknown (e.g. the answer was lost)
     */
    public function register(string $domain, string $contactId, array $nameservers, int $years, array $registrant = []): ?string;

    /**
     * @param list<string> $nameservers
     * @param array<string, string> $registrant see register()
     * @return string|null see register()
     *
     * @throws OrderRejectedException when the order was refused (nothing ordered)
     * @throws IntegrationException   when the outcome is unknown
     */
    public function transfer(string $domain, #[\SensitiveParameter] string $authCode, string $contactId, array $nameservers, array $registrant = []): ?string;

    /**
     * @param list<string> $nameservers
     *
     * @throws IntegrationException
     */
    public function setNameservers(string $domain, array $nameservers): void;

    /**
     * @param string $mode Registrar::RENEW, EXPIRE or DELETE (at the end of the term)
     *
     * @throws IntegrationException
     */
    public function setRenewalMode(string $domain, string $mode): void;
}
