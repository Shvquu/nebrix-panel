<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature;

use DateTimeImmutable;
use Nebrix\Auth\User;
use Nebrix\Billing\CreditService;
use Nebrix\Billing\SavedMethodAutoPayment;
use Nebrix\Scheduler\Scheduler;
use Nebrix\Service\ServiceRepository;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\FakeStripeHttp;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;

/**
 * Saved payment methods at Stripe (card / SEPA direct debit) and automatic
 * renewal payments with them – against a fake Stripe API only.
 */
final class SavedPaymentMethodTest extends FeatureTestCase
{
    private const WEBHOOK_SECRET = 'whsec_testsecret';

    private FakeStripeHttp $stripe;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-06-25 12:00:00'));
        $settings = $this->app->container()->get(SettingsRepository::class);
        foreach ([
            'payments.stripe.enabled' => true, 'payments.stripe.mode' => 'test', 'payments.stripe.secret_key' => 'sk_test_abc123',
            'payments.stripe.webhook_secret' => self::WEBHOOK_SECRET, 'payments.stripe.saved_methods' => true,
        ] as $key => $value) {
            $settings->set($key, $value);
        }
        $this->stripe = new FakeStripeHttp();
        ApiRequestor::setHttpClient($this->stripe);
        $this->stripe->on('post', '/v1/customers', static fn (): array => [200, ['id' => 'cus_TEST1', 'object' => 'customer']]);
        $this->stripe->on('post', '/v1/checkout/sessions', static fn (): array => [200, ['id' => 'cs_test_setup1', 'object' => 'checkout.session', 'mode' => 'setup', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_setup1']]);

        $this->customer = $this->createUser(attributes: ['auto_pay_credit' => 0, 'country' => 'DE']);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $method
     */
    private function setupSession(array $method, string $customer = 'cus_TEST1', ?int $userId = null, ?string $mandate = null): void
    {
        $userId ??= $this->customer->id;
        $this->stripe->on('get', '/v1/checkout/sessions/cs_test_setup1', static fn (): array => [200, [
            'id' => 'cs_test_setup1', 'object' => 'checkout.session', 'mode' => 'setup', 'status' => 'complete', 'customer' => $customer,
            'metadata' => ['user_id' => (string) $userId, 'purpose' => 'setup'],
            'setup_intent' => ['id' => 'seti_1', 'object' => 'setup_intent', 'status' => 'succeeded', 'mandate' => $mandate, 'payment_method' => $method + ['object' => 'payment_method', 'customer' => $customer]],
        ]]);
    }

    private function saveCard(): void
    {
        $this->setupSession(['id' => 'pm_card1', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030, 'country' => 'DE']]);
        $this->actingAs($this->customer);
        $this->assertRedirect($this->post('/account/payment-methods/add'), 'https://checkout.stripe.com/c/pay/cs_test_setup1');
        $this->get('/account/payment-methods/return', ['session_id' => 'cs_test_setup1']);
        $this->post('/account/payment-methods/autopay', ['auto_pay_saved' => '1']);
    }

    private function renewal(): int
    {
        $serviceId = $this->app->container()->get(ServiceRepository::class)->create([
            'user_id' => $this->customer->id, 'category' => 'dedicated', 'name' => 'Root server', 'status' => 'active',
            'billing_cycle' => 'monthly', 'recurring_amount' => 500, 'tax_rate_bp' => 1900, 'currency' => 'EUR',
            'next_due_at' => '2026-07-01 00:00:00', 'config' => [],
        ]);
        $this->app->container()->get(Scheduler::class)->createRenewalInvoices();

        return (int) $this->db->fetchValue("SELECT id FROM nx_invoices WHERE service_id = ? AND type = 'renewal'", [$serviceId]);
    }

    private function autopay(): array
    {
        return $this->app->container()->get(SavedMethodAutoPayment::class)->run();
    }

    private function invoiceStatus(int $invoiceId): string
    {
        return (string) $this->db->fetchValue('SELECT status FROM nx_invoices WHERE id = ?', [$invoiceId]);
    }

    private function mails(string $template): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_jobs WHERE type = 'mail.send' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.template')) = ?", [$template]);
    }

    /** @return array<string, mixed> */
    private static function intent(int $invoiceId, string $status, string $id = 'pi_auto1'): array
    {
        return [
            'id' => $id, 'object' => 'payment_intent', 'status' => $status, 'amount' => 595, 'amount_received' => $status === 'succeeded' ? 595 : 0,
            'currency' => 'eur', 'livemode' => false, 'metadata' => ['invoice_id' => (string) $invoiceId, 'purpose' => 'autopay', 'attempt' => '1'],
            'latest_charge' => ['id' => 'ch_1', 'object' => 'charge', 'payment_method_details' => ['type' => 'card', 'card' => ['country' => 'DE']]],
        ];
    }

    private function webhook(string $type, array $object): void
    {
        $payload = json_encode(['id' => 'evt_' . bin2hex(random_bytes(6)), 'object' => 'event', 'type' => $type, 'api_version' => '2026-11-18.endive', 'created' => time(), 'livemode' => false, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
        $response = $this->postRaw('/webhooks/stripe', $payload, ['Stripe-Signature' => FakeStripeHttp::signature($payload, self::WEBHOOK_SECRET)]);
        self::assertSame(200, $response->status(), $response->body());
    }

    /** @param list<string> $headers */
    private static function idempotencyKey(array $headers): string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Idempotency-Key:') === 0) {
                return trim(substr($header, strlen('Idempotency-Key:')));
            }
        }

        return '';
    }

