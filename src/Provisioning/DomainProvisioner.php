<?php

declare(strict_types=1);

namespace Nebrix\Provisioning;

use InvalidArgumentException;
use Nebrix\Auth\User;
use Nebrix\Billing\OrderException;
use Nebrix\Core\Clock;
use Nebrix\Billing\Tax\EuVat;
use Nebrix\Database\Connection;
use Nebrix\Domain\OrderRejectedException;
use Nebrix\Domain\PremiumDomainException;
use Nebrix\Domain\Registrar;
use Nebrix\Domain\RegistrarContacts;
use Nebrix\Integration\Integration;
use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\IntegrationManager;
use Nebrix\Security\Encrypter;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;

/**
 * Domain registrations and transfers through a registrar (INWX or SKRIME).
 *
 * - Before the order: TLD, profile completeness (registrant data) and
 *   availability are checked; registered domains need an auth code.
 * - A domain can be held by one live service only (checked before the order
 *   and again before provisioning).
 * - Registration/transfer uses a registrant contact created from the
 *   customer profile. The submission is recorded only after the registrar
 *   accepted it; the service stays in provisioning until the registry
 *   reports the domain as active (transfers: up to 10 days). Every later
 *   action checks that the domain is still in the account with this
 *   service's registrant contact (INWX) or the registrar's ID returned by
 *   this service's order (SKRIME).
 * - "submitting" is set right before the order request. A definitive
 *   refusal clears it; an unknown outcome (lost answer) never leads to a
 *   second order – the service waits for the domain and then fails for
 *   staff to check.
 * - The transfer auth code is stored encrypted and removed after submission.
 * - Suspension stops automatic renewal at the registry, unsuspension enables
 *   it again; termination lets the domain be deleted at the end of its term
 *   (never an immediate deletion).
 */
final class DomainProvisioner implements Provisioner, OrderPrecheck
{
    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly ServiceRepository $services,
        private readonly RegistrarContacts $contacts,
        private readonly Encrypter $encrypter,
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    public function checkOrder(Integration $integration, array $config, User $user, array $options): void
    {
        $domain = mb_strtolower((string) ($options['domain'] ?? ''));
        if (!$this->matchesTld($domain, (string) ($config['tld'] ?? ''))) {
            throw new OrderException(sprintf('This product is only available for %s domains.', '.' . ltrim((string) ($config['tld'] ?? ''), '.')));
        }
        if ($this->heldByOtherService($domain, 0)) {
            throw new OrderException(sprintf('%s is already managed in this panel.', $domain));
        }
        try {
            $contact = RegistrarContacts::contactData($user);
        } catch (InvalidArgumentException $exception) {
            throw new OrderException($exception->getMessage() . ' (Account → Profile)');
        }
        // Registry rule: .eu domains need a registrant in the EU or the EEA.
        if (str_ends_with($domain, '.eu') && !EuVat::isMember($contact['country']) && !in_array($contact['country'], ['IS', 'LI', 'NO'], true)) {
            throw new OrderException('.eu domains can only be registered for customers in the EU or the EEA (Account → Profile).');
        }

        $registrar = $this->integrations->registrar($integration);
        // Sandbox orders never complete: only staff can test them through the shop.
        if ($registrar->isSandbox() && !$user->isStaff()) {
            throw new OrderException('Domain orders are not available right now. Please contact our support.');
        }
        try {
            $blocker = $registrar->orderBlocker($domain, RegistrarContacts::registrantData($user), $this->defaultNameservers($integration));
        } catch (InvalidArgumentException $exception) {
            throw new OrderException($exception->getMessage() . ' (Account → Profile)');
        }
        if ($blocker !== null) {
            throw new OrderException($blocker);
        }

        try {
            $available = $registrar->isAvailable($domain);
        } catch (PremiumDomainException) {
            throw new OrderException('This is a premium domain with a special price and cannot be ordered here. Please contact our support.');
        } catch (IntegrationException) {
            throw new OrderException('The availability of the domain could not be checked. Please try again later.');
        }
        $transfer = isset($options['auth_code']) && $options['auth_code'] !== '' && $options['auth_code'] !== [];
        if (!$available && !$transfer) {
            throw new OrderException(sprintf('%s is already registered. If it is yours, enter its auth code to transfer it.', $domain));
        }
        if ($available && $transfer) {
            throw new OrderException(sprintf('%s is not registered yet and cannot be transferred. Order it without an auth code.', $domain));
        }
    }

