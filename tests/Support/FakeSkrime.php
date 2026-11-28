<?php

declare(strict_types=1);

namespace Nebrix\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Stateful fake of the SKRIME API (envelope {response, state, data}, bearer
 * key), modelled on https://skrime.eu/docs/openapi.json. Domain checks and the
 * price list were compared with the live API's public answers.
 */
final class FakeSkrime
{
    public const KEY = 'skr_test_0123456789abcdef0123';

    /** @var array<string, array<string, mixed>> domain => product */
    public array $domains = [];

    /** @var list<string> registered at another provider */
    public array $registeredElsewhere = [];

    /** @var list<string> */
    public array $premium = [];

    /** @var list<array{path: string, body: array<string, mixed>}> */
    public array $orders = [];

    public string $balance = '25.00';

    /** @var list<string> domains renewed through product/renew */
    public array $renewals = [];

    /** Simulates a lost answer: the order is carried out, then the connection fails. */
    public bool $loseNextOrderAnswer = false;

    public function register(FakeHttp $http): void
    {
        $prefix = '/(?:sandbox|api)';
        $ok = static fn (string $message, mixed $data, int $status = 200): Response => new Response($status, ['Content-Type' => 'application/json'], json_encode(['response' => $message, 'state' => 'success', 'data' => $data], JSON_THROW_ON_ERROR));
        $error = static fn (string $message, int $status, mixed $data = []): Response => new Response($status, ['Content-Type' => 'application/json'], json_encode(['response' => $message, 'state' => 'error', 'data' => $data], JSON_THROW_ON_ERROR));
        $auth = function (RequestInterface $r, \Closure $next) use ($error): Response {
            if ($r->getHeaderLine('Authorization') !== 'Bearer ' . self::KEY) {
                return $error('Unauthorized access, please log in or provide a valid key', 401);
            }

            return $next();
        };
        $query = static function (RequestInterface $r): array {
            parse_str($r->getUri()->getQuery(), $q);

            return $q;
        };
        $body = static fn (RequestInterface $r): array => (array) json_decode((string) $r->getBody(), true);
        $product = function (string $domain) use ($error, $ok): Response {
            if (!isset($this->domains[$domain])) {
                return $error('No product found or product is suspended', 404);
            }
            $d = $this->domains[$domain];
            // Per the API description, 404 covers missing and inactive (suspended) products.
            if (($d['suspended'] ?? false) === true) {
                return $error('No product found or product is suspended', 404);
            }
            if (($d['blocked'] ?? false) === true) {
                return $error('Access blocked due to product suspension', 423, ['reason' => 'Payment overdue']);
            }

            return $ok('Successfully fetched product', [
                'id' => $d['id'], 'type' => 'domain', 'state' => $d['state'], 'userRights' => [],
                'productInfo' => ['domain' => $domain, 'handle' => $d['handle'], 'provider' => 'realtime', 'view' => ['reason' => ''], 'renew' => ['reason' => '']],
                'price' => '2.99', 'duration' => '12', 'autoRenew' => $d['autoRenew'], 'expireAt' => $d['expireAt'], 'createdAt' => $d['createdAt'] ?? '2026-01-01 00:00:00',
            ]);
        };

        $http
            ->on('GET', $prefix . '/account/user', fn ($r) => $auth($r, fn () => $ok('Successfully fetched user data', [
                'userId' => 'U-1', 'state' => 'active', 'username' => 'Reseller GmbH', 'email' => 'reseller@example.test',
                'balance' => ['amount' => $this->balance, 'coins' => '0', 'dispo' => '0.00', 'reservedAmount' => '0.00', 'total' => $this->balance],
                'nameserver' => ['nameserver01.eu', 'nameserver02.eu', 'nameserver03.eu'],
            ])))
            ->on('POST', $prefix . '/domain/check', function ($r) use ($ok, $body): Response {
                // Public on the live API (answers without a valid key).
                $domain = (string) ($body($r)['domain'] ?? '');

                return $ok('Successfully fetched domain check', ['domain' => $domain, 'available' => !isset($this->domains[$domain]) && !in_array($domain, $this->registeredElsewhere, true), 'premium' => in_array($domain, $this->premium, true)]);
            })
            ->on('GET', $prefix . '/product/single', fn ($r) => $auth($r, fn () => $product((string) ($query($r)['domain'] ?? ''))))
            ->on('GET', $prefix . '/domain/nameserver', fn ($r) => $auth($r, function () use ($r, $query, $ok, $error): Response {
                $domain = (string) ($query($r)['domain'] ?? '');

                return isset($this->domains[$domain]) ? $ok('Successfully fetched nameservers', ['domain' => $domain, 'nameserver' => $this->domains[$domain]['nameserver']]) : $error('No product found or product is suspended', 404);
            }))
            ->on('POST', $prefix . '/domain/nameserver', fn ($r) => $auth($r, function () use ($r, $body, $ok, $error): Response {
                $data = $body($r);
                $domain = (string) ($data['domain'] ?? '');
                $ns = (array) ($data['nameserver'] ?? []);
                if (!isset($this->domains[$domain])) {
                    return $error('No product found or product is suspended', 404);
                }
                if (count($ns) < 2 || count($ns) > 6) {
                    return $error('Invalid request, please provide between 2 and 6 nameservers', 400);
                }
                $this->domains[$domain]['nameserver'] = array_values($ns);

                return $ok('Successfully updated nameservers', ['domain' => $domain, 'nameserver' => $ns]);
            }))
            ->on('GET', $prefix . '/domain/authcode', fn ($r) => $auth($r, function () use ($r, $query, $ok, $error): Response {
                $domain = (string) ($query($r)['domain'] ?? '');

                return isset($this->domains[$domain]) ? $ok('Successfully fetched authcode', ['domain' => $domain, 'authcode' => $this->domains[$domain]['authcode']]) : $error('No product found or product is suspended', 404);
            }))
            ->on('GET', $prefix . '/domain/contact', fn ($r) => $auth($r, function () use ($r, $query, $ok, $error): Response {
                $domain = (string) ($query($r)['domain'] ?? '');

                return isset($this->domains[$domain]) ? $ok('Successfully fetched contact', ['domain' => $domain, 'contact' => $this->domains[$domain]['contact']]) : $error('No product found or product is suspended', 404);
            }))
            ->on('POST', $prefix . '/product/renew', fn ($r) => $auth($r, function () use ($r, $body, $ok, $error): Response {
                $domain = (string) ($body($r)['domain'] ?? '');
                if (!isset($this->domains[$domain])) {
                    return $error('No product found or product is suspended', 404);
                }
                if ((float) $this->balance < 2.99) {
                    return $error('Insufficient balance', 400);
                }
                $this->renewals[] = $domain;
                $this->domains[$domain]['expireAt'] = gmdate('Y-m-d H:i:s', (int) strtotime($this->domains[$domain]['expireAt'] . ' UTC +1 year'));

                return $ok('Successfully renewed product', ['productId' => $this->domains[$domain]['id'], 'price' => '2.99', 'expireAt' => $this->domains[$domain]['expireAt']]);
            }))
            ->on('POST', $prefix . '/product/autorenew', fn ($r) => $auth($r, function () use ($r, $body, $ok, $error): Response {
                $data = $body($r);
                $domain = (string) ($data['domain'] ?? '');
                if (!isset($this->domains[$domain])) {
                    return $error('No product found or product is suspended', 404);
                }
                if (!is_bool($data['autoRenew'] ?? null)) {
                    return $error('Invalid request, please provide autoRenew', 400);
                }
                $this->domains[$domain]['autoRenew'] = $data['autoRenew'];

                return $ok('Successfully updated auto renewal status', ['productId' => $this->domains[$domain]['id'], 'autoRenew' => $data['autoRenew']]);
            }))
            ->on('POST', $prefix . '/domain/order', fn ($r) => $auth($r, function () use ($r, $body, $ok, $error): Response {
                $data = $body($r);
                $this->orders[] = ['path' => $r->getUri()->getPath(), 'body' => $data];
                $domain = (string) ($data['domain'] ?? '');
                $contact = (array) ($data['contact'] ?? []);
                if (($data['tos'] ?? null) !== true || ($data['cancellation'] ?? null) !== true) {
                    return $error('Invalid request, please accept the terms of service', 400);
                }
                foreach (['firstname', 'lastname', 'street', 'postcode', 'city', 'country', 'email', 'phone'] as $field) {
                    if (trim((string) ($contact[$field] ?? '')) === '') {
                        return $error('Invalid request, please provide a ' . $field, 400);
                    }
                }
                $ns = (array) ($data['nameserver'] ?? []);
                if (count($ns) < 2 || count($ns) > 6) {
                    return $error('Invalid request, please provide between 2 and 6 nameservers', 400);
                }
                if (str_ends_with($domain, '.eu') && !in_array($contact['country'], ['DE', 'AT', 'FR', 'NL', 'NO'], true)) {
                    return $error('Invalid country, .eu domains require a registrant in the EU or EEA', 400);
                }
                $transfer = isset($data['authCode']);
                if (isset($this->domains[$domain]) || ($transfer ? !in_array($domain, $this->registeredElsewhere, true) : in_array($domain, $this->registeredElsewhere, true))) {
                    return $error($transfer ? 'Invalid request, domain cannot be transferred' : 'Invalid request, domain is not available', 400);
                }
                if (str_starts_with($r->getUri()->getPath(), '/sandbox')) {
                    return $ok('Successfully ordered product', ['id' => 'SANDBOX', 'type' => 'domain', 'state' => 'active', 'productInfo' => ['domain' => $domain]]);
                }
                $id = sprintf('00000000-0000-4000-8000-%012d', count($this->domains) + 1);
                $this->domains[$domain] = [
                    'id' => $id, 'handle' => 'H-' . $id, 'state' => $transfer ? 'pending' : 'active', 'autoRenew' => false,
                    'expireAt' => gmdate('Y-m-d H:i:s', time() + 365 * 86400), 'createdAt' => gmdate('Y-m-d H:i:s'), 'nameserver' => array_values($ns), 'contact' => $contact, 'authcode' => 'Sk!rme-' . substr(md5($domain), 0, 10),
                ];
                if ($this->loseNextOrderAnswer) {
                    $this->loseNextOrderAnswer = false;

                    return new Response(504, ['Content-Type' => 'text/html'], '<html>Gateway Timeout</html>');
                }

                return $ok('Successfully ordered product', ['id' => $id, 'type' => 'domain', 'state' => $this->domains[$domain]['state'], 'productInfo' => ['domain' => $domain, 'handle' => 'H-' . $id], 'autoRenew' => false, 'expireAt' => '2027-11-28 10:00:00']);
            }));
    }
}
