<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Marketing\Consents;
use Nebrix\Marketing\ReviewInvitations;
use Nebrix\Scheduler\Scheduler;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\MarketingTestHelpers;

/**
 * Review invitations (§ 7 UWG): only with explicit consent (unticked
 * checkbox at registration and order, toggle in the account), one e-mail
 * per customer N days after the first service became active, opt-out link
 * and one-click headers, consent withdrawable.
 */
final class ReviewInvitationTest extends FeatureTestCase
{
    use MarketingTestHelpers;

    private const REVIEW_URL = 'https://de.trustpilot.com/evaluate/nebrix.test';

    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = $this->app->container()->get(SettingsRepository::class);
        $this->clock()->freeze(new DateTimeImmutable('2026-12-10 12:00:00', new DateTimeZone('UTC')));
    }

    private function enable(): void
    {
        $this->settings->set('reviews.enabled', true);
        $this->settings->set('reviews.review_url', self::REVIEW_URL);
        $this->settings->set('reviews.delay_days', 14);
    }

    private function service(User $user, string $provisionedAt, string $status = 'active'): int
    {
        return $this->db->insert('nx_services', [
            'user_id' => $user->id, 'category' => 'teamspeak', 'name' => 'TS', 'status' => $status, 'billing_cycle' => 'monthly', 'recurring_amount' => 199,
            'currency' => 'EUR', 'provisioned_at' => $provisionedAt, 'created_at' => $provisionedAt, 'updated_at' => $provisionedAt,
        ]);
    }

    private function consent(User $user): void
    {
        $this->app->container()->get(Consents::class)->grant($user->id, Consents::REVIEW_INVITATION, 'account', '203.0.113.5', 'consent text');
    }

    private function invitations(): ReviewInvitations
    {
        return $this->app->container()->get(ReviewInvitations::class);
    }

    public function testNothingIsSentWithoutConsentOrWhileSwitchedOff(): void
    {
        $customer = $this->createUser();
        $this->service($customer, '2026-11-01 10:00:00');
        $this->consent($customer);
        self::assertSame(0, $this->invitations()->sendDue(), 'Off by default.');

        $this->enable();
        $this->settings->set('reviews.review_url', '');
        self::assertSame(0, $this->invitations()->sendDue(), 'Without a review link nothing is sent.');

        $this->enable();
        $other = $this->createUser();
        $this->service($other, '2026-11-01 10:00:00');
        self::assertSame(1, $this->invitations()->sendDue(), 'Only the customer who agreed.');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_review_invitations WHERE user_id = ?', [$other->id]));
    }

    public function testOneInvitationAfterTheDelayWithOptOutLinkAndHeaders(): void
    {
        $this->enable();
        $customer = $this->createUser(Role::CUSTOMER, ['language' => 'de', 'first_name' => 'Erika']);
        $serviceId = $this->service($customer, '2026-12-01 09:00:00');
        $this->service($customer, '2026-12-05 09:00:00');
        $this->consent($customer);
        // Excluded: staff, customers without an active service, withdrawn consents.
        $staff = $this->createUser(Role::ADMIN);
        $this->service($staff, '2026-11-01 09:00:00');
        $this->consent($staff);
        $gone = $this->createUser();
        $this->service($gone, '2026-11-01 09:00:00', 'terminated');
        $this->consent($gone);
        $withdrawn = $this->createUser();
        $this->service($withdrawn, '2026-11-01 09:00:00');
        $this->consent($withdrawn);
        $this->app->container()->get(Consents::class)->withdraw($withdrawn->id, Consents::REVIEW_INVITATION, 'account', null);

        self::assertSame(0, $this->invitations()->sendDue(), 'Only 9 days since the first service became active.');

        $this->clock()->freeze(new DateTimeImmutable('2026-12-15 09:00:01', new DateTimeZone('UTC')));
        $results = $this->app->container()->get(Scheduler::class)->run(true);
        self::assertSame('1 review invitation(s) sent', $results['reviews.invitations']);
        $mail = $this->lastMail('review_invitation');
        self::assertNotNull($mail);
        self::assertSame($customer->email, $mail['to']);
        self::assertSame('Wie zufrieden sind Sie mit Nebrix?', $mail['subject'], 'In the language of the customer.');
        self::assertStringContainsString('href="' . self::REVIEW_URL . '"', $mail['html']);
        $optOut = $this->extractLink($mail['html'], '/reviews/opt-out/');
        $decoded = $this->app->container()->get(\Nebrix\Security\Encrypter::class)->decryptArray((string) json_decode((string) $this->db->fetchValue("SELECT payload FROM nx_jobs WHERE type = 'mail.send' ORDER BY id DESC LIMIT 1"), true)['message'], 'mail-job');
        self::assertSame(['list_unsubscribe' => 'http://localhost' . $optOut], $decoded['headers']);
        self::assertSame($serviceId, (int) $this->db->fetchValue('SELECT service_id FROM nx_review_invitations WHERE user_id = ?', [$customer->id]));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_review_invitations'));

        // Never a second invitation.
        $this->clock()->freeze(new DateTimeImmutable('2027-02-01 09:00:00', new DateTimeZone('UTC')));
        self::assertSame(0, $this->invitations()->sendDue());
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_jobs WHERE type = 'mail.send' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.template')) = 'review_invitation'"));

        // The opt-out link: a page with a button (scanners withdraw nothing), then the consent is withdrawn.
        $page = $this->get($optOut);
        self::assertSame(200, $page->status());
        self::assertStringContainsString('noindex, nofollow', $page->body());
        self::assertTrue($this->app->container()->get(Consents::class)->has($customer->id, Consents::REVIEW_INVITATION));
        $this->cookies = [];
        $done = $this->post($optOut, [], false);
        self::assertSame(200, $done->status());
        self::assertFalse($this->app->container()->get(Consents::class)->has($customer->id, Consents::REVIEW_INVITATION));
        self::assertSame('email', $this->db->fetchValue('SELECT withdrawn_via FROM nx_marketing_consents WHERE user_id = ?', [$customer->id]));
        // One-click requests of mail clients (RFC 8058) carry no CSRF token.
        self::assertSame('OK', $this->post($optOut, ['List-Unsubscribe' => 'One-Click'], false)->body());
        self::assertSame(404, $this->get('/reviews/opt-out/' . str_repeat('0', 64))->status());
        self::assertSame(404, $this->post('/reviews/opt-out/' . str_repeat('0', 64), ['List-Unsubscribe' => 'One-Click'], false)->status());
    }

    public function testConsentAtRegistrationIsOptionalAndUnticked(): void
    {
        $form = $this->get('/register')->body();
        self::assertStringNotContainsString('name="review_consent"', $form, 'Not offered while review invitations are off.');

        $this->enable();
        $form = $this->get('/register')->body();
        self::assertMatchesRegularExpression('#<input type="checkbox" name="review_consent" value="1">#', $form, 'Never ticked in advance.');

        $data = ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'password' => 'A-strong-passphrase-9', 'password_confirmation' => 'A-strong-passphrase-9', 'accept_terms' => '1'];
        $this->post('/register', $data + ['email' => 'without@example.test']);
        $this->cookies = [];
        $this->post('/register', $data + ['email' => 'with@example.test', 'review_consent' => '1']);
        $without = (int) $this->db->fetchValue("SELECT id FROM nx_users WHERE email = 'without@example.test'");
        $with = (int) $this->db->fetchValue("SELECT id FROM nx_users WHERE email = 'with@example.test'");
        self::assertGreaterThan(0, $without);
        self::assertGreaterThan(0, $with);
        self::assertNull($this->db->fetchValue('SELECT id FROM nx_marketing_consents WHERE user_id = ?', [$without]));
        $consent = $this->db->fetchOne('SELECT * FROM nx_marketing_consents WHERE user_id = ?', [$with]);
        self::assertNotNull($consent);
        self::assertSame('registration', $consent['granted_source']);
        self::assertSame('198.51.100.0', $consent['granted_ip'], 'Stored shortened.');
        self::assertSame('2026-12-10 12:00:00', $consent['granted_at']);
        self::assertStringContainsString('may invite me once by e-mail', (string) $consent['consent_text']);
    }

    public function testConsentAtCheckoutAndInTheAccount(): void
    {
        $this->enable();
        $customer = $this->createUser();
        $product = $this->websiteProduct('nextcloud', 'Cloud 1', 0, ['quota_gb' => 1], ['tax_rate_bp' => 0]);
        $slug = (string) $this->db->fetchValue('SELECT slug FROM nx_products WHERE id = ?', [$product]);
        $this->actingAs($customer);
        self::assertStringContainsString('name="review_consent"', $this->get('/store/' . $slug)->body());
        $this->post('/store/' . $slug, ['accept_terms' => '1', 'review_consent' => '1']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_orders WHERE user_id = ?', [$customer->id]));
        self::assertSame('checkout', $this->db->fetchValue('SELECT granted_source FROM nx_marketing_consents WHERE user_id = ?', [$customer->id]));

        // Withdraw and give again in the account settings.
        $page = $this->get('/account/communication')->body();
        self::assertStringContainsString('name="review_invitations" value="1" checked', $page);
        $this->assertRedirect($this->post('/account/communication', ['reviews_shown' => '1']), '/account/communication');
        self::assertFalse($this->app->container()->get(Consents::class)->has($customer->id, Consents::REVIEW_INVITATION));
        self::assertSame('account', $this->db->fetchValue('SELECT withdrawn_via FROM nx_marketing_consents WHERE user_id = ?', [$customer->id]));
        $this->post('/account/communication', ['reviews_shown' => '1', 'review_invitations' => '1']);
        self::assertTrue($this->app->container()->get(Consents::class)->has($customer->id, Consents::REVIEW_INVITATION));
        self::assertSame('account', $this->db->fetchValue('SELECT granted_source FROM nx_marketing_consents WHERE user_id = ?', [$customer->id]));
        self::assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'consent.granted' AND target_id = ?", [$customer->id]));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'consent.withdrawn' AND target_id = ?", [$customer->id]));

        // Account closure removes the consent.
        $this->service($customer, '2026-12-01 00:00:00', 'terminated');
        $this->db->run("UPDATE nx_services SET status = 'terminated' WHERE user_id = ?", [$customer->id]);
        $this->app->container()->get(\Nebrix\Customer\CustomerService::class)->closeAndAnonymize($customer, $this->createUser(Role::ADMIN)->id, null);
        self::assertNull($this->db->fetchValue('SELECT id FROM nx_marketing_consents WHERE user_id = ?', [$customer->id]));
    }
}