    public function provision(Service $service, User $owner): ProvisionResult
    {
        [$integration, $registrar] = $this->client($service);
        $domain = $this->domain($service);
        if ($this->heldByOtherService($domain, $service->id)) {
            throw new IntegrationException(sprintf('%s is already held by another service.', $domain));
        }
        $remote = $service->remote;
        if (isset($remote['registry_env']) && $remote['registry_env'] !== $registrar->environment()) {
            throw new IntegrationException(sprintf('This domain was ordered in the %s environment of the registrar; the integration now uses %s. Assign it manually.', (string) $remote['registry_env'], $registrar->environment()));
        }

        $info = $registrar->info($domain);
        if ($info !== null) {
            if (!$this->owns($remote, $info) && !$this->recogniseLostOrder($service, $registrar, $owner, $remote, $info)) {
                throw new IntegrationException(sprintf('%s is already in the registrar account but was not registered by this service. Assign it manually.', $domain));
            }
            $remote = $this->services->find($service->id)->remote ?? $remote;
            if (isset($remote['submitting'])) {
                $remote = $this->merge($service, ['submitted' => $remote['submitting'], 'submitting' => null, 'submitted_at' => $remote['submitted_at'] ?? $this->clock->now()->getTimestamp()]);
            }

            return $this->completedOrWait($service, $registrar, $remote, $info);
        }
        if (isset($remote['submitted'])) {
            // Accepted earlier; a transfer waits for the losing registrar.
            return $this->completedOrWait($service, $registrar, $remote, null);
        }
        if (isset($remote['submitting'])) {
            // The order was sent but its answer is unknown: never order a second time.
            $transfer = $remote['submitting'] === 'transfer';
            $since = (int) ($remote['submitting_at'] ?? 0);
            if ($this->clock->now()->getTimestamp() > $since + ($transfer ? 10 * 86400 : 2 * 86400)) {
                throw new IntegrationException(sprintf('The registrar did not confirm the %s of %s and the domain did not appear in the account. Check the order at the registrar before retrying.', $transfer ? 'transfer' : 'registration', $domain));
            }

            throw new RetryLaterException(sprintf('Waiting for the %s of %s to appear at the registrar (the answer to the order was lost).', $transfer ? 'transfer' : 'registration', $domain), $transfer ? 3600 : 300);
        }

        try {
            $contactId = $this->contacts->ensure($owner, $integration->id, $registrar);
            $registrant = RegistrarContacts::registrantData($owner);
        } catch (InvalidArgumentException $exception) {
            throw new IntegrationException($exception->getMessage());
        }
        $nameservers = $this->defaultNameservers($integration);
        $blocker = $registrar->orderBlocker($domain, $registrant, $nameservers);
        if ($blocker !== null) {
            throw new IntegrationException('The registrar cannot take this order: ' . $blocker);
        }
        $authCode = $this->authCode($service);
        $mode = $authCode !== null ? 'transfer' : 'register';

        // Set right before the request: a retry after a lost answer must not order again.
        $this->merge($service, ['domain' => $domain, 'contact_id' => $contactId, 'submitting' => $mode, 'submitting_at' => $this->clock->now()->getTimestamp(), 'registry_env' => $registrar->environment(), 'registry_ref' => null]);
        try {
            $reference = $authCode !== null
                ? $registrar->transfer($domain, $authCode, $contactId, $nameservers, $registrant)
                : $registrar->register($domain, $contactId, $nameservers, 1, $registrant);
        } catch (OrderRejectedException $exception) {
            // Refused: nothing was ordered, a later retry may order again.
            $this->merge($service, ['submitting' => null, 'submitting_at' => null]);
            throw $exception;
        }
        // Only an accepted request counts as submitted.
        $remote = $this->merge($service, ['submitted' => $mode, 'submitting' => null, 'submitted_at' => $this->clock->now()->getTimestamp(), 'registry_ref' => $reference]);
        if ($authCode !== null) {
            $this->forgetAuthCode($service);
        }

        $info = $registrar->info($domain);
        if ($info !== null && !$this->owns($remote, $info)) {
            throw new IntegrationException(sprintf('%s appeared in the registrar account with another registrant.', $domain));
        }

        return $this->completedOrWait($service, $registrar, $remote, $info);
    }

