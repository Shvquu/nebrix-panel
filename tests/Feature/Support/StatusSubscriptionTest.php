<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature\Support;

use DateTimeImmutable;
use Nebrix\Auth\Role;
use Nebrix\Security\RateLimiter;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Status\StatusSubscriptions;
use Nebrix\Tests\FeatureTestCase;

/**
 * Status page e-mail subscriptions: double opt-in with hashed, expiring
 * tokens, no address enumeration, updates for subscribers and one-click
 * unsubscribe (List-Unsubscribe).
 */
final class StatusSubscriptionTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-12-01 10:00:00'));
    }

    private function subscribe(string $email): string
    {
        $this->cookies = [];
        $this->get('/status/subscribe');
        $response = $this->post('/status/subscribe', ['email' => $email]);
        self::assertSame(200, $response->status());

        return $response->body();
    }

    private function confirmToken(): string
    {
        $mail = $this->lastMail('status_subscription_confirm');
        self::assertNotNull($mail);
        self::assertSame(1, preg_match('#/status/subscribe/confirm/([a-f0-9]{64})#', $mail['text'], $m));

        return $m[1];
    }

    public function testDoubleOptInUpdatesAndOneClickUnsubscribe(): void
    {
        self::assertStringContainsString('/status/subscribe', $this->get('/status')->body());
        $page = $this->subscribe('Reader@Example.test');
        self::assertStringContainsString('you will get an e-mail with a confirmation link', $page);
        $token = $this->confirmToken();
        $row = (array) $this->db->fetchOne('SELECT * FROM `nx_status_subscribers`');
        self::assertSame(['reader@example.test', hash('sha256', $token), null], [$row['email'], $row['confirm_hash'], $row['confirmed_at']], 'Only the hash of the token is stored.');
        self::assertSame('2026-12-03 10:00:00', $row['confirm_expires_at']);

        // Not active before the confirmation: no updates.
        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/status/incidents', ['type' => 'incident', 'title' => 'API errors', 'impact' => 'minor', 'status' => 'investigating', 'message' => 'Looking into it.', 'is_published' => '1']);
        self::assertNull($this->lastMail('status_subscriber_update'));

        // Opening the link only shows a button (mail scanners change nothing).
        $this->cookies = [];
        self::assertStringContainsString('Please confirm that you want to receive e-mails', $this->get('/status/subscribe/confirm/' . $token)->body());
        self::assertNull($this->db->fetchValue('SELECT `confirmed_at` FROM `nx_status_subscribers`'));
        self::assertSame(200, $this->post('/status/subscribe/confirm', ['token' => $token])->status());
        self::assertNotNull($this->db->fetchValue('SELECT `confirmed_at` FROM `nx_status_subscribers`'));
        self::assertSame(404, $this->post('/status/subscribe/confirm', ['token' => $token])->status(), 'Single use.');

        // Subscribing again changes nothing and sends nothing – the answer is the same.
        $mails = (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_jobs` WHERE `type` = 'mail.send'");
        self::assertStringContainsString('you will get an e-mail with a confirmation link', $this->subscribe('reader@example.test'));
        self::assertSame($mails, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_jobs` WHERE `type` = 'mail.send'"));

        // New incidents and staff updates are e-mailed with a one-click unsubscribe link.
        $this->actingAs($this->createUser(Role::SUPPORT));
        $this->post('/admin/status/incidents', ['type' => 'maintenance', 'title' => 'Router <b>swap</b>', 'status' => 'scheduled', 'starts_at' => '2026-12-05T02:00', 'ends_at' => '2026-12-05T03:00', 'message' => 'Short interruptions.', 'is_published' => '1']);
        $mail = $this->lastMail('status_subscriber_update');
        self::assertNotNull($mail);
        self::assertSame('reader@example.test', $mail['to']);
        self::assertSame('Maintenance: Router <b>swap</b>', $mail['subject']);
        self::assertStringContainsString('Router &lt;b&gt;swap&lt;/b&gt;', $mail['html']);
        $unsubscribe = (string) ($mail['headers']['list_unsubscribe'] ?? '');
        self::assertMatchesRegularExpression('#^http://localhost/status/unsubscribe/\d+/[a-f0-9]{64}$#', $unsubscribe);
        self::assertStringContainsString($unsubscribe, $mail['html']);
        $incident = (int) $this->db->fetchValue("SELECT `id` FROM `nx_status_incidents` WHERE `type` = 'maintenance'");
        $this->post('/admin/status/incidents/' . $incident . '/updates', ['status' => 'in_progress', 'message' => 'Started.']);
        self::assertSame('Started.', $this->extractMessage());

        // The automatic start/end of maintenance does not mail subscribers.
        $before = (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_jobs` WHERE `type` = 'mail.send'");
        $this->clock()->freeze(new DateTimeImmutable('2026-12-05 04:00:00'));
        $this->app->container()->get(\Nebrix\Status\StatusService::class)->advanceMaintenance();
        self::assertSame($before, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_jobs` WHERE `type` = 'mail.send'"));

        // One-click unsubscribe (RFC 8058) without a session or CSRF token; tampered links fail.
        $path = (string) parse_url($unsubscribe, PHP_URL_PATH);
        $this->cookies = [];
        self::assertStringContainsString('Unsubscribe from status updates', $this->get($path)->body());
        self::assertSame(404, $this->post(substr($path, 0, -1) . (str_ends_with($path, 'a') ? 'b' : 'a'), ['List-Unsubscribe' => 'One-Click'], false)->status());
        $id = (int) explode('/', $path)[3];
        self::assertSame(404, $this->post(str_replace('/' . $id . '/', '/' . ($id + 1) . '/', $path), ['List-Unsubscribe' => 'One-Click'], false)->status());
        $response = $this->postRaw($path, 'List-Unsubscribe=One-Click', ['Content-Type' => 'application/x-www-form-urlencoded']);
        self::assertSame(200, $response->status());
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_status_subscribers`'), 'The address is deleted.');
        self::assertSame(404, $this->post($path, [], false)->status());
    }

    private function extractMessage(): string
    {
        $mail = $this->lastMail('status_subscriber_update');
        self::assertNotNull($mail);
        self::assertSame(1, preg_match('#<p>(Started\.)</p>#', $mail['html'], $m));

        return $m[1];
    }

    public function testTokensExpireAndRequestsAreLimited(): void
    {
        $this->subscribe('late@example.test');
        $token = $this->confirmToken();
        $this->clock()->freeze(new DateTimeImmutable('2026-12-03 10:00:01'));
        self::assertStringContainsString('This link is invalid or has expired.', $this->get('/status/subscribe/confirm/' . $token)->body());
        self::assertSame(404, $this->post('/status/subscribe/confirm', ['token' => $token])->status());
        self::assertSame(1, $this->app->container()->get(StatusSubscriptions::class)->purgeExpired());
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_status_subscribers`'));

        // At most 3 confirmation e-mails per address and day.
        for ($i = 0; $i < 4; $i++) {
            $this->subscribe('flood@example.test');
        }
        self::assertSame(1 + 3, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_jobs` WHERE `type` = 'mail.send' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.template')) = 'status_subscription_confirm'"), 'late@ once, flood@ three times.');

        // Per network: 10 subscription requests per hour.
        $limiter = $this->app->container()->get(RateLimiter::class);
        for ($i = 0; $i < 10; $i++) {
            $limiter->hit('status-subscribe:' . RateLimiter::network($this->clientIp, 48), 3600);
        }
        $this->cookies = [];
        self::assertSame(429, $this->post('/status/subscribe', ['email' => 'other@example.test'])->status());

        // Invalid input and the switch.
        $this->clientIp = '198.51.100.99';
        $this->cookies = [];
        $this->get('/status/subscribe');
        $this->post('/status/subscribe', ['email' => 'not-an-address']);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_status_subscribers` WHERE `email` = 'not-an-address'"));
        $this->app->container()->get(SettingsRepository::class)->set('notifications.status_subscriptions', false);
        self::assertSame(404, $this->get('/status/subscribe')->status());
        self::assertStringNotContainsString('/status/subscribe', $this->get('/status')->body());
    }
}
