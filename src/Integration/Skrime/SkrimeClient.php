<?php

declare(strict_types=1);

namespace Nebrix\Integration\Skrime;

use Nebrix\Domain\OrderRejectedException;
use Nebrix\Domain\PremiumDomainException;
use Nebrix\Domain\Registrar;
use Nebrix\Integration\HealthResult;
use Nebrix\Integration\Http\ApiClient;
use Nebrix\Integration\IntegrationException;

/**
 * Domains through the SKRIME API (https://skrime.eu/docs/openapi.json).
 *
 * Every response is an envelope {response, state, data}; the API key is sent
 * as bearer token. SKRIME keeps the registrant inline with each domain (no
 * contact handles) and registers for one year. Ownership is tied to the
 * SKRIME product ID returned by the order. SKRIME's own auto renewal stays
 * off: the panel renews each period explicitly once the customer has paid
 * for it (renew()). The sandbox validates like the live API but changes
 * nothing and costs nothing.
 */
final class SkrimeClient implements Registrar
{
    public const ENDPOINTS = [
        'sandbox' => 'https://skrime.eu/sandbox',
        'live' => 'https://skrime.eu/api',
    ];

    public function __construct(
        private readonly ApiClient $api,
        private readonly bool $termsAccepted,
        private readonly bool $sandbox,
    ) {
    }

    public function healthCheck(): HealthResult
    {
        try {
            $user = $this->call('GET', '/account/user', [], 'account.user');
        } catch (IntegrationException $exception) {
            return HealthResult::down($exception->getMessage());
        }
        $balance = is_array($user['balance'] ?? null) ? (string) ($user['balance']['amount'] ?? '?') : '?';
        $message = sprintf('Signed in to SKRIME%s (account %s, balance %s EUR).', $this->sandbox ? ' sandbox' : '', (string) ($user['username'] ?? $user['email'] ?? '?'), $balance);
        if (!$this->termsAccepted) {
            return HealthResult::degraded($message . ' Orders are blocked until the SKRIME terms are accepted in the integration settings.');
        }

        return HealthResult::healthy($message);
    }

    public function isAvailable(string $domain): bool
    {
        $result = $this->call('POST', '/domain/check', ['json' => ['domain' => $this->domain($domain)]], 'domain.check');
        if (!is_bool($result['available'] ?? null)) {
            throw new IntegrationException('SKRIME returned no availability information.');
        }
        // Premium prices apply to registrations, transfers and renewals: never through the panel.
        if (($result['premium'] ?? false) === true) {
            throw new PremiumDomainException(sprintf('%s is a premium domain.', $domain));
        }

        return $result['available'];
    }

    public function info(string $domain): ?array
    {
        $domain = $this->domain($domain);
        try {
            $product = $this->call('GET', '/product/single', ['query' => ['domain' => $domain]], 'product.single');
        } catch (IntegrationException $exception) {
            if ($exception->isNotFound()) {
                return null;
            }
            throw $exception;
        }
        $info = is_array($product['productInfo'] ?? null) ? $product['productInfo'] : [];
        if (($product['type'] ?? '') !== 'domain' || mb_strtolower((string) ($info['domain'] ?? $domain)) !== $domain) {
            return null; // another product, never treated as this domain
        }

        $nameservers = [];
        try {
            $ns = $this->call('GET', '/domain/nameserver', ['query' => ['domain' => $domain]], 'domain.nameserver.get');
            $nameservers = array_values(array_map('strval', is_array($ns['nameserver'] ?? null) ? $ns['nameserver'] : []));
        } catch (IntegrationException) {
            // shown as unknown; the domain itself was found
        }
        $state = (string) ($product['state'] ?? 'unknown');

        return [
            'status' => $state === 'active' ? 'OK' : $state,
            'expires' => isset($product['expireAt']) && $product['expireAt'] !== '' ? (string) $product['expireAt'] : null,
            'nameservers' => $nameservers,
            'auth_code' => null, // only on request: authCode()
            'registrant' => null, // SKRIME has no registrant handles
            'reference' => isset($product['id']) && $product['id'] !== '' ? (string) $product['id'] : null,
            'created' => isset($product['createdAt']) && $product['createdAt'] !== '' ? (string) $product['createdAt'] : null,
        ];
    }

