<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use DateTimeImmutable;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Billing\Coupons\CouponService;
use Nebrix\Billing\OrderService;
use Nebrix\Customer\CustomerService;
use Nebrix\Payment\PaymentService;
use Nebrix\Referral\ReferralService;
use Nebrix\Repository\ProductRepository;
use Nebrix\Repository\UserRepository;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Refer a friend: link and registration, qualification by a real payment,
 * reward after the holding period, refunds, limits, review of self-referrals.
 */
final class ReferralTest extends FeatureTestCase
{
    private ReferralService $referrals;
    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-07-01 10:00:00'));
        $this->referrals = $this->app->container()->get(ReferralService::class);
        $this->settings = $this->app->container()->get(SettingsRepository::class);
        $this->settings->set('referral.enabled', true);
        $this->settings->set('referral.reward', '10.00');
        $this->settings->set('referral.min_amount', '5.00');
    }

    private function product(int $price): int
    {
        return $this->app->container()->get(ProductRepository::class)->create([
            'category' => 'dedicated', 'name' => 'Root ' . $price, 'slug' => 'root-' . $price . '-' . random_int(1, 99999), 'price' => $price, 'setup_fee' => 0, 'currency' => 'EUR',
            'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => [],
        ]);
    }

    /**
     * @return array{order_id: int, invoice_id: int, paid: bool}
     */
    private function order(User $customer, int $price, ?string $coupon = null): array
    {
        return $this->app->container()->get(OrderService::class)->place($customer, $this->product($price), ['hostname' => 'srv' . random_int(1, 99999)], '127.0.0.1', $coupon);
    }

    private function pay(int $invoiceId, string $gateway, string $reference): void
    {
        $total = (int) $this->db->fetchValue('SELECT `total` FROM `nx_invoices` WHERE `id` = ?', [$invoiceId]);
        self::assertTrue($this->app->container()->get(PaymentService::class)->recordPayment($gateway, $reference, $invoiceId, $total, 'EUR')->isSuccessful());
    }

    /** A referred customer (as if registered through the link). */
    private function referred(User $referrer, string $ip = '203.0.113.9'): User
    {
        $customer = $this->createUser();
        $this->referrals->attach($customer->id, $this->referrals->codeFor($referrer->id), $ip);

        return $customer;
    }

    private function referralStatus(User $referred): string
    {
        return (string) $this->db->fetchValue('SELECT `status` FROM `nx_referrals` WHERE `referred_id` = ?', [$referred->id]);
    }

    private function credit(User $user): int
    {
        return (int) $this->db->fetchValue('SELECT `credit_balance` FROM `nx_users` WHERE `id` = ?', [$user->id]);
    }

    private function later(string $modify): void
    {
        $this->clock()->freeze($this->clock()->now()->modify($modify));
    }

    public function testLinkRegistrationAndRewardAfterTheHoldingPeriod(): void
    {
        $this->settings->set('referral.welcome', '2.50');
        $referrer = $this->createUser();
        $code = $this->referrals->codeFor($referrer->id);
        self::assertTrue(ReferralService::isValidCode($code));
        self::assertSame($code, $this->referrals->codeFor($referrer->id), 'The code stays the same.');

        // The link remembers the code (also typed in lower case) and leads to the registration.
        $this->assertRedirect($this->get('/r/' . strtolower($code)), '/register');
        self::assertSame($code, $this->cookies[ReferralService::COOKIE] ?? null);
        self::assertStringContainsString('value="' . $code . '"', $this->get('/register')->body());

        $this->post('/register', [
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test',
            'password' => 'Compiler-Pioneer-1906', 'password_confirmation' => 'Compiler-Pioneer-1906', 'accept_terms' => '1', 'referral_code' => $code,
        ]);
        $referredId = (int) $this->db->fetchValue('SELECT `id` FROM `nx_users` WHERE `email` = ?', ['ada@example.test']);
        self::assertGreaterThan(0, $referredId);
        self::assertSame($referrer->id, (int) $this->db->fetchValue('SELECT `referred_by` FROM `nx_users` WHERE `id` = ?', [$referredId]));
        self::assertArrayNotHasKey(ReferralService::COOKIE, $this->cookies, 'The cookie is removed after the registration.');
        $this->db->update('nx_users', ['status' => User::STATUS_ACTIVE, 'email_verified_at' => $this->clock()->nowString()], ['id' => $referredId]);
        $referred = $this->app->container()->get(UserRepository::class)->find($referredId);
        self::assertNotNull($referred);
        self::assertSame(ReferralService::PENDING, $this->referralStatus($referred));

        // Ordered, not yet paid: nothing happens.
        $order = $this->order($referred, 1000);
        $this->referrals->process();
        self::assertSame(ReferralService::PENDING, $this->referralStatus($referred));

        // Paid by bank transfer: qualified, but rewarded only after the holding period.
        $this->later('+2 hours');
        $this->pay($order['invoice_id'], 'manual', 'bank-1');
        self::assertSame(['qualified' => 1, 'rewarded' => 0, 'reset' => 0], $this->referrals->process());
        self::assertSame($order['invoice_id'], (int) $this->db->fetchValue('SELECT `invoice_id` FROM `nx_referrals` WHERE `referred_id` = ?', [$referred->id]));
        $this->later('+13 days');
        $this->referrals->process();
        self::assertSame(ReferralService::QUALIFIED, $this->referralStatus($referred));
        self::assertSame(0, $this->credit($referrer));

        $this->later('+1 day');
        self::assertSame(1, $this->referrals->process()['rewarded']);
        self::assertSame(ReferralService::REWARDED, $this->referralStatus($referred));
        self::assertSame(1000, $this->credit($referrer));
        self::assertSame(250, $this->credit($referred));
        self::assertSame(['Referral reward', 'referral'], array_values((array) $this->db->fetchOne('SELECT `reason`, `reference_type` FROM `nx_credit_ledger` WHERE `user_id` = ?', [$referrer->id])));
        self::assertSame($referrer->email, $this->lastMail('referral_reward')['to'] ?? null);
        self::assertSame('ada@example.test', $this->lastMail('referral_welcome')['to'] ?? null);

        // Processing again changes nothing.
        $this->later('+1 day');
        self::assertSame(['qualified' => 0, 'rewarded' => 0, 'reset' => 0], $this->referrals->process());
        self::assertSame(1000, $this->credit($referrer));

        // The referrer's page: link, statistics – but not who registered.
        $this->actingAs($referrer);
        $page = $this->get('/account/referrals')->body();
        self::assertStringContainsString('/r/' . $code, $page);
        self::assertStringContainsString('Rewarded', $page);
        self::assertStringContainsString('€10.00', $page);
        self::assertStringNotContainsString('ada@example.test', $page);
        self::assertStringNotContainsString('Lovelace', $page);
    }

    public function testOnlyRealPaymentsAboveTheMinimumQualify(): void
    {
        $this->settings->set('referral.min_amount', '15.00');
        $referrer = $this->createUser();
        $referred = $this->referred($referrer);

        $small = $this->order($referred, 1000);
        $this->pay($small['invoice_id'], 'manual', 'bank-small');
        $fromCredit = $this->order($referred, 2000);
        $this->pay($fromCredit['invoice_id'], 'credit', 'credit-' . $fromCredit['invoice_id']);
        $this->app->container()->get(CouponService::class)->save(null, [
            'code' => 'FREE', 'type' => 'percent', 'value' => 10000, 'duration' => 'once', 'max_uses_per_customer' => 1, 'is_active' => true,
        ], 1);
        self::assertTrue($this->order($referred, 3000, 'FREE')['paid'], 'Free order.');
        $this->referrals->process();
        self::assertSame(ReferralService::PENDING, $this->referralStatus($referred), 'Below the minimum, paid from credit or free: no reward.');

        $real = $this->order($referred, 2000);
        $this->pay($real['invoice_id'], 'stripe', 'pi_test_123');
        $this->referrals->process();
        self::assertSame(ReferralService::QUALIFIED, $this->referralStatus($referred));
        self::assertSame($real['invoice_id'], (int) $this->db->fetchValue('SELECT `invoice_id` FROM `nx_referrals` WHERE `referred_id` = ?', [$referred->id]));
    }

    public function testRefundsResetAndTheMonthlyLimitHolds(): void
    {
        $this->settings->set('referral.monthly_limit', 1);
        $referrer = $this->createUser();

        // Refunded during the holding period: no reward, the referral waits for another payment.
        $refunded = $this->referred($referrer);
        $order = $this->order($refunded, 1000);
        $this->pay($order['invoice_id'], 'manual', 'bank-r');
        $this->referrals->process();
        $total = (int) $this->db->fetchValue('SELECT `total` FROM `nx_invoices` WHERE `id` = ?', [$order['invoice_id']]);
        $this->app->container()->get(PaymentService::class)->recordRefund('manual', 'refund-r', 'bank-r', $total, 'EUR');
        $this->later('+15 days');
        self::assertSame(1, $this->referrals->process()['reset']);
        self::assertSame(ReferralService::PENDING, $this->referralStatus($refunded));
        self::assertSame(0, $this->credit($referrer));

        // Two qualified referrals, limit 1 per month: the second one waits for the next month.
        $first = $this->referred($referrer);
        $second = $this->referred($referrer);
        foreach ([$first, $second] as $i => $customer) {
            $paid = $this->order($customer, 1000);
            $this->pay($paid['invoice_id'], 'manual', 'bank-m' . $i);
        }
        $this->referrals->process();
        $this->later('+14 days');
        self::assertSame(1, $this->referrals->process()['rewarded']);
        self::assertSame([ReferralService::REWARDED, ReferralService::QUALIFIED], [$this->referralStatus($first), $this->referralStatus($second)]);
        self::assertSame(1000, $this->credit($referrer));

        // A suspended referred account waits until staff decide.
        $this->db->update('nx_users', ['status' => User::STATUS_SUSPENDED], ['id' => $second->id]);
        $this->clock()->freeze(new DateTimeImmutable('2026-08-01 00:30:00'));
        self::assertSame(0, $this->referrals->process()['rewarded']);
        $this->db->update('nx_users', ['status' => User::STATUS_ACTIVE], ['id' => $second->id]);
        self::assertSame(1, $this->referrals->process()['rewarded']);
        self::assertSame(2000, $this->credit($referrer));
    }

    public function testSelfReferralsAreHeldForReviewAndStaffDecide(): void
    {
        $referrer = $this->createUser();
        $this->db->insert('nx_login_history', ['user_id' => $referrer->id, 'identifier' => $referrer->email, 'ip' => '192.0.2.44', 'user_agent' => 'x', 'success' => 1, 'created_at' => $this->clock()->nowString()]);

        $suspicious = $this->referred($referrer, '192.0.2.44');
        $other = $this->referred($referrer, '192.0.2.44');
        self::assertSame([ReferralService::REVIEW, ReferralService::REVIEW], [$this->referralStatus($suspicious), $this->referralStatus($other)]);
        $order = $this->order($suspicious, 1000);
        $this->pay($order['invoice_id'], 'manual', 'bank-s');
        $this->referrals->process();
        self::assertSame(ReferralService::REVIEW, $this->referralStatus($suspicious), 'Held referrals do not qualify on their own.');

        // Support staff may look but not decide.
        $this->actingAs($this->createUser(Role::SUPPORT));
        $id = (int) $this->db->fetchValue('SELECT `id` FROM `nx_referrals` WHERE `referred_id` = ?', [$suspicious->id]);
        $list = $this->get('/admin/referrals')->body();
        self::assertStringContainsString($suspicious->email, $list);
        self::assertStringContainsString('192.0.2.44', $list);
        self::assertSame(403, $this->post('/admin/referrals/' . $id . '/approve')->status());

        $this->actingAs($this->createUser(Role::ADMIN));
        $this->post('/admin/referrals/' . $id . '/approve');
        self::assertSame(ReferralService::PENDING, $this->referralStatus($suspicious));
        $this->referrals->process();
        self::assertSame(ReferralService::QUALIFIED, $this->referralStatus($suspicious));

        $otherId = (int) $this->db->fetchValue('SELECT `id` FROM `nx_referrals` WHERE `referred_id` = ?', [$other->id]);
        $this->post('/admin/referrals/' . $otherId . '/reject', ['reason' => 'Same person.']);
        self::assertSame([ReferralService::REJECTED, 'Same person.'], array_values((array) $this->db->fetchOne('SELECT `status`, `note` FROM `nx_referrals` WHERE `id` = ?', [$otherId])));
        $this->post('/admin/referrals/' . $otherId . '/approve');
        self::assertSame(ReferralService::REJECTED, $this->referralStatus($other), 'Rejected stays rejected.');
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = 'referral.rejected'"));

        // The sign-up address is removed after the login-history retention period.
        $this->later('+181 days');
        $this->referrals->process();
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_referrals` WHERE `signup_ip` IS NOT NULL'));
    }

    public function testInvalidCodesDisabledFeatureAndClosedAccounts(): void
    {
        $referrer = $this->createUser();
        $code = $this->referrals->codeFor($referrer->id);

        // A mistyped code is reported; the visitor can correct or remove it.
        $this->post('/register', [
            'first_name' => 'Bob', 'last_name' => 'B', 'email' => 'bob@example.test',
            'password' => 'Compiler-Pioneer-1906', 'password_confirmation' => 'Compiler-Pioneer-1906', 'accept_terms' => '1', 'referral_code' => 'WRONG234',
        ]);
        self::assertNull($this->db->fetchValue('SELECT `id` FROM `nx_users` WHERE `email` = ?', ['bob@example.test']));
        self::assertStringContainsString('This referral code is not valid.', $this->get('/register')->body());
        $this->get('/r/WRONG234');
        self::assertArrayNotHasKey(ReferralService::COOKIE, $this->cookies);

        // Closing an account ends its open referrals and the code stops working.
        $referred = $this->referred($referrer);
        $this->app->container()->get(CustomerService::class)->closeAndAnonymize($referrer, $referrer->id, null);
        self::assertSame(ReferralService::REJECTED, $this->referralStatus($referred));
        self::assertNull($this->referrals->referrerByCode($code));

        // Switched off: no page, no cookie, codes are ignored.
        $other = $this->createUser();
        $otherCode = $this->referrals->codeFor($other->id);
        $this->settings->set('referral.enabled', false);
        $this->get('/r/' . $otherCode);
        self::assertArrayNotHasKey(ReferralService::COOKIE, $this->cookies);
        $this->actingAs($other);
        self::assertSame(404, $this->get('/account/referrals')->status());
        self::assertStringNotContainsString('/account/referrals', $this->get('/dashboard')->body());
        $late = $this->createUser();
        $this->referrals->attach($late->id, $otherCode, '203.0.113.1');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_referrals` WHERE `referred_id` = ?', [$late->id]));
    }
}