    public function suspend(Service $service): void
    {
        [, $registrar] = $this->client($service);
        $this->ownedInfo($service, $registrar);
        $registrar->setRenewalMode($this->domain($service), Registrar::EXPIRE);
    }

    public function unsuspend(Service $service): void
    {
        [, $registrar] = $this->client($service);
        $this->ownedInfo($service, $registrar);
        if (!$registrar->renewsExplicitly()) {
            $registrar->setRenewalMode($this->domain($service), Registrar::RENEW);
        }
        // Registrars without own renewal: the renewal task renews paid periods again.
    }

    public function terminate(Service $service): void
    {
        if (($service->remote['submitted'] ?? null) === null) {
            return; // never accepted by the registrar
        }
        [, $registrar] = $this->client($service);
        $info = $registrar->info($this->domain($service));
        if ($info === null) {
            return; // not (or no longer) in the account
        }
        if (!$this->owns($service->remote, $info)) {
            throw new IntegrationException('The domain in the registrar account does not belong to this service; nothing was changed.');
        }
        $registrar->setRenewalMode($this->domain($service), Registrar::DELETE);
    }

    /**
     * @param array<string, mixed> $remote
     * @param array{status: string, expires: string|null, nameservers: list<string>, auth_code: string|null, registrant: string|null, reference?: string|null, created?: string|null}|null $info
     */
    private function completedOrWait(Service $service, Registrar $registrar, array $remote, ?array $info): ProvisionResult
    {
        $domain = $this->domain($service);
        if ($info !== null && strtoupper($info['status']) === 'OK') {
            // Renewal at the registry follows the panel's billing from now on (suspension
            // stops it, termination lets the domain end with its term). Registrars without
            // own renewal keep it off; the panel renews each paid period (DomainRenewals).
            if (($remote['auto_renew'] ?? null) !== true) {
                $registrar->setRenewalMode($domain, $registrar->renewsExplicitly() ? Registrar::EXPIRE : Registrar::RENEW);
                $this->merge($service, ['auto_renew' => true]);
            }

            return $this->result($service, $domain);
        }

        $transfer = ($remote['submitted'] ?? null) === 'transfer';
        $deadline = (int) ($remote['submitted_at'] ?? 0) + ($transfer ? 10 * 86400 : 2 * 86400);
        if ($this->clock->now()->getTimestamp() > $deadline) {
            throw new IntegrationException(sprintf('The %s of %s was not completed in time (status: %s). Check it at the registrar.', $transfer ? 'transfer' : 'registration', $domain, $info['status'] ?? 'not in the account'));
        }

        throw new RetryLaterException(sprintf('Waiting for the registry to complete the %s of %s.', $transfer ? 'transfer' : 'registration', $domain), $transfer ? 3600 : 300);
    }

    /**
     * The domain belongs to the service when the service submitted it and the
     * registrar reports the service's registrant contact (INWX) or the ID its
     * order returned (SKRIME).
     *
     * @param array<string, mixed> $remote
     * @param array{registrant: string|null, reference?: string|null} $info
     */
    private function owns(array $remote, array $info): bool
    {
        if (($remote['submitted'] ?? $remote['submitting'] ?? null) === null) {
            return false;
        }
        if (($info['reference'] ?? null) !== null) {
            $reference = (string) ($remote['registry_ref'] ?? '');

            return $reference !== '' && hash_equals($reference, (string) $info['reference']);
        }

        return $info['registrant'] === null || $info['registrant'] === (string) ($remote['contact_id'] ?? '');
    }

    /**
     * The order was sent, but its answer (with the registrar's ID) was lost.
     * The domain is accepted as this service's only if it was created at the
     * registrar right after this service submitted it and its registrant is
     * this customer; the ID is then stored.
     *
     * @param array<string, mixed> $remote
     * @param array{reference?: string|null, created?: string|null} $info
     */
    private function recogniseLostOrder(Service $service, Registrar $registrar, User $owner, array $remote, array $info): bool
    {
        $since = (int) ($remote['submitting_at'] ?? 0);
        $created = isset($info['created']) ? strtotime((string) $info['created'] . ' UTC') : false;
        if (!isset($remote['submitting']) || ($remote['registry_ref'] ?? null) !== null || ($info['reference'] ?? null) === null || $since === 0 || $created === false) {
            return false;
        }
        if ($created < $since - 600 || $created > $since + 86400) {
            return false;
        }
        try {
            if (!$registrar->registrantMatches($this->domain($service), RegistrarContacts::registrantData($owner))) {
                return false;
            }
        } catch (InvalidArgumentException) {
            return false;
        }
        $this->merge($service, ['registry_ref' => (string) $info['reference']]);

        return true;
    }