    public function testACardIsSavedAndRenewalsArePaidAutomaticallyOnce(): void
    {
        $this->saveCard();

        $session = $this->stripe->callsTo('post', '/v1/checkout/sessions')[0]['params'];
        self::assertSame('setup', $session['mode']);
        self::assertSame('cus_TEST1', $session['customer']);
        self::assertSame(['card'], $session['payment_method_types']);
        $method = $this->db->fetchOne('SELECT * FROM nx_payment_methods WHERE user_id = ?', [$this->customer->id]);
        self::assertSame(['pm_card1', 'card', 'visa', '4242', 1], [$method['external_id'], $method['type'], $method['brand'], $method['last4'], (int) $method['is_default']]);
        self::assertSame('cus_TEST1', $this->db->fetchValue('SELECT stripe_customer_id FROM nx_users WHERE id = ?', [$this->customer->id]));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT auto_pay_saved FROM nx_users WHERE id = ?', [$this->customer->id]));
        self::assertStringContainsString('•••• 4242', $this->get('/account/payment-methods')->body());

        $invoiceId = $this->renewal();
        $this->stripe->on('post', '/v1/payment_intents', static fn (array $p): array => [200, self::intent((int) $p['metadata']['invoice_id'], 'succeeded')]);
        self::assertSame(['charged' => 0, 'prenotified' => 0], $this->autopay(), 'Not charged a week before the due date.');
        self::assertCount(0, $this->stripe->callsTo('post', '/v1/payment_intents'));

        $this->clock()->freeze(new DateTimeImmutable('2026-06-30 08:00:00'));
        self::assertSame(1, $this->autopay()['charged']);
        self::assertSame('paid', $this->invoiceStatus($invoiceId));
        $call = $this->stripe->callsTo('post', '/v1/payment_intents')[0];
        self::assertSame(595, (int) $call['params']['amount'], 'The amount comes from the invoice.');
        self::assertSame(['eur', 'cus_TEST1', 'pm_card1', 'autopay'], [$call['params']['currency'], $call['params']['customer'], $call['params']['payment_method'], $call['params']['metadata']['purpose']]);
        self::assertContains($call['params']['off_session'], [true, 'true']);
        self::assertSame('nebrix-autopay-' . $invoiceId . '-1', self::idempotencyKey($call['headers']));
        self::assertSame('succeeded', $this->db->fetchValue("SELECT status FROM nx_autopay_attempts WHERE invoice_id = ? AND kind = 'charge'", [$invoiceId]));

        self::assertSame(0, $this->autopay()['charged'], 'Never charged twice.');
        self::assertCount(1, $this->stripe->callsTo('post', '/v1/payment_intents'));
        // The webhook for the same payment is recognized as a duplicate.
        $this->webhook('payment_intent.succeeded', self::intent($invoiceId, 'succeeded'));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_transactions WHERE gateway = 'stripe' AND type = 'payment' AND status = 'completed'"));
    }

