<?php

declare(strict_types=1);

namespace Nebrix\Integration\Discord;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Nebrix\Integration\IntegrationException;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal Discord API client: OAuth2 code exchange, the signed-in user,
 * adding a member to a server and assigning roles. Fixed host, no
 * redirects, short timeouts; tokens are never logged or stored by it.
 */
class DiscordClient
{
    public const API = 'https://discord.com/api/v10';
    public const AUTHORIZE = 'https://discord.com/oauth2/authorize';

    public function __construct(private readonly ClientInterface $http)
    {
    }

    /**
     * @param list<string> $scopes
     */
    public static function authorizeUrl(string $clientId, string $redirectUri, string $state, array $scopes): string
    {
        return self::AUTHORIZE . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => self::snowflake($clientId),
            'scope' => implode(' ', $scopes),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'prompt' => 'consent',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Exchanges the authorization code for a user access token (used once, never stored). */
    public function exchangeCode(string $clientId, #[\SensitiveParameter] string $clientSecret, string $code, string $redirectUri): string
    {
        $json = $this->json($this->send('POST', '/oauth2/token', [
            'auth' => [self::snowflake($clientId), $clientSecret],
            'form_params' => ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri],
        ]), [200]);
        $token = $json['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new IntegrationException('Discord did not return an access token.');
        }

        return $token;
    }

    /**
     * @return array{id: string, username: string, global_name: string|null}
     */
    public function currentUser(#[\SensitiveParameter] string $accessToken): array
    {
        $json = $this->json($this->send('GET', '/users/@me', ['headers' => ['Authorization' => 'Bearer ' . $accessToken]]), [200]);
        $id = (string) ($json['id'] ?? '');

        return [
            'id' => self::snowflake($id),
            'username' => mb_substr((string) ($json['username'] ?? ''), 0, 100),
            'global_name' => isset($json['global_name']) && is_string($json['global_name']) ? mb_substr($json['global_name'], 0, 100) : null,
        ];
    }

    /**
     * Adds the user to the server (needs the "guilds.join" scope of the
     * user token and a bot on the server). Returns false if the user was
     * already a member.
     *
     * @param list<string> $roles
     */
    public function addMember(#[\SensitiveParameter] string $botToken, string $guildId, string $userId, #[\SensitiveParameter] string $accessToken, array $roles): bool
    {
        $response = $this->send('PUT', '/guilds/' . self::snowflake($guildId) . '/members/' . self::snowflake($userId), [
            'headers' => $this->bot($botToken),
            'json' => ['access_token' => $accessToken] + ($roles !== [] ? ['roles' => array_map(self::snowflake(...), $roles)] : []),
        ]);
        $this->json($response, [201, 204]);

        return $response->getStatusCode() === 201;
    }

    /**
     * @return list<string>|null role IDs of the member, null if the user is not on the server
     */
    public function memberRoles(#[\SensitiveParameter] string $botToken, string $guildId, string $userId): ?array
    {
        $response = $this->send('GET', '/guilds/' . self::snowflake($guildId) . '/members/' . self::snowflake($userId), ['headers' => $this->bot($botToken)]);
        if ($response->getStatusCode() === 404) {
            return null;
        }
        $json = $this->json($response, [200]);

        return array_values(array_filter(array_map('strval', is_array($json['roles'] ?? null) ? $json['roles'] : []), static fn (string $role): bool => preg_match('/^\d{17,20}$/', $role) === 1));
    }

    public function addRole(#[\SensitiveParameter] string $botToken, string $guildId, string $userId, string $roleId, string $reason): void
    {
        $this->json($this->send('PUT', $this->rolePath($guildId, $userId, $roleId), ['headers' => $this->bot($botToken) + ['X-Audit-Log-Reason' => rawurlencode($reason)]]), [204]);
    }

    /** Removing a role the member does not have, or from a user who left, is not an error. */
    public function removeRole(#[\SensitiveParameter] string $botToken, string $guildId, string $userId, string $roleId, string $reason): void
    {
        $response = $this->send('DELETE', $this->rolePath($guildId, $userId, $roleId), ['headers' => $this->bot($botToken) + ['X-Audit-Log-Reason' => rawurlencode($reason)]]);
        if ($response->getStatusCode() !== 404) {
            $this->json($response, [204]);
        }
    }

    /**
     * Sends a direct message from the bot (opens the DM channel first).
     * Discord only delivers it while the user shares a server with the bot
     * and accepts direct messages; otherwise it answers 403 (code 50007).
     * Mentions are never resolved.
     */
    public function sendDirectMessage(#[\SensitiveParameter] string $botToken, string $userId, string $content): void
    {
        $channel = $this->json($this->send('POST', '/users/@me/channels', ['headers' => $this->bot($botToken), 'json' => ['recipient_id' => self::snowflake($userId)]]), [200]);
        $this->json($this->send('POST', '/channels/' . self::snowflake((string) ($channel['id'] ?? '')) . '/messages', [
            'headers' => $this->bot($botToken),
            'json' => ['content' => mb_substr($content, 0, 2000), 'allowed_mentions' => ['parse' => []]],
        ]), [200]);
    }

    public static function snowflake(string $id): string
    {
        if (preg_match('/^\d{17,20}$/', $id) !== 1) {
            throw new IntegrationException('Invalid Discord ID.');
        }

        return $id;
    }

    private function rolePath(string $guildId, string $userId, string $roleId): string
    {
        return '/guilds/' . self::snowflake($guildId) . '/members/' . self::snowflake($userId) . '/roles/' . self::snowflake($roleId);
    }

    /**
     * @return array<string, string>
     */
    private function bot(#[\SensitiveParameter] string $token): array
    {
        if ($token === '') {
            throw new IntegrationException('No Discord bot token is configured.');
        }

        return ['Authorization' => 'Bot ' . $token];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $path, array $options): ResponseInterface
    {
        try {
            return $this->http->request($method, self::API . $path, $options + [
                'timeout' => 10,
                'connect_timeout' => 5,
                'http_errors' => false,
                'allow_redirects' => false,
                'headers' => [],
            ] + ['headers' => []]);
        } catch (GuzzleException $exception) {
            throw new IntegrationException('Discord is not reachable: ' . $exception->getMessage(), null, $exception);
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
        if ($status === 429) {
            throw new DiscordRateLimited((float) ($json['retry_after'] ?? 5));
        }
        if (!in_array($status, $expected, true)) {
            $message = is_string($json['message'] ?? null) ? mb_substr($json['message'], 0, 200) : (is_string($json['error_description'] ?? null) ? mb_substr($json['error_description'], 0, 200) : 'unexpected response');

            throw new IntegrationException(sprintf('Discord API error %d: %s', $status, $message), $status);
        }

        return $json;
    }
}
