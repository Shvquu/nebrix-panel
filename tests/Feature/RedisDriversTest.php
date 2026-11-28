<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use Nebrix\Auth\Role;
use Nebrix\Core\Application;
use Nebrix\Core\Clock;
use Nebrix\Redis\RedisConnection;
use Nebrix\Security\RateLimitStore;
use Nebrix\Security\RedisRateLimitStore;
use Nebrix\Session\RedisSessionBackend;
use Nebrix\Session\SessionStore;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * The whole sign-in flow with sessions and rate limits in Redis
 * (SESSION_DRIVER=redis, RATE_LIMIT_DRIVER=redis).
 */
final class RedisDriversTest extends FeatureTestCase
{
    private RedisConnection $redis;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $url = (string) getenv('TEST_REDIS_URL');
        if ($url === '') {
            self::markTestSkipped('TEST_REDIS_URL is not set (no Redis server for this run).');
        }
        $this->prefix = 'nebrix-test-' . bin2hex(random_bytes(4)) . ':';
        $this->redis = new RedisConnection($url, $this->prefix);
        $container = $this->app->container();
        $container->set(RedisConnection::class, fn (): RedisConnection => $this->redis);
        $container->set(SessionStore::class, fn ($c): SessionStore => new SessionStore(new RedisSessionBackend($this->redis, 3600, 43200), $c->get(Clock::class), 3600, 43200));
        $container->set(RateLimitStore::class, fn (): RateLimitStore => new RedisRateLimitStore($this->redis));
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $keys = (array) $this->redis->client()->keys($this->prefix . '*');
            if ($keys !== []) {
                $this->redis->client()->del($keys);
            }
        }
        parent::tearDown();
    }

    public function testSignInSessionsAndLockoutLiveInRedis(): void
    {
        $user = $this->createUser(Role::CUSTOMER, ['email' => 'redis@example.test']);
        $this->assertRedirect($this->post('/login', ['email' => 'redis@example.test', 'password' => 'Valid-Password-123']), '/dashboard');
        self::assertSame(200, $this->get('/dashboard')->status());
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_sessions WHERE user_id = ?', [$user->id]), 'Nothing in the database.');
        self::assertSame(1, $this->app->container()->get(SessionStore::class)->countForUser($user->id));

        // "Sign out other devices" and the session list work on Redis.
        self::assertStringContainsString('198.51.100.20', $this->get('/account/security')->body());
        $this->post('/logout');
        $this->assertRedirect($this->get('/dashboard'), '/login');
        self::assertSame(0, $this->app->container()->get(SessionStore::class)->countForUser($user->id));

        // The account lockout counts in Redis.
        $this->app->container()->get(SettingsRepository::class)->set('auth.max_login_attempts', 3);
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['email' => 'redis@example.test', 'password' => 'wrong-password-' . $i]);
        }
        $page = $this->followRedirect($this->post('/login', ['email' => 'redis@example.test', 'password' => 'Valid-Password-123']));
        self::assertStringContainsString('Too many failed sign-in attempts', $page->body());
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_rate_limits WHERE key_hash = ?', [hash('sha256', 'login:user:' . $user->id)]));
        self::assertGreaterThanOrEqual(4, (int) $this->redis->client()->get($this->prefix . 'rl:' . hash('sha256', 'login:user:' . $user->id)));
    }

    public function testTheContainerPicksTheConfiguredDrivers(): void
    {
        $app = Application::create(dirname(__DIR__, 2), [
            'session' => ['driver' => 'redis'], 'rate_limit' => ['driver' => 'redis'],
            'redis' => ['url' => (string) getenv('TEST_REDIS_URL'), 'prefix' => $this->prefix],
        ], false);
        $backend = (new \ReflectionProperty(SessionStore::class, 'backend'))->getValue($app->container()->get(SessionStore::class));
        self::assertInstanceOf(RedisSessionBackend::class, $backend);
        self::assertInstanceOf(RedisRateLimitStore::class, $app->container()->get(RateLimitStore::class));

        $default = Application::create(dirname(__DIR__, 2), [], false);
        self::assertInstanceOf(\Nebrix\Security\DatabaseRateLimitStore::class, $default->container()->get(RateLimitStore::class));

        $wrong = Application::create(dirname(__DIR__, 2), ['session' => ['driver' => 'memcached']], false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown driver "memcached"');
        $wrong->container()->get(SessionStore::class);
    }
}