    public function testASetupSessionOfAnotherAccountIsNotSaved(): void
    {
        $other = $this->createUser();
        $this->db->update('nx_users', ['stripe_customer_id' => 'cus_OTHER'], ['id' => $other->id]);
        $this->setupSession(['id' => 'pm_card9', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '1111']], 'cus_OTHER', $other->id);
        $this->actingAs($this->customer);

        $this->get('/account/payment-methods/return', ['session_id' => 'cs_test_setup1']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_payment_methods'));
        $this->get('/account/payment-methods/return', ['session_id' => '../../v1/customers']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_payment_methods'));
    }

    public function testDeclinedCardsAreRetriedThreeTimesAtMost(): void
    {
        $this->saveCard();
        $invoiceId = $this->renewal();
        $this->stripe->on('post', '/v1/payment_intents', static fn (array $p): array => [402, ['error' => [
            'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.',
            'payment_intent' => self::intent((int) $p['metadata']['invoice_id'], 'requires_payment_method', 'pi_dec' . $p['metadata']['attempt']),
        ]]]);

        $days = ['2026-06-30 08:00:00', '2026-07-01 08:00:00', '2026-07-03 09:00:00', '2026-07-06 10:00:00', '2026-07-20 10:00:00'];
        foreach ($days as $day) {
            $this->clock()->freeze(new DateTimeImmutable($day));
            $this->autopay();
        }
        $calls = $this->stripe->callsTo('post', '/v1/payment_intents');
        self::assertCount(3, $calls, 'Three attempts, three days apart, then the customer pays manually.');
        self::assertSame('nebrix-autopay-' . $invoiceId . '-3', self::idempotencyKey($calls[2]['headers']));
        self::assertSame('unpaid', $this->invoiceStatus($invoiceId));
        self::assertSame(3, $this->mails('autopay_failed'));
        self::assertSame(3, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_autopay_attempts WHERE invoice_id = ? AND status = 'failed'", [$invoiceId]));
    }

    public function testAuthenticationRequiredAsksTheCustomerToPay(): void
    {
        $this->saveCard();
        $invoiceId = $this->renewal();
        $this->stripe->on('post', '/v1/payment_intents', static fn (array $p): array => [402, ['error' => [
            'type' => 'card_error', 'code' => 'authentication_required', 'message' => 'Authentication required.',
            'payment_intent' => self::intent((int) $p['metadata']['invoice_id'], 'requires_action'),
        ]]]);
        $this->clock()->freeze(new DateTimeImmutable('2026-06-30 08:00:00'));
        $this->autopay();
        $this->clock()->freeze(new DateTimeImmutable('2026-07-05 08:00:00'));
        $this->autopay();

        self::assertCount(1, $this->stripe->callsTo('post', '/v1/payment_intents'));
        self::assertSame('action_required', $this->db->fetchValue("SELECT status FROM nx_autopay_attempts WHERE invoice_id = ? AND kind = 'charge'", [$invoiceId]));
        self::assertSame(1, $this->mails('autopay_action_required'));
    }

    public function testAnInterruptedAttemptIsRepeatedWithTheSameIdempotencyKey(): void
    {
        $this->saveCard();
        $invoiceId = $this->renewal();
        $calls = 0;
        $this->stripe->on('post', '/v1/payment_intents', static function (array $p) use (&$calls): array {
            if (++$calls === 1) {
                throw new ApiConnectionException('Connection timed out');
            }

            return [200, self::intent((int) $p['metadata']['invoice_id'], 'succeeded')];
        });
        $this->clock()->freeze(new DateTimeImmutable('2026-06-30 08:00:00'));
        $this->autopay();
        self::assertSame('retry', $this->db->fetchValue("SELECT status FROM nx_autopay_attempts WHERE invoice_id = ?", [$invoiceId]));
        $this->clock()->freeze(new DateTimeImmutable('2026-06-30 09:00:00'));
        $this->autopay();

        $requests = $this->stripe->callsTo('post', '/v1/payment_intents');
        self::assertCount(2, $requests);
        self::assertSame(self::idempotencyKey($requests[0]['headers']), self::idempotencyKey($requests[1]['headers']));
        self::assertSame('paid', $this->invoiceStatus($invoiceId));
    }

    public function testSepaDebitsArePrenotifiedAndSettledByWebhook(): void
    {
        $this->app->container()->get(SettingsRepository::class)->set('payments.stripe.sepa_debit', true);
        $this->stripe->on('get', '/v1/mandates/mandate_1', static fn (): array => [200, ['id' => 'mandate_1', 'object' => 'mandate', 'status' => 'active', 'payment_method_details' => ['type' => 'sepa_debit', 'sepa_debit' => ['reference' => 'NEBRIX-MANDATE-1']]]]);
        $this->setupSession(['id' => 'pm_sepa1', 'type' => 'sepa_debit', 'sepa_debit' => ['last4' => '3000', 'country' => 'DE']], mandate: 'mandate_1');
        $this->actingAs($this->customer);
        $this->post('/account/payment-methods/add');
        self::assertSame(['card', 'sepa_debit'], $this->stripe->callsTo('post', '/v1/checkout/sessions')[0]['params']['payment_method_types']);
        $this->get('/account/payment-methods/return', ['session_id' => 'cs_test_setup1']);
        $this->post('/account/payment-methods/autopay', ['auto_pay_saved' => '1']);
        self::assertSame('NEBRIX-MANDATE-1', $this->db->fetchValue('SELECT mandate_reference FROM nx_payment_methods WHERE external_id = ?', ['pm_sepa1']));

        $invoiceId = $this->renewal();
        $this->stripe->on('post', '/v1/payment_intents', static fn (array $p): array => [200, self::intent((int) $p['metadata']['invoice_id'], 'processing')]);
        self::assertSame(1, $this->autopay()['prenotified']);
        self::assertSame(1, $this->mails('sepa_prenotification'));
        self::assertSame('2026-06-30', $this->db->fetchValue("SELECT debit_on FROM nx_autopay_attempts WHERE invoice_id = ? AND kind = 'prenotice'", [$invoiceId]));
        $this->autopay();
        self::assertSame(1, $this->mails('sepa_prenotification'), 'One pre-notification per invoice.');

        $this->clock()->freeze(new DateTimeImmutable('2026-06-29 12:00:00'));
        $this->autopay();
        self::assertCount(0, $this->stripe->callsTo('post', '/v1/payment_intents'), 'Not before the announced date.');

        $this->clock()->freeze(new DateTimeImmutable('2026-06-30 06:00:00'));
        self::assertSame(1, $this->autopay()['charged']);
        $call = $this->stripe->callsTo('post', '/v1/payment_intents')[0]['params'];
        self::assertSame(['sepa_debit'], $call['payment_method_types']);
        self::assertSame('mandate_1', $call['mandate']);
        self::assertSame('unpaid', $this->invoiceStatus($invoiceId), 'SEPA is processing – not paid yet.');

        $this->webhook('payment_intent.succeeded', self::intent($invoiceId, 'succeeded'));
        self::assertSame('paid', $this->invoiceStatus($invoiceId));
        self::assertSame('succeeded', $this->db->fetchValue("SELECT status FROM nx_autopay_attempts WHERE invoice_id = ? AND kind = 'charge'", [$invoiceId]));
    }

    public function testAReturnedSepaDebitIsNotRetried(): void
    {
        $this->app->container()->get(SettingsRepository::class)->set('payments.stripe.sepa_debit', true);
        $this->stripe->on('get', '/v1/mandates/mandate_1', static fn (): array => [404, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such mandate']]]);
        $this->setupSession(['id' => 'pm_sepa1', 'type' => 'sepa_debit', 'sepa_debit' => ['last4' => '3000']], mandate: 'mandate_1');
        $this->actingAs($this->customer);
        $this->post('/account/payment-methods/add');
        $this->get('/account/payment-methods/return', ['session_id' => 'cs_test_setup1']);
        $this->post('/account/payment-methods/autopay', ['auto_pay_saved' => '1']);
        $invoiceId = $this->renewal();
        $this->stripe->on('post', '/v1/payment_intents', static fn (array $p): array => [200, self::intent((int) $p['metadata']['invoice_id'], 'processing')]);
        $this->autopay();
        $this->clock()->freeze(new DateTimeImmutable('2026-06-30 06:00:00'));
        $this->autopay();

        $this->webhook('payment_intent.payment_failed', self::intent($invoiceId, 'requires_payment_method') + ['last_payment_error' => ['code' => 'insufficient_funds']]);
        self::assertSame('failed', $this->db->fetchValue("SELECT status FROM nx_autopay_attempts WHERE invoice_id = ? AND kind = 'charge'", [$invoiceId]));
        self::assertSame(1, $this->mails('autopay_failed'));
        $this->clock()->freeze(new DateTimeImmutable('2026-07-10 06:00:00'));
        $this->autopay();
        self::assertCount(1, $this->stripe->callsTo('post', '/v1/payment_intents'));
    }

    public function testCreditTakesPrecedenceAndTheSwitchIsHonored(): void
    {
        $this->saveCard();
        $this->db->update('nx_users', ['auto_pay_credit' => 1], ['id' => $this->customer->id]);
        $this->app->container()->get(CreditService::class)->adjust($this->customer->id, 5000, 'test');
        $this->app->container()->get(SettingsRepository::class)->set('payments.credit.enabled', true);
        $this->renewal();
        $this->clock()->freeze(new DateTimeImmutable('2026-06-30 08:00:00'));
        $this->autopay();
        self::assertCount(0, $this->stripe->callsTo('post', '/v1/payment_intents'));

        $this->db->update('nx_users', ['auto_pay_credit' => 0], ['id' => $this->customer->id]);
        $this->actingAs($this->customer); // the session expired while the clock moved on
        $response = $this->post('/account/payment-methods/autopay', []);
        self::assertSame(302, $response->status(), $response->body());
        self::assertSame(0, (int) $this->db->fetchValue('SELECT auto_pay_saved FROM nx_users WHERE id = ?', [$this->customer->id]));
        $this->autopay();
        self::assertCount(0, $this->stripe->callsTo('post', '/v1/payment_intents'));
    }

    public function testMethodsCanOnlyBeRemovedByTheirOwner(): void
    {
        $this->saveCard();
        $id = (int) $this->db->fetchValue('SELECT id FROM nx_payment_methods WHERE external_id = ?', ['pm_card1']);
        $this->stripe->on('post', '/v1/payment_methods/pm_card1/detach', static fn (): array => [200, ['id' => 'pm_card1', 'object' => 'payment_method', 'customer' => null]]);

        $this->actingAs($this->createUser());
        self::assertSame(404, $this->post('/account/payment-methods/' . $id . '/remove')->status());
        self::assertSame(404, $this->post('/account/payment-methods/' . $id . '/default')->status());
        self::assertCount(0, $this->stripe->callsTo('post', '/v1/payment_methods/pm_card1/detach'));

        $this->actingAs($this->customer);
        $this->post('/account/payment-methods/' . $id . '/remove');
        self::assertCount(1, $this->stripe->callsTo('post', '/v1/payment_methods/pm_card1/detach'));
        self::assertSame('removed', $this->db->fetchValue('SELECT status FROM nx_payment_methods WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT auto_pay_saved FROM nx_users WHERE id = ?', [$this->customer->id]), 'No default left: automatic payment is off.');
    }

    public function testWebhooksDeactivateDetachedMethodsAndRevokedMandates(): void
    {
        $this->saveCard();
        $this->webhook('payment_method.detached', ['id' => 'pm_card1', 'object' => 'payment_method']);
        self::assertSame('removed', $this->db->fetchValue('SELECT status FROM nx_payment_methods WHERE external_id = ?', ['pm_card1']));

        $this->db->insert('nx_payment_methods', ['user_id' => $this->customer->id, 'gateway' => 'stripe', 'external_id' => 'pm_sepa2', 'customer_ref' => 'cus_TEST1', 'type' => 'sepa_debit', 'mandate_id' => 'mandate_2', 'is_default' => 1, 'status' => 'active', 'created_at' => '2026-06-25 12:00:00', 'updated_at' => '2026-06-25 12:00:00']);
        $this->webhook('mandate.updated', ['id' => 'mandate_2', 'object' => 'mandate', 'status' => 'inactive']);
        self::assertSame(['inactive', 0], array_values(array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, (array) $this->db->fetchOne('SELECT status, is_default FROM nx_payment_methods WHERE external_id = ?', ['pm_sepa2']))));
    }

    public function testThePageIsHiddenWhenTheFeatureIsOff(): void
    {
        $this->app->container()->get(SettingsRepository::class)->set('payments.stripe.saved_methods', false);
        $this->actingAs($this->customer);
        self::assertSame(404, $this->get('/account/payment-methods')->status());
        self::assertSame(404, $this->post('/account/payment-methods/add')->status());
        self::assertCount(0, $this->stripe->callsTo('post', '/v1/customers'));
    }
}
