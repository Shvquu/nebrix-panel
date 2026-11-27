<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Customer\CustomerService;
use Nebrix\Customer\SupportPin;
use Nebrix\Discord\DiscordService;
use Nebrix\Discord\DiscordSyncJob;
use Nebrix\Provisioning\RetryLaterException;
use Nebrix\Service\ServiceRepository;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\FakeHttp;

/**
 * Discord account link (OAuth2 with session-bound state), server join and
 * role sync against a fake Discord API; support PIN.
 */
final class DiscordTest extends FeatureTestCase
{
    private const GUILD = '223456789012345678';
    private const ROLE_LINKED = '323456789012345678';
    private const ROLE_ACTIVE = '423456789012345678';
    private const DISCORD_USER = '523456789012345678';

    private FakeHttp $http;

    /** @var list<string> */
    private array $memberRoles = [];

    private bool $onServer = false;

    private bool $rateLimited = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttp();
        $this->app->container()->set(ClientInterface::class, fn (): ClientInterface => $this->http->client());
        $settings = $this->app->container()->get(SettingsRepository::class);
        foreach ([
            'discord.enabled' => true, 'discord.client_id' => '123456789012345678', 'discord.client_secret' => 'client-secret',
            'discord.bot_token' => 'bot-token', 'discord.guild_id' => self::GUILD, 'discord.join_guild' => true,
            'discord.role_linked' => self::ROLE_LINKED, 'discord.role_active' => self::ROLE_ACTIVE, 'discord.invite_url' => 'https://discord.gg/nebrix',
        ] as $key => $value) {
            $settings->set($key, $value);
        }

