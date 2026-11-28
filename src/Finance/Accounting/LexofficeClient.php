<?php

declare(strict_types=1);

namespace Nebrix\Finance\Accounting;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Nebrix\Integration\IntegrationException;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal client for the lexoffice public API: create a voucher
 * (Belegerfassung), attach the PDF and find a voucher by its number. Fixed
 * host, no redirects, short timeouts; the API key is sent as Bearer token and
 * never logged. Tested against a mock only.
 */
class LexofficeClient
{
    public const API = 'https://api.lexoffice.io/v1';

    public function __construct(private readonly ClientInterface $http)
    {
    }

    /**
     * @param array<string, mixed> $voucher
     * @return string ID of the new voucher
     */
    public function createVoucher(#[\SensitiveParameter] string $apiKey, array $voucher): string
    {
        $json = $this->json($this->send('POST', '/vouchers', $apiKey, ['json' => $voucher]), [200, 201]);

        return self::id((string) ($json['id'] ?? ''));
    }

    public function uploadFile(#[\SensitiveParameter] string $apiKey, string $voucherId, string $content, string $filename): void
    {
        $this->json($this->send('POST', '/vouchers/' . self::id($voucherId) . '/files', $apiKey, [
            'multipart' => [
                ['name' => 'file', 'contents' => $content, 'filename' => (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $filename), 'headers' => ['Content-Type' => 'application/pdf']],
                ['name' => 'type', 'contents' => 'voucher'],
            ],
        ]), [200, 201, 202]);
    }

    /** ID of a sales voucher with this number, if one exists (detects a voucher created by an interrupted attempt). */
    public function findVoucher(#[\SensitiveParameter] string $apiKey, string $number): ?string
    {
        $json = $this->json($this->send('GET', '/voucherlist?' . http_build_query([
            'voucherType' => 'salesinvoice,salescreditnote',
            'voucherStatus' => 'any',
            'voucherNumber' => $number,
        ], '', '&', PHP_QUERY_RFC3986), $apiKey, []), [200]);
        foreach (is_array($json['content'] ?? null) ? $json['content'] : [] as $voucher) {
            if (is_array($voucher) && ($voucher['voucherNumber'] ?? null) === $number && is_string($voucher['id'] ?? null)) {
                return self::id($voucher['id']);
            }
        }

        return null;
    }

    public static function id(string $id): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
            throw new IntegrationException('lexoffice returned an invalid voucher ID.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $path, #[\SensitiveParameter] string $apiKey, array $options): ResponseInterface
    {
        if ($apiKey === '') {
            throw new IntegrationException('No lexoffice API key is configured.');
        }
        try {
            return $this->http->request($method, self::API . $path, $options + [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
                'timeout' => 20,
                'connect_timeout' => 5,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (GuzzleException $exception) {
            throw new IntegrationException('lexoffice is not reachable: ' . $exception->getMessage(), null, $exception);
        }
    }

    /**
     * @param list<int> $expected
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response, array $expected): array
    {
        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        $json = is_array($decoded) ? $decoded : [];
        if (!in_array($status, $expected, true)) {
            $message = match (true) {
                $status === 401 => 'the API key was rejected',
                $status === 429 => 'too many requests, try again later',
                is_string($json['message'] ?? null) => mb_substr($json['message'], 0, 200),
                default => 'unexpected response',
            };

            throw new IntegrationException(sprintf('lexoffice API error %d: %s', $status, $message), $status);
        }

        return $json;
    }
}