    /**
     * @return array{status: string, expires: string|null, nameservers: list<string>, auth_code: string|null, registrant: string|null, reference?: string|null, created?: string|null}
     */
    private function ownedInfo(Service $service, Registrar $registrar): array
    {
        $info = $registrar->info($this->domain($service));
        if ($info === null || !$this->owns($service->remote, $info)) {
            throw new IntegrationException('The domain is not (or no longer) managed by this service.');
        }

        return $info;
    }

    private function heldByOtherService(string $domain, int $exceptServiceId): bool
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM `nx_services` WHERE `category` = 'domain' AND `id` <> ? AND `status` NOT IN ('terminated', 'cancelled', 'failed')
             AND (JSON_UNQUOTE(JSON_EXTRACT(`config`, '$._options.domain')) = ? OR JSON_UNQUOTE(JSON_EXTRACT(`remote`, '$.domain')) = ?)",
            [$exceptServiceId, $domain, $domain],
        ) > 0;
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function merge(Service $service, array $changes): array
    {
        $this->services->mergeRemote($service->id, $changes);

        return $this->services->find($service->id)->remote ?? [];
    }

    public function status(Service $service): array
    {
        [, $registrar] = $this->client($service);
        $domain = $this->domain($service);
        $info = $registrar->info($domain);
        if ($info === null) {
            return ['state' => ($service->remote['submitted'] ?? null) === 'transfer' ? 'transfer pending' : 'pending', 'connection' => ['Domain' => $domain]];
        }
        if (!$this->owns($service->remote, $info)) {
            throw new IntegrationException('The domain is not (or no longer) managed by this service.');
        }

        return [
            'state' => strtoupper($info['status']) === 'OK' ? 'active' : mb_strtolower($info['status']),
            'connection' => array_filter([
                'Domain' => $domain,
                'Paid until (registry)' => (string) ($info['expires'] ?? ''),
                'Name servers' => implode(', ', $info['nameservers']),
            ]),
        ];
    }

    public function actions(Service $service): array
    {
        // Host names are case-insensitive; they are lower-cased before use.
        $hostname = 'required|string|max:253|regex:/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i';
        $optional = 'nullable|string|max:253|regex:/^((?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63})?$/i';

        return [
            'nameservers' => new ServiceAction('Change name servers', [
                'ns1' => ['label' => 'Name server 1', 'rules' => $hostname],
                'ns2' => ['label' => 'Name server 2', 'rules' => $hostname],
                'ns3' => ['label' => 'Name server 3 (optional)', 'rules' => $optional],
                'ns4' => ['label' => 'Name server 4 (optional)', 'rules' => $optional],
            ], 'Change the name servers? Wrong name servers make the domain unreachable.'),
            'auth_code' => ServiceAction::simple('Show auth code (transfer away)', 'Request the auth code? With it the domain can be transferred to another provider.'),
        ];
    }

    public function perform(Service $service, string $action, array $input = []): string
    {
        [, $registrar] = $this->client($service);
        $domain = $this->domain($service);
        $this->ownedInfo($service, $registrar); // the domain must still belong to this service

        if ($action === 'nameservers') {
            $nameservers = array_values(array_filter(array_map(static fn (mixed $v): string => mb_strtolower(trim((string) $v)), [$input['ns1'] ?? '', $input['ns2'] ?? '', $input['ns3'] ?? '', $input['ns4'] ?? '']), static fn (string $v): bool => $v !== ''));
            $registrar->setNameservers($domain, $nameservers);

            return 'The name servers have been updated. Changes can take up to 24 hours to propagate.';
        }
        if ($action === 'auth_code') {
            $code = $registrar->authCode($domain);
            if ($code === null) {
                throw new IntegrationException('The registry did not provide an auth code for this domain.');
            }
            $this->services->setCredentials($service->id, ['auth_code' => $code] + $this->services->credentials($service));

            return 'The auth code is shown under access data.';
        }

        throw new IntegrationException('Unsupported action.');
    }

    /**
     * Registrars without own renewal (SKRIME): renews the domain while its
     * registry term ends before the period the customer has paid for
     * (next_due_at). At most one renewal request per expiry date – a lost
     * answer never leads to a second, paid renewal.
     *
     * @return bool whether a renewal was requested
     *
     * @throws IntegrationException
     */
    public function renewPaidPeriod(Service $service): bool
    {
        [, $registrar] = $this->client($service);
        if (!$registrar->renewsExplicitly() || $registrar->isSandbox() || !$service->isActive() || $service->nextDueAt === null) {
            return false;
        }
        $info = $this->ownedInfo($service, $registrar);
        $expires = $info['expires'] !== null ? strtotime($info['expires'] . ' UTC') : false;
        $paidUntil = strtotime($service->nextDueAt . ' UTC');
        if (strtoupper($info['status']) !== 'OK' || $expires === false || $paidUntil === false || $expires >= $paidUntil) {
            return false;
        }
        $remote = $service->remote;
        if (($remote['renewed_from'] ?? null) === $info['expires']) {
            return false; // already requested from this expiry date
        }
        $now = $this->clock->now()->getTimestamp();
        if (isset($remote['renewal_error_at']) && $now - (int) $remote['renewal_error_at'] < 21600) {
            return false; // refused recently (e.g. balance): retried every 6 hours
        }

        try {
            $newExpiry = $registrar->renew($this->domain($service));
        } catch (IntegrationException $exception) {
            $status = $exception->httpStatus();
            $refused = $exception instanceof OrderRejectedException || ($status !== null && ($status < 300 || ($status >= 400 && $status < 500 && $status !== 408)));
            $this->merge($service, $refused
                ? ['renewal_error_at' => $now, 'renewal_error' => mb_substr($exception->getMessage(), 0, 200)]
                : ['renewed_from' => $info['expires'], 'renewal_unconfirmed_at' => $now, 'renewal_error' => mb_substr($exception->getMessage(), 0, 200)]);
            throw $exception;
        }
        $this->merge($service, ['renewed_from' => $info['expires'], 'renewed_at' => $now, 'renewed_until' => $newExpiry, 'renewal_error' => null, 'renewal_error_at' => null, 'renewal_unconfirmed_at' => null]);

        return true;
    }

    public function prepareReinstall(Service $service, array $input): void
    {
        throw new ServiceStateException('Domains cannot be reinstalled.');
    }

    private function result(Service $service, string $domain): ProvisionResult
    {
        return new ProvisionResult('domain:' . $domain, ['domain' => $domain], $this->services->credentials($service));
    }

    private function domain(Service $service): string
    {
        $domain = mb_strtolower((string) $service->option('domain', ''));
        if (!$this->matchesTld($domain, (string) $service->config('tld', ''))) {
            throw new IntegrationException('The service has no valid domain for the product\'s TLD.');
        }

        return $domain;
    }

    private function matchesTld(string $domain, string $tld): bool
    {
        $tld = ltrim(mb_strtolower(trim($tld)), '.');

        return $tld !== ''
            && preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1
            && str_ends_with($domain, '.' . $tld)
            && !str_contains(substr($domain, 0, -strlen($tld) - 1), '.');
    }

    private function authCode(Service $service): ?string
    {
        $value = $service->option('auth_code');
        if (is_array($value) && is_string($value['encrypted'] ?? null)) {
            return $this->encrypter->decrypt($value['encrypted'], 'order-option:auth_code');
        }

        return null;
    }

    private function forgetAuthCode(Service $service): void
    {
        $config = $service->config;
        if (is_array($config['_options'] ?? null)) {
            unset($config['_options']['auth_code']);
            $this->services->update($service->id, ['config' => $config]);
        }
    }

    /**
     * @return list<string>
     */
    private function defaultNameservers(Integration $integration): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $integration->config('nameservers', '')) ?: [])));
    }

    /**
     * @return array{0: Integration, 1: Registrar}
     */
    private function client(Service $service): array
    {
        if ($service->integrationId === null) {
            throw new IntegrationException('No registrar integration is assigned to this service.');
        }
        $integration = $this->integrations->get($service->integrationId);

        return [$integration, $this->integrations->registrar($integration)];
    }
}