    /**
     * Current domain prices (EUR, updated hourly by SKRIME).
     *
     * @return list<array{tld: string, create: string, renew: string, transfer: string, offer: bool}>
     */
    public function pricelist(): array
    {
        $rows = [];
        foreach ($this->call('GET', '/domain/pricelist', [], 'domain.pricelist') as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tld = mb_strtolower(trim((string) ($row['tld'] ?? '')));
            // Only plain LDH labels (IDN TLDs arrive as punycode); prices must be numbers.
            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $tld) !== 1
                || !is_numeric($row['create'] ?? null) || !is_numeric($row['renew'] ?? null) || !is_numeric($row['transfer'] ?? null)) {
                continue;
            }
            $rows[] = ['tld' => $tld, 'create' => (string) $row['create'], 'renew' => (string) $row['renew'], 'transfer' => (string) $row['transfer'], 'offer' => ($row['offer'] ?? false) === true];
        }

        return $rows;
    }

    public function environment(): string
    {
        return $this->sandbox ? 'sandbox' : 'live';
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function orderBlocker(string $domain, array $registrant, array $nameservers): ?string
    {
        if (!$this->termsAccepted) {
            return 'Domain orders are not available right now. Please contact our support.';
        }
        if (str_ends_with(mb_strtolower($domain), '.it')) {
            return '.it domains cannot be ordered here. Please contact our support.';
        }
        if ($nameservers !== [] && (count($nameservers) < 2 || count($nameservers) > 6)) {
            return 'Domain orders are not available right now. Please contact our support.';
        }
        if (self::splitStreet((string) ($registrant['street'] ?? ''))[1] === '') {
            return 'Please add the house number to the street of your address (Account → Profile).';
        }
        foreach (['firstname', 'lastname'] as $field) {
            if (trim((string) ($registrant[$field] ?? '')) === '') {
                return 'Domain registrations need your first and last name (Account → Profile).';
            }
        }

        return null;
    }

    public function registrantMatches(string $domain, array $registrant): bool
    {
        $result = $this->call('GET', '/domain/contact', ['query' => ['domain' => $this->domain($domain)]], 'domain.contact.get');
        $contact = is_array($result['contact'] ?? null) ? $result['contact'] : [];
        $same = static fn (mixed $a, mixed $b): bool => is_string($a) && is_string($b) && $a !== '' && mb_strtolower(trim($a)) === mb_strtolower(trim($b));

        return $same($contact['email'] ?? null, $registrant['email'] ?? null)
            && $same($contact['firstname'] ?? null, $registrant['firstname'] ?? null)
            && $same($contact['lastname'] ?? null, $registrant['lastname'] ?? null);
    }

    public function renewsExplicitly(): bool
    {
        return true;
    }

    public function renew(string $domain): ?string
    {
        $result = $this->call('POST', '/product/renew', ['json' => ['domain' => $this->domain($domain)]], 'product.renew');

        return isset($result['expireAt']) && is_string($result['expireAt']) && $result['expireAt'] !== '' ? $result['expireAt'] : null;
    }

    public function authCode(string $domain): ?string
    {
        $result = $this->call('GET', '/domain/authcode', ['query' => ['domain' => $this->domain($domain)]], 'domain.authcode');
        $code = $result['authcode'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * SKRIME stores the registrant with every domain; the "handle" is a local
     * fingerprint of the data, so the panel keeps reusing it while the profile
     * stays the same.
     */
    public function createContact(array $contact): string
    {
        return 'skrime:' . substr(hash('sha256', json_encode($contact, JSON_THROW_ON_ERROR)), 0, 32);
    }

    public function register(string $domain, string $contactId, array $nameservers, int $years, array $registrant = []): ?string
    {
        if ($years !== 1) {
            throw new OrderRejectedException('SKRIME registers domains for one year; further years are renewed by the panel.');
        }

        return $this->order($domain, null, $nameservers, $registrant);
    }

    public function transfer(string $domain, #[\SensitiveParameter] string $authCode, string $contactId, array $nameservers, array $registrant = []): ?string
    {
        return $this->order($domain, $authCode, $nameservers, $registrant);
    }

    public function setNameservers(string $domain, array $nameservers): void
    {
        $this->call('POST', '/domain/nameserver', ['json' => ['domain' => $this->domain($domain), 'nameserver' => $this->nameservers($nameservers)]], 'domain.nameserver.update');
    }

    /**
     * SKRIME's auto renewal is switched off in every mode: renewals cost the
     * operator's balance, so the panel renews only paid periods (renew()).
     * Without a renewal the domain ends with its term.
     */
    public function setRenewalMode(string $domain, string $mode): void
    {
        if (!in_array($mode, [self::RENEW, self::EXPIRE, self::DELETE], true)) {
            throw new IntegrationException('Invalid renewal mode.');
        }
        $this->call('POST', '/product/autorenew', ['json' => ['domain' => $this->domain($domain), 'autoRenew' => false]], 'product.autorenew');
    }

    /**
     * @param list<string> $nameservers
     * @param array<string, string> $registrant
     */
    private function order(string $domain, #[\SensitiveParameter] ?string $authCode, array $nameservers, array $registrant): ?string
    {
        if (!$this->termsAccepted) {
            throw new OrderRejectedException('Ordering at SKRIME is blocked: accept the SKRIME terms of service in the integration settings first.');
        }
        try {
            $contact = self::contact($registrant);
        } catch (IntegrationException $exception) {
            throw new OrderRejectedException($exception->getMessage());
        }
        $body = [
            'domain' => $this->domain($domain),
            'contact' => $contact,
            // Accepted by the panel operator in the integration settings (SKRIME terms and
            // the immediate start of the service, which ends the right of withdrawal).
            'tos' => true,
            'cancellation' => true,
        ];
        try {
            $body['nameserver'] = $this->nameservers($nameservers !== [] ? $nameservers : $this->accountNameservers());
        } catch (IntegrationException $exception) {
            throw new OrderRejectedException($exception->getMessage(), $exception->httpStatus(), $exception);
        }
        if ($authCode !== null) {
            $body['authCode'] = $authCode;
        }
        try {
            $product = $this->call('POST', '/domain/order', ['json' => $body], $authCode !== null ? 'domain.transfer' : 'domain.order');
        } catch (IntegrationException $exception) {
            // A definitive refusal (4xx, or an error envelope) means nothing was ordered;
            // timeouts, connection errors and 5xx leave the outcome unknown.
            $status = $exception->httpStatus();
            if ($status !== null && ($status < 300 || ($status >= 400 && $status < 500 && $status !== 408))) {
                throw new OrderRejectedException($exception->getMessage(), $status, $exception);
            }
            throw $exception;
        }

        return isset($product['id']) && is_string($product['id']) && $product['id'] !== '' ? $product['id'] : null;
    }

    /**
     * Registrant in SKRIME's format: first and last name, street and house
     * number separately, phone as +CC.NUMBER.
     *
     * @param array<string, string> $registrant
     * @return array<string, string>
     */
    public static function contact(array $registrant): array
    {
        foreach (['firstname', 'lastname', 'street', 'postcode', 'city', 'country', 'email', 'phone'] as $field) {
            if (trim((string) ($registrant[$field] ?? '')) === '') {
                throw new IntegrationException(sprintf('The registrant data is incomplete (%s).', $field));
            }
        }
        [$street, $number] = self::splitStreet((string) $registrant['street']);
        $contact = [
            'firstname' => (string) $registrant['firstname'],
            'lastname' => (string) $registrant['lastname'],
            'street' => $street,
            'number' => $number,
            'postcode' => (string) $registrant['postcode'],
            'city' => (string) $registrant['city'],
            'country' => strtoupper((string) $registrant['country']),
            'email' => (string) $registrant['email'],
            'phone' => (string) $registrant['phone'],
        ];
        if (trim((string) ($registrant['org'] ?? '')) !== '') {
            $contact = ['company' => (string) $registrant['org']] + $contact;
        }
        if (trim((string) ($registrant['state'] ?? '')) !== '') {
            $contact['state'] = (string) $registrant['state'];
        }

        return $contact;
    }

    /**
     * "Musterstraße 1a" → ["Musterstraße", "1a"]; "12 Main Street" → ["Main Street", "12"].
     *
     * @return array{0: string, 1: string}
     */
    public static function splitStreet(string $street): array
    {
        $street = trim(preg_replace('/\s+/u', ' ', $street) ?? $street);
        if (preg_match('/^(.+?)\s+(\d+[\p{L}]?(?:\s?[-\/]\s?\d*[\p{L}]?)?)$/u', $street, $match) === 1) {
            return [$match[1], $match[2]];
        }
        if (preg_match('/^(\d+[\p{L}]?)\s+(.+)$/u', $street, $match) === 1) {
            return [$match[2], $match[1]];
        }

        return [$street, ''];
    }

    /**
     * @return list<string>
     */
    private function accountNameservers(): array
    {
        $user = $this->call('GET', '/account/user', [], 'account.user');

        return array_values(array_map('strval', is_array($user['nameserver'] ?? null) ? $user['nameserver'] : []));
    }

    /**
     * @param list<string> $nameservers
     * @return list<string>
     */
    private function nameservers(array $nameservers): array
    {
        $clean = array_values(array_unique(array_filter(array_map(static fn (string $ns): string => mb_strtolower(trim($ns)), $nameservers), static fn (string $ns): bool => $ns !== '')));
        if (count($clean) < 2 || count($clean) > 6) {
            throw new IntegrationException('SKRIME needs two to six name servers.');
        }
        foreach ($clean as $ns) {
            if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $ns) !== 1) {
                throw new IntegrationException(sprintf('"%s" is not a valid name server.', mb_substr($ns, 0, 60)));
            }
        }

        return $clean;
    }

    private function domain(string $domain): string
    {
        $domain = mb_strtolower(trim($domain));
        if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1) {
            throw new IntegrationException('Invalid domain name.');
        }

        return $domain;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    private function call(string $method, string $path, array $options, string $action): array
    {
        $response = $this->api->request($method, $path, $options + ['action' => $action]);
        $json = $response['json'];
        if (!is_array($json) || ($json['state'] ?? null) !== 'success') {
            $message = is_array($json) && is_string($json['response'] ?? null) ? $json['response'] : 'unexpected response';

            throw new IntegrationException('SKRIME: ' . mb_substr($message, 0, 200), $response['status']);
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }
}
