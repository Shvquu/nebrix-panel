<?php

declare(strict_types=1);

namespace Nebrix\Tests\Integration\Redis;

use Nebrix\Core\Clock;
use Nebrix\Redis\RedisConnection;
use Nebrix\Security\RateLimiter;
use Nebrix\Security\RedisRateLimitStore;
use Nebrix\Session\RedisSessionBackend;
use Nebrix\Session\SessionStore;
use PHPUnit\Framework\TestCase;

/**
 * Sessions and rate limits in a real Redis server.
 *
 * Runs only when TEST_REDIS_URL points to a Redis server (CI starts one);
 * every run uses its own key prefix and removes its keys afterwards.
 */
final class RedisStoresTest extends TestCase
{
    private RedisConnection $redis;

    private string $prefix;

    protected function setUp(): void
    {
        $url = (string) getenv('TEST_REDIS_URL');
        if ($url === '') {
            self::markTestSkipped('TEST_REDIS_URL is not set (no Redis server for this run).');
        }
        $this->prefix = 'nebrix-test-' . bin2hex(random_bytes(4)) . ':';
        $this->redis = new RedisConnection($url, $this->prefix);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $client = $this->redis->client();
            $keys = (array) $client->keys($this->prefix . '*');
            if ($keys !== []) {
                $client->del($keys);
            }
        }
    }

    /** @return list<string> */
    private function keys(): array
    {
        $keys = array_map('strval', (array) $this->redis->client()->keys($this->prefix . '*'));
        sort($keys);

        return $keys;
    }

    public function testRateLimitsCountAtomicallyAndExpire(): void
    {
        $limiter = new RateLimiter(new RedisRateLimitStore($this->redis));
        $key = 'login:user:42';

        self::assertTrue($limiter->attempt($key, 3, 600));
        self::assertTrue($limiter->attempt($key, 3, 600));
        self::assertTrue($limiter->attempt($key, 3, 600));
        self::assertFalse($limiter->attempt($key, 3, 600), 'The fourth attempt is over the limit.');
        self::assertSame(4, $limiter->attempts($key));
        self::assertTrue($limiter->tooManyAttempts($key, 3));
        self::assertGreaterThan(590, $limiter->availableIn($key));
        self::assertLessThanOrEqual(600, $limiter->availableIn($key));

        // Stored under the hash only (keys can contain e-mail addresses and IPs).
        self::assertSame([$this->prefix . 'rl:' . hash('sha256', $key)], $this->keys());

        $limiter->release($key);
        self::assertSame(3, $limiter->attempts($key));
        $limiter->clear($key);
        self::assertSame([0, 0], [$limiter->attempts($key), $limiter->availableIn($key)]);
        $limiter->release($key);
        self::assertSame(0, $limiter->attempts($key), 'Never below zero.');
        self::assertSame([], $this->keys(), 'Releasing a missing counter creates nothing.');

        // A window ends with its TTL.
        self::assertSame(1, $limiter->hit('short', 1));
        usleep(2_100_000);
        self::assertSame(0, $limiter->attempts('short'));
        self::assertSame(1, $limiter->hit('short', 1), 'A new window starts at 1.');
    }

    public function testParallelClientsCannotPassALimitTogether(): void
    {
        $limiters = [];
        for ($i = 0; $i < 5; $i++) {
            $limiters[] = new RateLimiter(new RedisRateLimitStore(new RedisConnection((string) getenv('TEST_REDIS_URL'), $this->prefix)));
        }
        $allowed = 0;
        for ($round = 0; $round < 4; $round++) {
            foreach ($limiters as $limiter) {
                $allowed += $limiter->attempt('api:user:7', 10, 60) ? 1 : 0;
            }
        }
        self::assertSame(10, $allowed);
        self::assertSame(20, $limiters[0]->attempts('api:user:7'));
    }

    public function testSessionsRoundTripExpireAndAreNeverResurrected(): void
    {
        $clock = new Clock();
        $store = new SessionStore(new RedisSessionBackend($this->redis, 1800, 43200), $clock, 1800, 43200);

        $session = $store->create();
        $session->put('cart', ['product' => 5]);
        $session->setUserId(42);
        $store->save($session, '203.0.113.7', 'Browser/1.0');
        $loaded = $store->load($session->id());
        self::assertNotNull($loaded);
        self::assertSame([42, ['product' => 5]], [$loaded->userId(), $loaded->get('cart')]);
        $hashKey = $this->prefix . 'session:' . SessionStore::hashId($session->id());
        self::assertContains($hashKey, $this->keys(), 'Only the hash of the ID is used.');
        self::assertStringNotContainsString($session->id(), implode(',', $this->keys()));
        $ttl = (int) $this->redis->client()->ttl($hashKey);
        self::assertGreaterThan(1790, $ttl);
        self::assertLessThanOrEqual(1800, $ttl, 'Expires after the idle timeout.');

        // Revoked while a request ran: the request cannot bring it back.
        $store->destroy($session->id());
        $loaded->put('cart', ['product' => 6]);
        $store->save($loaded, '203.0.113.7', 'Browser/1.0');
        self::assertNull($store->load($session->id()));
        self::assertNotContains($hashKey, $this->keys());

        // Regeneration replaces the ID; a revoked session stays revoked under the new ID too.
        $second = $store->create();
        $second->setUserId(42);
        $store->save($second, '203.0.113.7', 'Browser/1.0');
        $reloaded = $store->load($second->id());
        self::assertNotNull($reloaded);
        $reloaded->regenerate();
        $store->save($reloaded, '203.0.113.7', 'Browser/1.0');
        self::assertNull($store->load($second->id()));
        self::assertNotNull($store->load($reloaded->id()));
        $stale = $store->load($reloaded->id());
        self::assertNotNull($stale);
        $store->destroy($reloaded->id());
        $stale->regenerate();
        $store->save($stale, '203.0.113.7', 'Browser/1.0');
        self::assertNull($store->load($stale->id()));
    }

    public function testSignOutEverywhereAndTheSessionList(): void
    {
        $store = new SessionStore(new RedisSessionBackend($this->redis, 1800, 43200), new Clock(), 1800, 43200);
        $ids = [];
        foreach (['Phone', 'Laptop', 'Tablet'] as $agent) {
            $session = $store->create();
            $session->setUserId(7);
            $session->put('x', $agent);
            $store->save($session, '198.51.100.1', $agent);
            $ids[$agent] = $session->id();
        }
        $other = $store->create();
        $other->setUserId(8);
        $other->put('x', 'y');
        $store->save($other, '198.51.100.2', 'Other');

        self::assertSame(3, $store->countForUser(7));
        $list = $store->listForUser(7);
        self::assertCount(3, $list);
        self::assertEqualsCanonicalizing(['Phone', 'Laptop', 'Tablet'], array_column($list, 'user_agent'));
        self::assertContains(SessionStore::hashId($ids['Laptop']), array_column($list, 'id'));

        self::assertSame(2, $store->destroyForUser(7, $ids['Laptop']));
        self::assertNull($store->load($ids['Phone']));
        self::assertNotNull($store->load($ids['Laptop']), 'The current session is kept.');
        self::assertNotNull($store->load($other->id()), 'Other users are not affected.');
        self::assertSame(1, $store->countForUser(7));

        // Signing out (user removed from the session) takes it out of the user's list.
        $laptop = $store->load($ids['Laptop']);
        self::assertNotNull($laptop);
        $laptop->setUserId(null);
        $store->save($laptop, '198.51.100.1', 'Laptop');
        self::assertSame(0, $store->countForUser(7));
    }

    public function testTheAbsoluteLifetimeCapsTheTtl(): void
    {
        $store = new SessionStore(new RedisSessionBackend($this->redis, 3600, 120), new Clock(), 3600, 120);
        $session = $store->create();
        $session->put('a', 1);
        $store->save($session, '127.0.0.1', 'x');
        $ttl = (int) $this->redis->client()->ttl($this->prefix . 'session:' . SessionStore::hashId($session->id()));
        self::assertLessThanOrEqual(120, $ttl, 'Never longer than the absolute lifetime.');
    }
}
