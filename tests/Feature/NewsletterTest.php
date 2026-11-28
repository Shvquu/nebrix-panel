<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Nebrix\Auth\Role;
use Nebrix\Mail\Mailer;
use Nebrix\Marketing\Newsletter;
use Nebrix\Marketing\NewsletterCampaigns;
use Nebrix\Queue\Worker;
use Nebrix\Security\Encrypter;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\FakeHttp;
use Nebrix\Tests\Support\MarketingTestHelpers;

/**
 * Newsletter: double opt-in with hashed, expiring tokens and proof of
 * consent, identical answers for every address, rate limits and CAPTCHA,
 * unsubscribe links with RFC 8058 one-click headers, the customer account,
 * the subscriber administration and campaigns sent in throttled batches
 * through the job queue – never to unconfirmed addresses, never twice.
 */
final class NewsletterTest extends FeatureTestCase
{
    use MarketingTestHelpers;

    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = $this->app->container()->get(SettingsRepository::class);
        $this->settings->set('newsletter.enabled', true);
        $this->clock()->freeze(new DateTimeImmutable('2026-12-10 12:00:00', new DateTimeZone('UTC')));
    }

    private function subscriber(string $email, string $status = Newsletter::CONFIRMED, string $locale = 'en'): int
    {
        return $this->db->insert('nx_newsletter_subscribers', [
            'email' => $email, 'locale' => $locale, 'status' => $status, 'source' => 'website', 'requested_at' => '2026-12-01 10:00:00', 'requested_ip' => '203.0.113.0',
            'confirmed_at' => $status === Newsletter::PENDING ? null : '2026-12-01 10:05:00', 'confirmed_ip' => $status === Newsletter::PENDING ? null : '203.0.113.0',
            'unsubscribed_at' => $status === Newsletter::UNSUBSCRIBED ? '2026-12-05 10:00:00' : null,
            'created_at' => '2026-12-01 10:00:00', 'updated_at' => '2026-12-01 10:00:00',
        ]);
    }

    private function mailCount(string $template): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_jobs WHERE type = ? AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.template')) = ?", [Mailer::JOB_TYPE, $template]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decryptJob(int $jobId): array
    {
        $payload = json_decode((string) $this->db->fetchValue('SELECT payload FROM nx_jobs WHERE id = ?', [$jobId]), true);

        return $this->app->container()->get(Encrypter::class)->decryptArray((string) $payload['message'], 'mail-job');
    }

    public function testDoubleOptInStoresProofAndConfirmsOnce(): void
    {
        $page = $this->get('/newsletter');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('action="/newsletter"', $page->body());
        self::assertStringContainsString('I would like to receive the newsletter of Nebrix', $page->body());

        $response = $this->post('/newsletter', ['email' => 'Ada@Example.test']);
        $this->assertRedirect($response, '/newsletter');
        self::assertStringContainsString('If the address can receive our newsletter', $this->get('/newsletter')->body());
        $row = $this->db->fetchOne("SELECT * FROM nx_newsletter_subscribers WHERE email = 'ada@example.test'");
        self::assertNotNull($row);
        self::assertSame(Newsletter::PENDING, $row['status']);
        self::assertSame('198.51.100.0', $row['requested_ip'], 'Shortened IP address.');
        self::assertSame('2026-12-10 12:00:00', $row['requested_at']);
        self::assertSame('2026-12-12 12:00:00', $row['confirm_expires_at'], '48 hours by default.');
        self::assertStringContainsString('I would like to receive the newsletter', (string) $row['consent_text']);

        $mail = $this->lastMail('newsletter_confirm');
        self::assertNotNull($mail);
        self::assertSame('ada@example.test', $mail['to']);
        $link = $this->extractLink($mail['html'], '/newsletter/confirm/');
        $token = substr($link, strlen('/newsletter/confirm/'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame(hash('sha256', $token), $row['confirm_token_hash'], 'Only the hash is stored.');
        self::assertStringNotContainsString($token, (string) json_encode($row));

        // Pending addresses never receive campaigns.
        self::assertSame(0, $this->app->container()->get(NewsletterCampaigns::class)->audienceSize('all'));

        // The link opens a page with a button – a link scanner confirms nothing.
        $this->cookies = [];
        $confirmPage = $this->get($link);
        self::assertSame(200, $confirmPage->status());
        self::assertStringContainsString('value="' . $token . '"', $confirmPage->body());
        self::assertSame(Newsletter::PENDING, $this->db->fetchValue("SELECT status FROM nx_newsletter_subscribers WHERE email = 'ada@example.test'"));

        $this->clientIp = '2001:db8:abcd:12::99';
        $done = $this->post('/newsletter/confirm', ['token' => $token]);
        self::assertSame(200, $done->status());
        $row = $this->db->fetchOne("SELECT * FROM nx_newsletter_subscribers WHERE email = 'ada@example.test'");
        self::assertSame(Newsletter::CONFIRMED, $row['status']);
        self::assertSame('2026-12-10 12:00:00', $row['confirmed_at']);
        self::assertSame('2001:db8:abcd::', $row['confirmed_ip']);
        self::assertNull($row['confirm_token_hash']);
        self::assertSame(1, $this->app->container()->get(NewsletterCampaigns::class)->audienceSize('all'));

        // Single use.
        self::assertSame(404, $this->post('/newsletter/confirm', ['token' => $token])->status());
        self::assertSame(404, $this->get($link)->status());
        self::assertSame(404, $this->post('/newsletter/confirm', ['token' => str_repeat('a', 64)])->status());
    }

    public function testConfirmationLinksExpireAndUnconfirmedSignUpsAreRemoved(): void
    {
        $this->post('/newsletter', ['email' => 'late@example.test']);
        $link = $this->extractLink((string) $this->lastMail('newsletter_confirm')['html'], '/newsletter/confirm/');

        $this->clock()->freeze(new DateTimeImmutable('2026-12-12 12:00:01', new DateTimeZone('UTC')));
        self::assertSame(404, $this->get($link)->status());
        self::assertSame(404, $this->post('/newsletter/confirm', ['token' => substr($link, strlen('/newsletter/confirm/'))])->status());
        self::assertSame(Newsletter::PENDING, $this->db->fetchValue("SELECT status FROM nx_newsletter_subscribers WHERE email = 'late@example.test'"));

        $newsletter = $this->app->container()->get(Newsletter::class);
        self::assertSame(0, $newsletter->pruneUnconfirmed(), 'Kept a few days after the expiry.');
        $this->clock()->freeze(new DateTimeImmutable('2026-12-20 12:00:00', new DateTimeZone('UTC')));
        $this->subscriber('kept@example.test', Newsletter::UNSUBSCRIBED);
        self::assertSame(1, $newsletter->pruneUnconfirmed());
        self::assertNull($this->db->fetchValue("SELECT id FROM nx_newsletter_subscribers WHERE email = 'late@example.test'"));
        self::assertNotNull($this->db->fetchValue("SELECT id FROM nx_newsletter_subscribers WHERE email = 'kept@example.test'"));
    }

    public function testSignUpAnswersTheSameForEveryAddressAndIsLimited(): void
    {
        $this->subscriber('known@example.test');
        $known = $this->post('/newsletter', ['email' => 'known@example.test']);
        $flashKnown = $this->get('/newsletter')->body();
        $unknown = $this->post('/newsletter', ['email' => 'unknown@example.test']);
        $flashUnknown = $this->get('/newsletter')->body();
        self::assertSame($known->status(), $unknown->status());
        self::assertSame($known->header('Location'), $unknown->header('Location'));
        $message = 'Thank you! If the address can receive our newsletter';
        self::assertStringContainsString($message, $flashKnown);
        self::assertStringContainsString($message, $flashUnknown);
        self::assertSame(1, $this->mailCount('newsletter_confirm'), 'Confirmed addresses get no e-mail.');
        self::assertSame(Newsletter::CONFIRMED, $this->db->fetchValue("SELECT status FROM nx_newsletter_subscribers WHERE email = 'known@example.test'"));

        // At most three confirmation e-mails per address and day – same answer afterwards.
        foreach (range(1, 4) as $n) {
            $this->clientIp = '198.51.100.' . (100 + $n);
            $this->post('/newsletter', ['email' => 'unknown@example.test']);
        }
        self::assertSame(3, $this->mailCount('newsletter_confirm'), 'The first sign-up of the address counts as well.');

        // At most ten sign-ups per network and hour.
        $this->clientIp = '192.0.2.10';
        foreach (range(1, 10) as $n) {
            $this->post('/newsletter', ['email' => 'flood' . $n . '@example.test']);
        }
        $this->post('/newsletter', ['email' => 'flood11@example.test']);
        self::assertStringContainsString('Too many sign-ups from your network', $this->get('/newsletter')->body());
        self::assertNull($this->db->fetchValue("SELECT id FROM nx_newsletter_subscribers WHERE email = 'flood11@example.test'"));

        // Invalid input, switched off.
        $this->clientIp = '192.0.2.200';
        $this->post('/newsletter', ['email' => 'not-an-address']);
        self::assertNull($this->db->fetchValue("SELECT id FROM nx_newsletter_subscribers WHERE email = 'not-an-address'"));
        $this->settings->set('newsletter.enabled', false);
        self::assertSame(404, $this->get('/newsletter')->status());
        self::assertSame(404, $this->post('/newsletter', ['email' => 'off@example.test'])->status());
    }

    public function testCaptchaIsRequiredWhenConfigured(): void
    {
        $http = (new FakeHttp())->on('POST', '/turnstile/v0/siteverify', static function ($request): Psr7Response {
            return new Psr7Response(200, [], json_encode(['success' => FakeHttp::form($request)['response'] === 'token-ok'], JSON_THROW_ON_ERROR));
        });
        $this->app->container()->set(ClientInterface::class, static fn (): ClientInterface => $http->client());
        $this->settings->set('auth.captcha_provider', 'turnstile');
        $this->settings->set('auth.captcha_site_key', '0x4AAAAAAA-site');
        $this->settings->set('auth.captcha_secret', 'turnstile-secret');

        $page = $this->get('/newsletter');
        self::assertStringContainsString('class="cf-turnstile"', $page->body());
        self::assertStringContainsString('https://challenges.cloudflare.com', (string) $page->header('Content-Security-Policy'));
        self::assertStringNotContainsString('challenges.cloudflare.com', (string) $this->get('/')->header('Content-Security-Policy'), 'Only on the sign-up page.');

        $this->post('/newsletter', ['email' => 'bot@example.test']);
        $this->post('/newsletter', ['email' => 'bot@example.test', 'cf-turnstile-response' => 'forged']);
        self::assertNull($this->db->fetchValue("SELECT id FROM nx_newsletter_subscribers WHERE email = 'bot@example.test'"));
        $this->post('/newsletter', ['email' => 'human@example.test', 'cf-turnstile-response' => 'token-ok']);
        self::assertNotNull($this->db->fetchValue("SELECT id FROM nx_newsletter_subscribers WHERE email = 'human@example.test'"));
    }

    public function testCampaignsAreSentInThrottledBatchesOnlyToConfirmedAddressesAndNeverTwice(): void
    {
        $this->settings->set('newsletter.batch_size', 2);
        $this->settings->set('newsletter.batch_interval', 60);
        foreach (['a', 'b', 'c', 'd'] as $n) {
            $this->subscriber($n . '@example.test');
        }
        $german = $this->subscriber('de@example.test', Newsletter::CONFIRMED, 'de');
        $this->subscriber('pending@example.test', Newsletter::PENDING);
        $this->subscriber('gone@example.test', Newsletter::UNSUBSCRIBED);
        $admin = $this->createUser(Role::ADMIN, ['email' => 'staff@example.test']);
        $this->actingAs($admin);

        self::assertSame(200, $this->get('/admin/newsletter')->status());
        self::assertSame(200, $this->get('/admin/newsletter/campaigns/create')->status());
        $this->post('/admin/newsletter/campaigns', ['subject' => 'Neuigkeiten im Dezember', 'body' => "## Neu\n**Mehr** Leistung <script>alert(1)</script> [Angebote](/products)", 'audience' => 'all']);
        $id = (int) $this->db->fetchValue('SELECT id FROM nx_newsletter_campaigns');
        $edit = $this->get('/admin/newsletter/campaigns/' . $id)->body();
        self::assertStringContainsString('<h2>Neu</h2>', $edit, 'Preview of the rendered Markdown.');
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $edit);
        self::assertStringNotContainsString('<script>alert(1)', $edit);
        self::assertStringContainsString('5 confirmed subscribers', $edit);

        // Test e-mail to the own address only.
        $this->post('/admin/newsletter/campaigns/' . $id . '/test');
        $test = $this->lastMail('newsletter');
        self::assertSame('staff@example.test', $test['to']);
        self::assertSame('[Test] Neuigkeiten im Dezember', $test['subject']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_newsletter_deliveries'));

        // Sending needs the typed confirmation.
        $this->post('/admin/newsletter/campaigns/' . $id . '/send', ['confirm' => 'yes']);
        self::assertSame(NewsletterCampaigns::DRAFT, $this->db->fetchValue('SELECT status FROM nx_newsletter_campaigns WHERE id = ?', [$id]));
        $this->post('/admin/newsletter/campaigns/' . $id . '/send', ['confirm' => 'SEND']);
        $campaign = $this->db->fetchOne('SELECT * FROM nx_newsletter_campaigns WHERE id = ?', [$id]);
        self::assertSame(NewsletterCampaigns::SENDING, $campaign['status']);
        self::assertSame(5, (int) $campaign['recipients'], 'Pending and unsubscribed addresses are not recipients.');
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'newsletter.campaign_sent'"));
        // A sent campaign cannot be changed or sent again.
        $this->post('/admin/newsletter/campaigns/' . $id, ['subject' => 'Changed', 'body' => 'x', 'audience' => 'all']);
        self::assertSame('Neuigkeiten im Dezember', $this->db->fetchValue('SELECT subject FROM nx_newsletter_campaigns WHERE id = ?', [$id]));
        $this->post('/admin/newsletter/campaigns/' . $id . '/send', ['confirm' => 'SEND']);
        self::assertSame(5, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_newsletter_deliveries WHERE campaign_id = ?', [$id]));

        // First batch through the worker: two e-mails, the next batch scheduled after the interval.
        $worker = $this->app->container()->get(Worker::class);
        self::assertTrue($worker->runOnce('test'), 'Delivers the test e-mail (queued first).');
        self::assertTrue($worker->runOnce('test'), 'Runs the first batch.');
        self::assertSame(2, $this->mailCount('newsletter') - 1);
        $next = $this->db->fetchOne("SELECT * FROM nx_jobs WHERE type = 'newsletter.batch' AND status = 'pending'");
        self::assertNotNull($next);
        self::assertSame('2026-12-10 12:01:00', $next['available_at']);
        self::assertSame('newsletter-batch:' . $id . ':2', $next['unique_key']);

        // Per recipient: headers, unsubscribe link, the recipient's language.
        $jobs = $this->db->fetchAll("SELECT id, unique_key FROM nx_jobs WHERE unique_key LIKE 'newsletter-mail:%' ORDER BY id");
        $first = $this->decryptJob((int) $jobs[0]['id']);
        self::assertSame('a@example.test', $first['to']);
        self::assertSame('Neuigkeiten im Dezember', $first['subject']);
        self::assertStringContainsString('<strong>Mehr</strong>', $first['html']);
        self::assertStringContainsString('<a href="http://localhost/products">Angebote</a>', $first['html'], 'Absolute links in e-mails.');
        self::assertStringNotContainsString('<script>alert(1)', $first['html']);
        $unsubscribe = $this->extractLink($first['html'], '/newsletter/unsubscribe/');
        self::assertSame(['list_unsubscribe' => 'http://localhost' . $unsubscribe], $first['headers']);
        self::assertSame(hash('sha256', substr($unsubscribe, strlen('/newsletter/unsubscribe/'))), $this->db->fetchValue("SELECT unsubscribe_token_hash FROM nx_newsletter_deliveries d JOIN nx_newsletter_subscribers s ON s.id = d.subscriber_id WHERE s.email = 'a@example.test'"));

        // Throttled: nothing more before the interval (the worker only delivers the two e-mails).
        $worker->runOnce('test');
        $worker->runOnce('test');
        self::assertFalse($worker->runOnce('test'));
        self::assertSame(2, (int) $this->db->fetchValue('SELECT queued_count FROM nx_newsletter_campaigns WHERE id = ?', [$id]));

        // Somebody unsubscribes in the meantime: skipped.
        $this->db->update('nx_newsletter_subscribers', ['status' => Newsletter::UNSUBSCRIBED], ['email' => 'd@example.test']);
        // A duplicate batch job (e.g. a retry) queues no second e-mail.
        $this->app->container()->get(NewsletterCampaigns::class)->processBatch($id, 1);
        $this->clock()->freeze(new DateTimeImmutable('2026-12-10 12:01:01', new DateTimeZone('UTC')));
        for ($n = 0; $n < 20 && $worker->runOnce('test'); $n++) {
            $this->clock()->freeze($this->clock()->now()->modify('+61 seconds'));
        }
        for ($n = 0; $n < 20 && $worker->runOnce('test'); $n++) {
            $this->clock()->freeze($this->clock()->now()->modify('+61 seconds'));
        }

        $campaign = $this->db->fetchOne('SELECT * FROM nx_newsletter_campaigns WHERE id = ?', [$id]);
        self::assertSame(NewsletterCampaigns::SENT, $campaign['status']);
        self::assertSame(4, (int) $campaign['queued_count']);
        self::assertSame(1, (int) $campaign['skipped_count']);
        self::assertSame(4, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_jobs WHERE unique_key LIKE ?", ['newsletter-mail:' . $id . ':%']), 'One e-mail per recipient.');
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_jobs WHERE unique_key = ?", ['newsletter-mail:' . $id . ':' . (int) $this->db->fetchValue("SELECT id FROM nx_newsletter_subscribers WHERE email = 'd@example.test'")]));
        self::assertSame(['delivered' => 4, 'waiting' => 0, 'failed' => 0], $this->app->container()->get(NewsletterCampaigns::class)->mailStats($id));
        self::assertStringContainsString('<td class="text-right">4</td>', $this->get('/admin/newsletter')->body());
        unset($german);
    }

    public function testStoppedCampaignsSendNothingMoreAndKeepTheirUnsubscribeLinks(): void
    {
        $this->settings->set('newsletter.batch_size', 1);
        foreach (['a', 'b', 'c'] as $n) {
            $this->subscriber($n . '@example.test');
        }
        $this->actingAs($this->createUser(Role::ADMIN));
        $campaigns = $this->app->container()->get(NewsletterCampaigns::class);
        $id = $campaigns->save(null, ['subject' => 'Hallo', 'body' => 'Text', 'audience' => 'all'], 1);
        $campaigns->start($id, 1);
        $campaigns->processBatch($id, 1);
        $this->post('/admin/newsletter/campaigns/' . $id . '/cancel');
        self::assertSame(NewsletterCampaigns::CANCELLED, $this->db->fetchValue('SELECT status FROM nx_newsletter_campaigns WHERE id = ?', [$id]));
        $campaigns->processBatch($id, 2);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_jobs WHERE unique_key LIKE 'newsletter-mail:%'"));

        $this->post('/admin/newsletter/campaigns/' . $id . '/delete');
        self::assertNotNull($this->db->fetchValue('SELECT id FROM nx_newsletter_campaigns WHERE id = ?', [$id]), 'Sent e-mails keep their unsubscribe links.');
        $mail = $this->decryptJob((int) $this->db->fetchValue("SELECT id FROM nx_jobs WHERE unique_key LIKE 'newsletter-mail:%'"));
        self::assertSame(200, $this->get($this->extractLink($mail['html'], '/newsletter/unsubscribe/'))->status());
    }

    public function testUnsubscribeLinksWorkOnceAndWithOneClick(): void
    {
        $a = $this->subscriber('a@example.test');
        $b = $this->subscriber('b@example.test', Newsletter::CONFIRMED, 'de');
        $campaigns = $this->app->container()->get(NewsletterCampaigns::class);
        $id = $campaigns->save(null, ['subject' => 'Hallo', 'body' => 'Text', 'audience' => 'all'], $this->createUser(Role::ADMIN)->id);
        $campaigns->start($id, 1);
        $campaigns->processBatch($id, 1);
        $links = [];
        foreach ($this->db->fetchAll("SELECT id FROM nx_jobs WHERE unique_key LIKE 'newsletter-mail:%' ORDER BY id") as $job) {
            $mail = $this->decryptJob((int) $job['id']);
            $links[$mail['to']] = $this->extractLink($mail['html'], '/newsletter/unsubscribe/');
            if ($mail['to'] === 'b@example.test') {
                self::assertStringContainsString('>Abmelden</a>', $mail['html'], 'The frame in the language of the subscriber.');
            }
        }

        // The link opens a page with a button; nothing changes on GET.
        $page = $this->get($links['a@example.test']);
        self::assertSame(200, $page->status());
        self::assertStringContainsString('noindex, nofollow', $page->body());
        self::assertSame(Newsletter::CONFIRMED, $this->db->fetchValue('SELECT status FROM nx_newsletter_subscribers WHERE id = ?', [$a]));
        $this->cookies = [];
        self::assertSame(200, $this->post($links['a@example.test'], [], false)->status());
        $row = $this->db->fetchOne('SELECT * FROM nx_newsletter_subscribers WHERE id = ?', [$a]);
        self::assertSame(Newsletter::UNSUBSCRIBED, $row['status']);
        self::assertSame('2026-12-10 12:00:00', $row['unsubscribed_at']);

        // RFC 8058 one-click (no session, no CSRF token) – also while the newsletter is switched off.
        $this->settings->set('newsletter.enabled', false);
        $oneClick = $this->post($links['b@example.test'], ['List-Unsubscribe' => 'One-Click'], false);
        self::assertSame(200, $oneClick->status());
        self::assertSame('OK', $oneClick->body());
        self::assertSame(Newsletter::UNSUBSCRIBED, $this->db->fetchValue('SELECT status FROM nx_newsletter_subscribers WHERE id = ?', [$b]));

        // Unknown and malformed tokens.
        self::assertSame(404, $this->get('/newsletter/unsubscribe/' . str_repeat('f', 64))->status());
        self::assertSame(404, $this->post('/newsletter/unsubscribe/' . str_repeat('f', 64), ['List-Unsubscribe' => 'One-Click'], false)->status());
        self::assertSame(404, $this->get('/newsletter/unsubscribe/not-a-token')->status());
        // Unsubscribed addresses are no recipients any more.
        self::assertSame(0, $campaigns->audienceSize('all'));
    }

    public function testDeliveredNewslettersCarryTheUnsubscribeHeaders(): void
    {
        $portFile = (string) tempnam(sys_get_temp_dir(), 'smtp');
        $messageFile = (string) tempnam(sys_get_temp_dir(), 'mail');
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/smtp_capture_server.php', $portFile, $messageFile], [], $pipes);
        self::assertIsResource($process);
        try {
            for ($i = 0; $i < 50 && (string) file_get_contents($portFile) === ''; $i++) {
                usleep(100_000);
            }
            foreach (['mail.enabled' => true, 'mail.host' => '127.0.0.1', 'mail.port' => (int) file_get_contents($portFile), 'mail.encryption' => 'none', 'mail.from_address' => 'news@example.test'] as $key => $value) {
                $this->settings->set($key, $value);
            }
            $this->subscriber('reader@example.test');
            $campaigns = $this->app->container()->get(NewsletterCampaigns::class);
            $id = $campaigns->save(null, ['subject' => 'Hello', 'body' => 'Text', 'audience' => 'all'], $this->createUser(Role::ADMIN)->id);
            $campaigns->start($id, 1);
            $campaigns->processBatch($id, 1);
            $payload = json_decode((string) $this->db->fetchValue("SELECT payload FROM nx_jobs WHERE unique_key LIKE 'newsletter-mail:%'"), true);
            $this->app->container()->get(Mailer::class)->sendQueued($payload);
            for ($i = 0; $i < 50 && (string) file_get_contents($messageFile) === ''; $i++) {
                usleep(100_000);
            }
            $message = (string) file_get_contents($messageFile);
        } finally {
            proc_terminate($process);
            proc_close($process);
            @unlink($portFile);
            @unlink($messageFile);
        }

        $headers = (string) preg_replace('/\r\n[ \t]+/', ' ', explode("\r\n\r\n", $message, 2)[0]);
        self::assertMatchesRegularExpression('#^List-Unsubscribe: <http://localhost/newsletter/unsubscribe/[a-f0-9]{64}>$#m', str_replace("\r", '', $headers));
        self::assertStringContainsString("List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n", $headers . "\r\n");
        self::assertStringContainsString('To: reader@example.test', $headers);
    }

    public function testCustomersSubscribeAndUnsubscribeInTheirAccount(): void
    {
        $customer = $this->createUser(Role::CUSTOMER, ['email' => 'kunde@example.test', 'language' => 'de']);
        $this->actingAs($customer);
        $page = $this->get('/account/communication');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('name="newsletter" value="1">', $page->body(), 'Not ticked in advance.');

        // A signed-in customer with a verified address: confirmed at once, with proof.
        $this->assertRedirect($this->post('/account/communication', ['newsletter_shown' => '1', 'newsletter' => '1']), '/account/communication');
        $row = $this->db->fetchOne("SELECT * FROM nx_newsletter_subscribers WHERE email = 'kunde@example.test'");
        self::assertSame(Newsletter::CONFIRMED, $row['status']);
        self::assertSame('account', $row['source']);
        self::assertSame($customer->id, (int) $row['user_id']);
        self::assertSame('de', $row['locale']);
        self::assertSame('198.51.100.0', $row['confirmed_ip']);
        self::assertStringContainsString('name="newsletter" value="1" checked', $this->get('/account/communication')->body());

        $this->post('/account/communication', ['newsletter_shown' => '1']);
        self::assertSame(Newsletter::UNSUBSCRIBED, $this->db->fetchValue("SELECT status FROM nx_newsletter_subscribers WHERE email = 'kunde@example.test'"));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'newsletter.unsubscribed'"));

        // Without a verified address the customer gets the confirmation e-mail instead.
        $this->settings->set('auth.require_email_verification', false);
        $unverified = $this->createUser(Role::CUSTOMER, ['email' => 'neu@example.test', 'email_verified_at' => null]);
        $this->actingAs($unverified);
        $this->post('/account/communication', ['newsletter_shown' => '1', 'newsletter' => '1']);
        self::assertSame(Newsletter::PENDING, $this->db->fetchValue("SELECT status FROM nx_newsletter_subscribers WHERE email = 'neu@example.test'"));
        self::assertSame('neu@example.test', $this->lastMail('newsletter_confirm')['to']);
    }

    public function testSubscriberAdministrationPermissionsExportAndDeletion(): void
    {
        $id = $this->subscriber('=HYPERLINK("http://evil")@example.test');
        $this->subscriber('other@example.test', Newsletter::PENDING);
        $campaigns = $this->app->container()->get(NewsletterCampaigns::class);
        $campaign = $campaigns->save(null, ['subject' => 'Hi', 'body' => 'Text', 'audience' => 'all'], $this->createUser(Role::ADMIN)->id);

        $this->actingAs($this->createUser());
        self::assertSame(403, $this->get('/admin/newsletter/subscribers')->status());

        $this->actingAs($this->staffWith(['newsletter.view']));
        $list = $this->get('/admin/newsletter/subscribers', ['status' => 'pending']);
        self::assertSame(200, $list->status());
        self::assertStringContainsString('other@example.test', $list->body());
        self::assertStringNotContainsString('HYPERLINK', $list->body(), 'Filtered by status.');
        self::assertStringContainsString('HYPERLINK', $this->get('/admin/newsletter/subscribers', ['search' => 'hyperlink'])->body());
        self::assertSame(403, $this->get('/admin/newsletter/subscribers/export')->status());
        self::assertSame(403, $this->post('/admin/newsletter/subscribers/' . $id . '/delete')->status());
        self::assertSame(200, $this->get('/admin/newsletter/campaigns/' . $campaign)->status());
        self::assertSame(403, $this->post('/admin/newsletter/campaigns/' . $campaign . '/send', ['confirm' => 'SEND'])->status());
        self::assertSame(403, $this->post('/admin/newsletter/campaigns/' . $campaign . '/test')->status());
        self::assertSame(NewsletterCampaigns::DRAFT, $this->db->fetchValue('SELECT status FROM nx_newsletter_campaigns WHERE id = ?', [$campaign]));

        $this->actingAs($this->staffWith(['newsletter.view', 'newsletter.export']));
        $export = $this->get('/admin/newsletter/subscribers/export');
        self::assertSame(200, $export->status());
        self::assertStringStartsWith('text/csv', (string) $export->header('Content-Type'));
        self::assertStringStartsWith('attachment', (string) $export->header('Content-Disposition'));
        $csv = $export->body();
        self::assertStringContainsString('email,status,language,source,requested_at,requested_ip,confirmed_at,confirmed_ip', $csv);
        self::assertStringContainsString("\"'=HYPERLINK(\"\"http://evil\"\")@example.test\"", $csv, 'Formulas are neutralised.');
        self::assertStringContainsString('"2026-12-01 10:05:00",203.0.113.0', $csv, 'With the proof of consent.');
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'newsletter.exported'"));

        $this->actingAs($this->staffWith(['newsletter.view', 'newsletter.manage']));
        $this->assertRedirect($this->post('/admin/newsletter/subscribers/' . $id . '/delete'), '/admin/newsletter/subscribers');
        self::assertNull($this->db->fetchValue('SELECT id FROM nx_newsletter_subscribers WHERE id = ?', [$id]));
        $log = (string) $this->db->fetchValue("SELECT details FROM nx_audit_log WHERE action = 'newsletter.subscriber_deleted'");
        self::assertStringNotContainsString('HYPERLINK', $log, 'The deleted address is not kept in the audit log.');
        $this->assertRedirect($this->post('/admin/newsletter/campaigns/' . $campaign . '/delete'), '/admin/newsletter');
        self::assertNull($this->db->fetchValue('SELECT id FROM nx_newsletter_campaigns WHERE id = ?', [$campaign]));
    }
}