        $member = '/api/v10/guilds/' . self::GUILD . '/members/(?<user>\d+)';
        $this->http
            ->on('POST', '/api/v10/oauth2/token', static fn ($request): Response => FakeHttp::form($request)['code'] === 'good-code'
                ? new Response(200, [], (string) json_encode(['access_token' => 'user-token', 'token_type' => 'Bearer']))
                : new Response(400, [], (string) json_encode(['error' => 'invalid_grant', 'error_description' => 'Invalid code'])))
            ->on('GET', '/api/v10/users/@me', static fn ($request): Response => $request->getHeaderLine('Authorization') === 'Bearer user-token'
                ? new Response(200, [], (string) json_encode(['id' => self::DISCORD_USER, 'username' => 'ada', 'global_name' => 'Ada L.']))
                : new Response(401, [], '{"message":"401: Unauthorized"}'))
            ->on('PUT', $member, function (): Response {
                $this->onServer = true;

                return new Response(201, [], '{}');
            })
            ->on('GET', $member, fn (): Response => $this->onServer ? new Response(200, [], (string) json_encode(['roles' => $this->memberRoles])) : new Response(404, [], '{"message":"Unknown Member"}'))
            ->on('PUT', $member . '/roles/(?<role>\d+)', function ($r, array $m): Response {
                if ($this->rateLimited) {
                    return new Response(429, [], '{"message":"You are being rate limited.","retry_after":2.5}');
                }
                $this->memberRoles = array_values(array_unique([...$this->memberRoles, $m['role']]));

                return new Response(204);
            })
            ->on('DELETE', $member . '/roles/(?<role>\d+)', function ($r, array $m): Response {
                $this->memberRoles = array_values(array_diff($this->memberRoles, [$m['role']]));

                return new Response(204);
            });
    }

    private function runJobs(): void
    {
        foreach ($this->db->fetchAll("SELECT `id`, `payload` FROM `nx_jobs` WHERE `type` = ? AND `status` = 'pending' ORDER BY `id`", [DiscordService::JOB_SYNC]) as $job) {
            $this->db->run('DELETE FROM `nx_jobs` WHERE `id` = ?', [$job['id']]);
            $this->app->container()->get(DiscordSyncJob::class)->handle((array) json_decode((string) $job['payload'], true));
        }
    }

    /**
     * @return array{state: string, location: string}
     */
    private function connect(): array
    {
        $response = $this->get('/account/discord/connect');
        $location = (string) $response->header('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return ['state' => (string) ($query['state'] ?? ''), 'location' => $location];
    }

    public function testCustomerLinksDiscordJoinsTheServerAndGetsRoles(): void
    {
        $customer = $this->createUser(Role::CUSTOMER, ['first_name' => 'Ada']);
        $this->actingAs($customer);
        self::assertStringContainsString('/account/discord/connect', $this->get('/dashboard')->body());

        $start = $this->connect();
        self::assertStringStartsWith('https://discord.com/oauth2/authorize?', $start['location']);
        parse_str((string) parse_url($start['location'], PHP_URL_QUERY), $query);
        self::assertSame(['identify guilds.join', 'http://localhost/account/discord/callback', '123456789012345678'], [$query['scope'], $query['redirect_uri'], $query['client_id']]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $start['state']);

        // A forged state (e.g. a link prepared by somebody else) is refused.
        $this->get('/account/discord/callback', ['code' => 'good-code', 'state' => str_repeat('a', 64)]);
        self::assertNull($this->db->fetchValue('SELECT discord_id FROM nx_discord_links WHERE user_id = ?', [$customer->id]));
        self::assertSame([], $this->http->requests('POST', '/api/v10/oauth2/token'), 'No token exchange without a valid state.');

        $start = $this->connect();
        $this->get('/account/discord/callback', ['code' => 'good-code', 'state' => $start['state']]);
        $link = (array) $this->db->fetchOne('SELECT * FROM nx_discord_links WHERE user_id = ?', [$customer->id]);
        self::assertSame([self::DISCORD_USER, 'ada', 'Ada L.', 1], [$link['discord_id'], $link['username'], $link['global_name'], (int) $link['in_guild']]);
        $join = $this->http->requests('PUT', '/api/v10/guilds/\d+/members/\d+')[0];
        self::assertSame('Bot bot-token', $join->getHeaderLine('Authorization'));
        self::assertSame(['access_token' => 'user-token'], FakeHttp::json($join), 'The user token is only used to join and never stored.');
        self::assertStringNotContainsString('user-token', (string) json_encode($link));
        self::assertStringContainsString('Ada L.', $this->get('/dashboard')->body());

        // The state is single-use.
        $this->get('/account/discord/callback', ['code' => 'good-code', 'state' => $start['state']]);
        self::assertCount(1, $this->http->requests('POST', '/api/v10/oauth2/token'));

        // Roles: "linked" now, "active" with an active service, removed again when it ends.
        $this->runJobs();
        self::assertSame([self::ROLE_LINKED], $this->memberRoles);
        $serviceId = $this->app->container()->get(ServiceRepository::class)->create([
            'user_id' => $customer->id, 'category' => 'teamspeak', 'name' => 'Clan TS', 'status' => 'active',
            'billing_cycle' => 'monthly', 'recurring_amount' => 300, 'tax_rate_bp' => 1900, 'currency' => 'EUR', 'config' => [],
        ]);
        $this->clock()->freeze($this->clock()->now()->modify('+1 minute'));
        self::assertSame(1, $this->app->container()->get(DiscordService::class)->syncAll());
        $this->runJobs();
        self::assertSame([self::ROLE_LINKED, self::ROLE_ACTIVE], $this->memberRoles);

        $this->db->update('nx_services', ['status' => 'terminated'], ['id' => $serviceId]);
        $this->memberRoles[] = '623456789012345678'; // a role staff gave by hand
        $this->clock()->freeze($this->clock()->now()->modify('+1 minute'));
        $this->app->container()->get(DiscordService::class)->syncAll();
        $this->runJobs();
        self::assertSame([self::ROLE_LINKED, '623456789012345678'], $this->memberRoles, 'Only roles the panel granted are removed.');

        // Unlinking removes the panel's roles.
        $this->post('/account/discord/unlink');
        self::assertNull($this->db->fetchValue('SELECT discord_id FROM nx_discord_links WHERE user_id = ?', [$customer->id]));
        $this->runJobs();
        self::assertSame(['623456789012345678'], $this->memberRoles);
    }

    public function testADiscordAccountBelongsToOneCustomerAndFailuresAreReported(): void
    {
        $first = $this->createUser();
        $this->actingAs($first);
        $this->get('/account/discord/callback', ['code' => 'good-code', 'state' => $this->connect()['state']]);
        self::assertSame(self::DISCORD_USER, $this->db->fetchValue('SELECT discord_id FROM nx_discord_links WHERE user_id = ?', [$first->id]));

        $second = $this->createUser();
        $this->actingAs($second);
        $this->get('/account/discord/callback', ['code' => 'good-code', 'state' => $this->connect()['state']]);
        self::assertNull($this->db->fetchValue('SELECT discord_id FROM nx_discord_links WHERE user_id = ?', [$second->id]));
        self::assertStringContainsString('already linked to another customer account', $this->get('/dashboard')->body());

        // An invalid code, and a cancelled authorization, change nothing.
        $this->get('/account/discord/callback', ['code' => 'bad-code', 'state' => $this->connect()['state']]);
        $this->get('/account/discord/callback', ['error' => 'access_denied', 'state' => $this->connect()['state']]);
        self::assertNull($this->db->fetchValue('SELECT discord_id FROM nx_discord_links WHERE user_id = ?', [$second->id]));

        // Discord rate limits are retried by the queue.
        $this->rateLimited = true;
        $this->onServer = true;
        try {
            $this->app->container()->get(DiscordSyncJob::class)->handle(['user_id' => $first->id]);
            self::fail('A rate limit was not retried.');
        } catch (RetryLaterException $retry) {
            self::assertSame(3, $retry->delaySeconds);
        }
    }

    public function testLinkingIsOnlyOfferedWhenConfigured(): void
    {
        $this->app->container()->get(SettingsRepository::class)->set('discord.enabled', false);
        $this->actingAs($this->createUser());
        self::assertSame(404, $this->get('/account/discord/connect')->status());
        $body = $this->get('/dashboard')->body();
        self::assertStringNotContainsString('/account/discord/connect', $body);
        self::assertStringContainsString('https://discord.gg/nebrix', $body, 'The invite link is still shown.');
    }

    public function testSupportPinAndAccountClosure(): void
    {
        $customer = $this->createUser();
        $pins = $this->app->container()->get(SupportPin::class);
        $pin = $pins->forUser($customer);
        self::assertMatchesRegularExpression('/^\d{3}-\d{3}-\d{3}$/', $pin);
        self::assertStringNotContainsString(str_replace('-', '', $pin), (string) $this->db->fetchValue('SELECT support_pin FROM nx_users WHERE id = ?', [$customer->id]), 'Stored encrypted.');

        $admin = $this->createUser(Role::ADMIN);
        $this->actingAs($admin);
        self::assertStringNotContainsString($pin, $this->get('/admin/customers/' . $customer->id)->body(), 'Staff never see the PIN.');
        $this->post('/admin/customers/' . $customer->id . '/support-pin', ['pin' => $pin]);
        self::assertStringContainsString('The support PIN is correct.', $this->get('/admin/customers/' . $customer->id)->body());
        $this->post('/admin/customers/' . $customer->id . '/support-pin', ['pin' => '000-000-000']);
        self::assertStringContainsString('The support PIN is not correct.', $this->get('/admin/customers/' . $customer->id)->body());

        $this->actingAs($customer);
        $this->post('/account/support-pin');
        self::assertNotSame($pin, $pins->forUser($customer));
        self::assertFalse($pins->verify($customer, $pin), 'The old PIN no longer works.');

        // Closing the account removes PIN and Discord link.
        $this->get('/account/discord/callback', ['code' => 'good-code', 'state' => $this->connect()['state']]);
        $this->app->container()->get(CustomerService::class)->closeAndAnonymize($this->reloadUser($customer), $admin->id, null);
        self::assertSame([null, null], array_values((array) $this->db->fetchOne('SELECT support_pin, vat_id_name FROM nx_users WHERE id = ?', [$customer->id])));
        self::assertNull($this->db->fetchValue('SELECT discord_id FROM nx_discord_links WHERE user_id = ?', [$customer->id]));
    }

    private function reloadUser(User $user): User
    {
        return $this->app->container()->get(\Nebrix\Repository\UserRepository::class)->find($user->id) ?? $user;
    }
}
