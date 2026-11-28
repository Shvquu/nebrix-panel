<?php

declare(strict_types=1);

namespace Nebrix\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * DNS zones of the SKRIME API (GET/POST /domain/dns, modelled on
 * https://skrime.eu/docs/openapi.json: records {name, type, data}; a POST
 * replaces the whole zone). Register after FakeSkrime.
 */
final class FakeSkrimeDns
{
    /** @var array<string, list<array{name: string, type: string, data: string}>> */
    public array $zones = [];

    /** @var list<array<string, mixed>> bodies of POST /domain/dns */
    public array $updates = [];

    public function register(FakeHttp $http): void
    {
        $ok = static fn (string $message, mixed $data): Response => new Response(200, ['Content-Type' => 'application/json'], json_encode(['response' => $message, 'state' => 'success', 'data' => $data], JSON_THROW_ON_ERROR));
        $error = static fn (string $message, int $status): Response => new Response($status, ['Content-Type' => 'application/json'], json_encode(['response' => $message, 'state' => 'error', 'data' => []], JSON_THROW_ON_ERROR));
        $authorized = static fn (RequestInterface $r): bool => $r->getHeaderLine('Authorization') === 'Bearer ' . FakeSkrime::KEY;

        $http
            ->on('GET', '/(?:sandbox|api)/domain/dns', function (RequestInterface $r) use ($ok, $error, $authorized): Response {
                parse_str($r->getUri()->getQuery(), $query);
                $domain = (string) ($query['domain'] ?? '');
                if (!$authorized($r)) {
                    return $error('Unauthorized access, please log in or provide a valid key', 401);
                }

                return isset($this->zones[$domain]) ? $ok('Successfully fetched DNS zone', ['domain' => $domain, 'records' => $this->zones[$domain]]) : $error('No product found or product is suspended', 404);
            })
            ->on('POST', '/(?:sandbox|api)/domain/dns', function (RequestInterface $r) use ($ok, $error, $authorized): Response {
                $body = (array) json_decode((string) $r->getBody(), true);
                $domain = (string) ($body['domain'] ?? '');
                if (!$authorized($r)) {
                    return $error('Unauthorized access, please log in or provide a valid key', 401);
                }
                if (!isset($this->zones[$domain])) {
                    return $error('No product found or product is suspended', 404);
                }
                $records = [];
                foreach ((array) ($body['records'] ?? []) as $record) {
                    if (!is_array($record) || !isset($record['name'], $record['type'], $record['data'])) {
                        return $error('Invalid request, please provide valid records', 400);
                    }
                    $records[] = ['name' => (string) $record['name'], 'type' => (string) $record['type'], 'data' => (string) $record['data']];
                }
                $this->updates[] = $body;
                $this->zones[$domain] = $records;

                return $ok('Successfully updated DNS records', ['domain' => $domain, 'records' => $records]);
            });
    }
}
