<?php

declare(strict_types=1);

namespace Nebrix\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * INWX DomRobot JSON-RPC with the name server methods (nameserver.info,
 * createRecord, updateRecord, deleteRecord). Names in nameserver.info are
 * fully qualified like in the real API; unknown zones answer 2303.
 */
final class FakeInwxDns
{
    /** @var array<string, array<string, mixed>> domain => domain.info result */
    public array $domains = [];

    /** @var array<string, list<array{id: int, name: string, type: string, content: string, ttl: int, prio: int}>> */
    public array $zones = [];

    /** @var list<array{method: string, params: array<string, mixed>}> */
    public array $calls = [];

    private int $nextId = 5000;

    private ?string $session = null;

    public function register(FakeHttp $http): void
    {
        $http->on('POST', '/jsonrpc/', function (RequestInterface $request): Response {
            $call = FakeHttp::json($request);
            $method = (string) ($call['method'] ?? '');
            $params = is_array($call['params'] ?? null) ? $call['params'] : [];
            $this->calls[] = ['method' => $method, 'params' => $params];

            if ($method === 'account.login') {
                if (($params['user'] ?? '') !== 'reseller' || ($params['pass'] ?? '') !== 'inwx-pass') {
                    return $this->result(2200, 'Authentication error');
                }
                $this->session = 'sess' . bin2hex(random_bytes(4));

                return new Response(200, ['Content-Type' => 'application/json', 'Set-Cookie' => 'domrobot=' . $this->session . '; path=/; secure; HttpOnly'], json_encode(['code' => 1000, 'msg' => 'OK', 'resData' => ['tfa' => '0']], JSON_THROW_ON_ERROR));
            }
            if ($this->session === null || $request->getHeaderLine('Cookie') !== 'domrobot=' . $this->session) {
                return $this->result(2200, 'Authentication error');
            }
            $domain = (string) ($params['domain'] ?? '');

            switch ($method) {
                case 'domain.info':
                    return isset($this->domains[$domain]) ? $this->result(1000, 'OK', $this->domains[$domain]) : $this->result(2303, 'Object does not exist');
                case 'nameserver.info':
                    return isset($this->zones[$domain]) ? $this->result(1000, 'OK', ['domain' => $domain, 'type' => 'MASTER', 'record' => $this->zones[$domain]]) : $this->result(2303, 'Object does not exist');
                case 'nameserver.createRecord':
                    if (!isset($this->zones[$domain])) {
                        return $this->result(2303, 'Object does not exist');
                    }
                    $id = ++$this->nextId;
                    $name = (string) ($params['name'] ?? '');
                    $this->zones[$domain][] = ['id' => $id, 'name' => $name === '' ? $domain : $name . '.' . $domain, 'type' => (string) $params['type'], 'content' => (string) $params['content'], 'ttl' => (int) ($params['ttl'] ?? 3600), 'prio' => (int) ($params['prio'] ?? 0)];

                    return $this->result(1000, 'OK', ['id' => $id]);
                case 'nameserver.updateRecord':
                case 'nameserver.deleteRecord':
                    foreach ($this->zones as $zone => $records) {
                        foreach ($records as $index => $record) {
                            if ($record['id'] !== (int) ($params['id'] ?? 0)) {
                                continue;
                            }
                            if ($method === 'nameserver.deleteRecord') {
                                unset($this->zones[$zone][$index]);
                                $this->zones[$zone] = array_values($this->zones[$zone]);
                            } else {
                                $name = (string) ($params['name'] ?? '');
                                $this->zones[$zone][$index] = ['id' => $record['id'], 'name' => $name === '' ? $zone : $name . '.' . $zone, 'type' => (string) $params['type'], 'content' => (string) $params['content'], 'ttl' => (int) $params['ttl'], 'prio' => (int) ($params['prio'] ?? 0)];
                            }

                            return $this->result(1000, 'OK');
                        }
                    }

                    return $this->result(2303, 'Object does not exist');
                default:
                    return $this->result(2400, 'Command failed');
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function callsTo(string $method): array
    {
        return array_values(array_map(static fn (array $c): array => $c['params'], array_filter($this->calls, static fn (array $c): bool => $c['method'] === $method)));
    }

    /**
     * @param array<string, mixed> $resData
     */
    private function result(int $code, string $message, array $resData = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['code' => $code, 'msg' => $message, 'resData' => $resData], JSON_THROW_ON_ERROR));
    }
}
