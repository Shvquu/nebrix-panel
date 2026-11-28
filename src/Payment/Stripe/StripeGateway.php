<?php

declare(strict_types=1);

namespace Nebrix\Payment\Stripe;

use Nebrix\Auth\User;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Integration\HealthResult;
use Nebrix\Payment\PaymentGateway;
use Nebrix\Payment\PaymentOutcome;
use Nebrix\Payment\PaymentService;
use Nebrix\Payment\WebhookEventRepository;
use Nebrix\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use Throwable;

/**
 * Stripe Checkout (official stripe-php SDK).
 *
 * Flow: Checkout Session with the invoice total and invoice ID in metadata
 * → customer pays at Stripe → on return the session is retrieved
 * server-side (never trusted from the URL) → PaymentService. Webhooks are
 * verified with Webhook::constructEvent (HMAC-SHA256 + timestamp tolerance)
 * and settle asynchronous payments and refunds made in the Stripe dashboard.
 *
 * Saved payment methods: a Checkout Session in setup mode stores a card or a
 * SEPA direct debit mandate at Stripe (the panel keeps only the reference,
 * brand and last four digits); renewal invoices are then charged off-session
 * with a PaymentIntent (see Billing\SavedMethodAutoPayment). Results are taken
 * from Stripe's API response or verified webhooks only.
 */
class StripeGateway implements PaymentGateway
{
    private ?StripeClient $client = null;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly PaymentService $payments,
        private readonly WebhookEventRepository $webhooks,
        private readonly LoggerInterface $logger,
        private readonly \Nebrix\Security\RateLimiter $limiter,
        private readonly \Nebrix\Payment\PaymentMethodRepository $methods,
        private readonly \Nebrix\Payment\AutopayAttempts $attempts,
        private readonly \Nebrix\Database\Connection $db,
    ) {
    }

    public function key(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Credit card & more (Stripe)';
    }

    public function isEnabled(): bool
    {
        return $this->settings->bool('payments.stripe.enabled') && $this->settings->get('payments.stripe.secret_key') !== null;
    }

    public function isAvailableFor(array $invoice, User $user): bool
    {
        return (int) $invoice['total'] >= 50; // Stripe minimum charge (0.50 in most currencies)
    }

    public function client(): StripeClient
    {
        return $this->client ??= new StripeClient([
            'api_key' => (string) $this->settings->get('payments.stripe.secret_key'),
            'max_network_retries' => 2,
        ]);
    }

    public function start(array $invoice, User $user, string $returnUrl, string $cancelUrl): string|PaymentOutcome
    {
        $currency = strtolower((string) $invoice['currency']);
        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) $invoice['total'],
                    'product_data' => ['name' => 'Invoice ' . $invoice['number']],
                ],
            ]],
            'client_reference_id' => (string) $invoice['id'],
            'customer_email' => $user->email,
            'metadata' => ['invoice_id' => (string) $invoice['id'], 'user_id' => (string) $user->id],
            'payment_intent_data' => ['metadata' => ['invoice_id' => (string) $invoice['id'], 'user_id' => (string) $user->id]],
            // Stripe replaces {CHECKOUT_SESSION_ID}; the server retrieves the session itself.
            'success_url' => $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ], ['idempotency_key' => 'invoice-' . $invoice['id'] . '-' . bin2hex(random_bytes(8))]);

        if (!is_string($session->url) || !str_starts_with($session->url, 'https://')) {
            throw new \RuntimeException('Stripe did not return a checkout URL.');
        }

        $this->payments->event('stripe', 'checkout.created', (string) $session->id, (int) $invoice['id'], (int) $invoice['user_id'], (int) $invoice['total'], strtoupper($currency));

        return $session->url;
    }

    public function complete(array $invoice, Request $request): PaymentOutcome
    {
        $sessionId = $request->string('session_id');
        if (preg_match('/^cs_(test|live)_[A-Za-z0-9]+$/', $sessionId) !== 1) {
            return PaymentOutcome::rejected('Invalid Stripe response.');
        }

        try {
            $session = $this->client()->checkout->sessions->retrieve($sessionId, ['expand' => ['payment_intent.latest_charge']]);
        } catch (ApiErrorException $exception) {
            $this->logger->warning('Stripe session lookup failed', ['message' => $exception->getMessage()]);

            return PaymentOutcome::rejected('The Stripe payment could not be verified.');
        }

        /** @var array<string, mixed> $data */
        $data = $session->toArray();
        if ((string) ($data['metadata']['invoice_id'] ?? '') !== (string) $invoice['id']) {
            $this->payments->event('stripe', 'checkout.invoice_mismatch', $sessionId, (int) $invoice['id'], (int) $invoice['user_id'], null, null);

            return PaymentOutcome::rejected('This Stripe payment does not belong to the invoice.');
        }

        return $this->settleSession($data);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function settleSession(array $session): PaymentOutcome
    {
        $invoiceId = (int) ($session['metadata']['invoice_id'] ?? 0);
        $paymentIntent = is_array($session['payment_intent'] ?? null) ? ($session['payment_intent']['id'] ?? '') : (string) ($session['payment_intent'] ?? '');

        if ($invoiceId === 0 || $paymentIntent === '') {
            return PaymentOutcome::rejected('Incomplete Stripe checkout session.');
        }
        if (($session['payment_status'] ?? '') !== 'paid') {
            return PaymentOutcome::pending('Stripe is still processing the payment. Your invoice will be updated automatically.');
        }

        $country = self::paymentCountry(is_array($session['payment_intent'] ?? null) ? $session['payment_intent'] : null) ?? $this->lookupPaymentCountry($paymentIntent);

        return $this->payments->recordPayment(
            'stripe',
            $paymentIntent,
            $invoiceId,
            (int) ($session['amount_total'] ?? 0),
            strtoupper((string) ($session['currency'] ?? '')),
            array_filter(['session' => (string) ($session['id'] ?? ''), 'livemode' => (bool) ($session['livemode'] ?? false), 'payer_country' => $country], static fn (mixed $v): bool => $v !== null),
        );
    }

    /**
     * Country of the payment instrument (card issuer, bank account) from an
     * expanded payment intent – location evidence for OSS.
     *
     * @param array<string, mixed>|null $intent
     */
    public static function paymentCountry(?array $intent): ?string
    {
        $details = $intent['latest_charge']['payment_method_details'] ?? null;
        if (!is_array($details)) {
            return null;
        }
        $type = (string) ($details['type'] ?? '');
        $country = $details[$type]['country'] ?? null;

        return is_string($country) && preg_match('/^[A-Z]{2}$/', strtoupper($country)) === 1 ? strtoupper($country) : null;
    }

    /** Webhooks carry no expanded charge: one lookup, never blocking the payment. */
    private function lookupPaymentCountry(string $paymentIntent): ?string
    {
        try {
            return self::paymentCountry($this->client()->paymentIntents->retrieve($paymentIntent, ['expand' => ['latest_charge']])->toArray());
        } catch (\Throwable $exception) {
            $this->logger->info('Stripe payment country not available', ['payment_intent' => $paymentIntent, 'message' => mb_substr($exception->getMessage(), 0, 200)]);

            return null;
        }
    }

    public function handleWebhook(Request $request): Response
    {
        $secret = (string) $this->settings->get('payments.stripe.webhook_secret');
        if ($secret === '') {
            return Response::json(['error' => 'webhook secret not configured'], 503);
        }

        $payload = $request->body();
        try {
            $event = Webhook::constructEvent($payload, (string) $request->header('Stripe-Signature'), $secret);
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            // At most 30 entries per hour: forged requests cannot flood the payment event log.
            if ($this->limiter->attempt('webhook-invalid-log:stripe', 30, 3600)) {
                $this->payments->event('stripe', 'webhook.invalid_signature', null, null, null, null, null);
            }

            return Response::json(['error' => 'invalid signature'], 400);
        }

        /** @var array<string, mixed> $data */
        $data = $event->toArray();
        $eventId = (string) $data['id'];
        if (!$this->webhooks->begin('stripe', $eventId, (string) $data['type'], $payload)) {
            return Response::json(['status' => 'duplicate']);
        }

        try {
            $status = $this->processEvent((string) $data['type'], is_array($data['data']['object'] ?? null) ? $data['data']['object'] : []);
            $this->webhooks->finish('stripe', $eventId, $status);
        } catch (Throwable $exception) {
            $this->webhooks->finish('stripe', $eventId, 'failed', $exception->getMessage());
            $this->logger->error('Stripe webhook processing failed', ['event' => $eventId, 'message' => $exception->getMessage()]);

            return Response::json(['error' => 'processing failed'], 500);
        }

        return Response::json(['status' => $status]);
    }

    /**
     * @param array<string, mixed> $object
     */
    private function processEvent(string $type, array $object): string
    {
        switch ($type) {
            case 'checkout.session.completed':
                if (($object['mode'] ?? 'payment') === 'setup') {
                    return $this->storeSetupSession($object, null) !== null ? 'processed' : 'ignored';
                }
                $outcome = $this->settleSession($object);

                return $outcome->status === PaymentOutcome::REJECTED ? 'ignored' : 'processed';

            case 'checkout.session.async_payment_succeeded':
                $outcome = $this->settleSession($object);

                return $outcome->status === PaymentOutcome::REJECTED ? 'ignored' : 'processed';

            case 'checkout.session.async_payment_failed':
                $invoiceId = (int) ($object['metadata']['invoice_id'] ?? 0);
                $this->payments->recordFailure('stripe', (string) ($object['payment_intent'] ?? $object['id'] ?? ''), $invoiceId > 0 ? $invoiceId : null, 'Asynchronous payment failed');

                return 'processed';

            case 'refund.created':
            case 'refund.updated':
                if (($object['status'] ?? '') !== 'succeeded' || !is_string($object['payment_intent'] ?? null)) {
                    return 'ignored';
                }
                $this->payments->recordRefund('stripe', (string) $object['id'], $object['payment_intent'], (int) $object['amount'], strtoupper((string) $object['currency']));

                return 'processed';

            case 'charge.dispute.created':
                if (!is_string($object['payment_intent'] ?? null)) {
                    return 'ignored';
                }
                $outcome = $this->payments->recordDispute('stripe', (string) ($object['id'] ?? ''), $object['payment_intent'], (int) ($object['amount'] ?? 0) ?: null, isset($object['currency']) ? strtoupper((string) $object['currency']) : null, ['reason' => (string) ($object['reason'] ?? '')]);

                return $outcome->status === PaymentOutcome::REJECTED ? 'ignored' : 'processed';

            case 'charge.refunded':
                $intent = is_string($object['payment_intent'] ?? null) ? $object['payment_intent'] : null;
                foreach ($object['refunds']['data'] ?? [] as $refund) {
                    if ($intent !== null && ($refund['status'] ?? '') === 'succeeded') {
                        $this->payments->recordRefund('stripe', (string) $refund['id'], $intent, (int) $refund['amount'], strtoupper((string) $refund['currency']));
                    }
                }

                return 'processed';

            case 'payment_intent.succeeded':
                if (($object['metadata']['purpose'] ?? '') !== 'autopay') {
                    return 'ignored'; // Checkout payments are settled through their session.
                }
                $outcome = $this->settleIntent($object);
                $attempt = $this->attempts->byIntent((string) ($object['id'] ?? ''));
                if ($attempt !== null && $outcome->isSuccessful()) {
                    $this->attempts->update((int) $attempt['id'], 'succeeded');
                }

                return $outcome->status === PaymentOutcome::REJECTED ? 'ignored' : 'processed';

            case 'payment_intent.payment_failed':
                if (($object['metadata']['purpose'] ?? '') !== 'autopay') {
                    return 'ignored';
                }
                $this->autopayFailed($object);

                return 'processed';

            case 'payment_method.detached':
                if (is_string($object['id'] ?? null)) {
                    $this->methods->markRemovedByExternal('stripe', $object['id']);
                }

                return 'processed';

            case 'mandate.updated':
                if (is_string($object['id'] ?? null) && ($object['status'] ?? 'active') !== 'active') {
                    $this->methods->markMandateInactive($object['id']);
                }

                return 'processed';

            default:
                return 'ignored';
        }
    }

    // ------------------------------------------------ saved payment methods

    /** Customers may save a card / SEPA mandate for automatic renewal payments. */
    public function savedMethodsEnabled(): bool
    {
        return $this->isEnabled() && $this->settings->bool('payments.stripe.saved_methods');
    }

    /** @return list<string> */
    public function savedMethodTypes(): array
    {
        return $this->settings->bool('payments.stripe.sepa_debit') ? ['card', 'sepa_debit'] : ['card'];
    }

    /** The customer's Stripe Customer ID; created once (the panel keeps one per account). */
    public function customerId(User $user): string
    {
        $existing = $this->db->fetchValue('SELECT `stripe_customer_id` FROM `nx_users` WHERE `id` = ?', [$user->id]);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        // The same idempotency key for 24 hours: parallel requests get the same customer.
        $customer = $this->client()->customers->create([
            'email' => $user->email,
            'name' => $user->displayName(),
            'metadata' => ['user_id' => (string) $user->id],
        ], ['idempotency_key' => 'nebrix-customer-' . $user->id]);
        $id = (string) $customer->id;
        if (preg_match('/^cus_[A-Za-z0-9]+$/', $id) !== 1) {
            throw new \RuntimeException('Stripe did not return a customer.');
        }
        $this->db->run('UPDATE `nx_users` SET `stripe_customer_id` = ? WHERE `id` = ? AND `stripe_customer_id` IS NULL', [$id, $user->id]);

        return (string) $this->db->fetchValue('SELECT `stripe_customer_id` FROM `nx_users` WHERE `id` = ?', [$user->id]);
    }

    /** Starts a Checkout Session in setup mode and returns the Stripe URL. */
    public function startSetup(User $user, string $returnUrl, string $cancelUrl): string
    {
        $session = $this->client()->checkout->sessions->create([
            'mode' => 'setup',
            'customer' => $this->customerId($user),
            'payment_method_types' => $this->savedMethodTypes(),
            'client_reference_id' => (string) $user->id,
            'metadata' => ['user_id' => (string) $user->id, 'purpose' => 'setup'],
            'setup_intent_data' => ['metadata' => ['user_id' => (string) $user->id, 'purpose' => 'setup']],
            'success_url' => $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ], ['idempotency_key' => 'setup-' . $user->id . '-' . bin2hex(random_bytes(8))]);

        if (!is_string($session->url) || !str_starts_with($session->url, 'https://')) {
            throw new \RuntimeException('Stripe did not return a checkout URL.');
        }
        $this->payments->event('stripe', 'setup.created', (string) $session->id, null, $user->id, null, null);

        return $session->url;
    }

    /**
     * Return from the setup page: the session is retrieved server-side and
     * must belong to the signed-in customer.
     *
     * @return int|null the saved method's ID
     */
    public function completeSetup(User $user, string $sessionId): ?int
    {
        if (preg_match('/^cs_(test|live)_[A-Za-z0-9]+$/', $sessionId) !== 1) {
            return null;
        }
        try {
            $session = $this->client()->checkout->sessions->retrieve($sessionId, ['expand' => ['setup_intent.payment_method']]);
        } catch (ApiErrorException $exception) {
            $this->logger->warning('Stripe setup session lookup failed', ['message' => $exception->getMessage()]);

            return null;
        }

        /** @var array<string, mixed> $data */
        $data = $session->toArray();

        return $this->storeSetupSession($data, $user->id);
    }

    /**
     * Stores the payment method of a completed setup session. The customer is
     * identified by the Stripe Customer ID stored in the panel – never by data
     * from the browser.
     *
     * @param array<string, mixed> $session
     */
    private function storeSetupSession(array $session, ?int $expectedUserId): ?int
    {
        if (($session['mode'] ?? '') !== 'setup' || ($session['status'] ?? '') !== 'complete' || !is_string($session['customer'] ?? null)) {
            return null;
        }
        $customerRef = $session['customer'];
        $userId = $this->db->fetchValue('SELECT `id` FROM `nx_users` WHERE `stripe_customer_id` = ?', [$customerRef]);
        if ($userId === null || ($expectedUserId !== null && (int) $userId !== $expectedUserId)
            || (string) ($session['metadata']['user_id'] ?? '') !== (string) $userId) {
            $this->payments->event('stripe', 'setup.customer_mismatch', (string) ($session['id'] ?? ''), null, $expectedUserId, null, null);

            return null;
        }

        try {
            $intent = $session['setup_intent'] ?? null;
            if (is_string($intent)) {
                $intent = $this->client()->setupIntents->retrieve($intent, ['expand' => ['payment_method']])->toArray();
            }
            if (!is_array($intent) || ($intent['status'] ?? '') !== 'succeeded') {
                return null;
            }
            $method = $intent['payment_method'] ?? null;
            if (is_string($method)) {
                $method = $this->client()->paymentMethods->retrieve($method)->toArray();
            }
        } catch (ApiErrorException $exception) {
            $this->logger->warning('Stripe setup intent lookup failed', ['message' => $exception->getMessage()]);

            return null;
        }
        if (!is_array($method) || !is_string($method['id'] ?? null) || preg_match('/^pm_[A-Za-z0-9]+$/', $method['id']) !== 1) {
            return null;
        }
        if (isset($method['customer']) && is_string($method['customer']) && $method['customer'] !== $customerRef) {
            return null;
        }

        $type = (string) ($method['type'] ?? '');
        if (!in_array($type, ['card', 'sepa_debit'], true)) {
            return null;
        }
        $details = is_array($method[$type] ?? null) ? $method[$type] : [];
        $mandateId = is_string($intent['mandate'] ?? null) ? $intent['mandate'] : null;
        $id = $this->methods->save((int) $userId, 'stripe', $method['id'], $customerRef, [
            'type' => $type,
            'brand' => $type === 'card' ? (string) ($details['brand'] ?? '') : null,
            'last4' => isset($details['last4']) ? (string) $details['last4'] : null,
            'exp_month' => $type === 'card' && isset($details['exp_month']) ? (int) $details['exp_month'] : null,
            'exp_year' => $type === 'card' && isset($details['exp_year']) ? (int) $details['exp_year'] : null,
            'country' => isset($details['country']) ? (string) $details['country'] : null,
            'mandate_id' => $mandateId,
            'mandate_reference' => $mandateId !== null ? $this->mandateReference($mandateId) : null,
        ]);
        if ($id !== null) {
            $this->payments->event('stripe', 'setup.completed', (string) ($session['id'] ?? ''), null, (int) $userId, null, null, ['type' => $type]);
        }

        return $id;
    }

    /** The SEPA mandate reference shown in pre-notifications (auxiliary – never blocks saving). */
    private function mandateReference(string $mandateId): ?string
    {
        try {
            $mandate = $this->client()->mandates->retrieve($mandateId)->toArray();
            $reference = $mandate['payment_method_details']['sepa_debit']['reference'] ?? null;

            return is_string($reference) && $reference !== '' ? $reference : null;
        } catch (\Throwable $exception) {
            $this->logger->info('Stripe mandate reference not available', ['mandate' => $mandateId, 'message' => mb_substr($exception->getMessage(), 0, 200)]);

            return null;
        }
    }

    /**
     * Removes a saved method at Stripe (detach) and in the panel.
     *
     * @param array<string, mixed> $method
     */
    public function detach(array $method): bool
    {
        try {
            $this->client()->paymentMethods->detach((string) $method['external_id']);
        } catch (\Stripe\Exception\InvalidRequestException $exception) {
            // Already detached or deleted at Stripe: remove it here as well.
            if ($exception->getStripeCode() !== 'resource_missing' && !str_contains($exception->getMessage(), 'not attached')) {
                $this->logger->warning('Stripe payment method could not be detached', ['method' => $method['id'], 'message' => $exception->getMessage()]);

                return false;
            }
        } catch (ApiErrorException $exception) {
            $this->logger->warning('Stripe payment method could not be detached', ['method' => $method['id'], 'message' => $exception->getMessage()]);

            return false;
        }
        $this->methods->markRemoved((int) $method['id']);

        return true;
    }

    /**
     * Charges an invoice off-session with a saved method. The same attempt
     * always uses the same idempotency key, so repeating it after a network
     * error never charges twice.
     *
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $method
     *
     * @return array{status: string, intent: ?string, error: ?string} status succeeded | processing | action_required | failed | retry
     */
    public function chargeOffSession(array $invoice, array $method, int $attempt): array
    {
        $result = $this->charge($invoice, $method, $attempt);
        $this->payments->event('stripe', 'autopay.' . $result['status'], $result['intent'], (int) $invoice['id'], (int) $invoice['user_id'], (int) $invoice['total'], strtoupper((string) $invoice['currency']), array_filter(['attempt' => $attempt, 'method' => (string) $method['type'], 'error' => $result['error']], static fn (mixed $v): bool => $v !== null));

        return $result;
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $method
     *
     * @return array{status: string, intent: ?string, error: ?string}
     */
    private function charge(array $invoice, array $method, int $attempt): array
    {
        $params = [
            'amount' => (int) $invoice['total'],
            'currency' => strtolower((string) $invoice['currency']),
            'customer' => (string) $method['customer_ref'],
            'payment_method' => (string) $method['external_id'],
            'payment_method_types' => [(string) $method['type']],
            'off_session' => true,
            'confirm' => true,
            'description' => 'Invoice ' . $invoice['number'],
            'metadata' => ['invoice_id' => (string) $invoice['id'], 'user_id' => (string) $invoice['user_id'], 'purpose' => 'autopay', 'attempt' => (string) $attempt],
            'expand' => ['latest_charge'],
        ];
        if ($method['type'] === 'sepa_debit' && is_string($method['mandate_id'] ?? null) && $method['mandate_id'] !== '') {
            $params['mandate'] = $method['mandate_id'];
        }

        try {
            $intent = $this->client()->paymentIntents->create($params, ['idempotency_key' => 'nebrix-autopay-' . $invoice['id'] . '-' . $attempt]);
        } catch (\Stripe\Exception\CardException $exception) {
            $error = $exception->getError();
            $intentId = is_object($error) && isset($error->payment_intent['id']) ? (string) $error->payment_intent['id'] : null;
            $code = (string) $exception->getStripeCode();

            return [
                'status' => $code === 'authentication_required' ? 'action_required' : 'failed',
                'intent' => $intentId,
                'error' => mb_substr(trim($code . ' ' . (string) $exception->getDeclineCode()), 0, 200),
            ];
        } catch (\Stripe\Exception\ApiConnectionException|\Stripe\Exception\RateLimitException $exception) {
            // Unknown outcome: the next run repeats the same attempt (same idempotency key).
            $this->logger->warning('Stripe automatic payment interrupted', ['invoice' => $invoice['id'], 'message' => $exception->getMessage()]);

            return ['status' => 'retry', 'intent' => null, 'error' => 'connection'];
        } catch (ApiErrorException $exception) {
            $this->logger->error('Stripe automatic payment failed', ['invoice' => $invoice['id'], 'message' => $exception->getMessage()]);

            return ['status' => 'failed', 'intent' => null, 'error' => mb_substr('api ' . (string) $exception->getStripeCode(), 0, 200)];
        }

        /** @var array<string, mixed> $data */
        $data = $intent->toArray();
        $intentId = (string) ($data['id'] ?? '');

        return match ((string) ($data['status'] ?? '')) {
            'succeeded' => $this->settleIntent($data)->isSuccessful()
                ? ['status' => 'succeeded', 'intent' => $intentId, 'error' => null]
                : ['status' => 'failed', 'intent' => $intentId, 'error' => 'not recorded'],
            'processing' => ['status' => 'processing', 'intent' => $intentId, 'error' => null],
            'requires_action' => ['status' => 'action_required', 'intent' => $intentId, 'error' => 'requires_action'],
            default => ['status' => 'failed', 'intent' => $intentId, 'error' => mb_substr((string) ($data['last_payment_error']['code'] ?? $data['status'] ?? 'failed'), 0, 200)],
        };
    }

    /**
     * Records a succeeded off-session PaymentIntent (API response or verified
     * webhook). Amount and currency are checked against the invoice.
     *
     * @param array<string, mixed> $intent
     */
    private function settleIntent(array $intent): PaymentOutcome
    {
        $invoiceId = (int) ($intent['metadata']['invoice_id'] ?? 0);
        $intentId = (string) ($intent['id'] ?? '');
        if ($invoiceId === 0 || preg_match('/^pi_[A-Za-z0-9]+$/', $intentId) !== 1 || ($intent['status'] ?? '') !== 'succeeded') {
            return PaymentOutcome::rejected('Incomplete Stripe payment.');
        }
        $country = self::paymentCountry($intent) ?? $this->lookupPaymentCountry($intentId);

        return $this->payments->recordPayment(
            'stripe',
            $intentId,
            $invoiceId,
            (int) ($intent['amount_received'] ?? $intent['amount'] ?? 0),
            strtoupper((string) ($intent['currency'] ?? '')),
            array_filter(['autopay' => true, 'livemode' => (bool) ($intent['livemode'] ?? false), 'payer_country' => $country], static fn (mixed $v): bool => $v !== null),
        );
    }

    /**
     * An off-session payment failed later (e.g. a returned SEPA debit).
     *
     * @param array<string, mixed> $intent
     */
    private function autopayFailed(array $intent): void
    {
        $intentId = (string) ($intent['id'] ?? '');
        $invoiceId = (int) ($intent['metadata']['invoice_id'] ?? 0);
        $reason = mb_substr((string) ($intent['last_payment_error']['code'] ?? 'payment_failed'), 0, 200);
        $attempt = $this->attempts->byIntent($intentId);
        if ($attempt !== null && in_array($attempt['status'], ['failed', 'action_required'], true)) {
            return; // already handled (the API response reported it)
        }
        if ($attempt !== null) {
            $this->attempts->update((int) $attempt['id'], 'failed', null, $reason);
        }
        $this->payments->recordFailure('stripe', $intentId, $invoiceId > 0 ? $invoiceId : null, 'Automatic payment failed: ' . $reason);

        $invoice = $invoiceId > 0 ? $this->db->fetchOne('SELECT * FROM `nx_invoices` WHERE `id` = ?', [$invoiceId]) : null;
        if ($invoice !== null && $invoice['status'] === 'unpaid') {
            $this->attempts->notifyFailure($invoice, false, $attempt !== null && (int) $attempt['attempt'] < \Nebrix\Payment\AutopayAttempts::MAX_ATTEMPTS);
        }
    }

    public function refund(array $transaction, int $amount, string $reason, string $idempotencyKey): PaymentOutcome
    {
        $refund = $this->client()->refunds->create([
            'payment_intent' => (string) $transaction['reference'],
            'amount' => $amount,
            'metadata' => ['transaction_id' => (string) $transaction['id'], 'reason' => mb_substr($reason, 0, 200)],
        ], ['idempotency_key' => $idempotencyKey]);

        if ($refund->status === 'succeeded') {
            return $this->payments->recordRefund('stripe', (string) $refund->id, (string) $transaction['reference'], (int) $refund->amount, strtoupper((string) $refund->currency), ['reason' => $reason]);
        }

        $this->payments->event('stripe', 'refund.' . $refund->status, (string) $refund->id, $transaction['invoice_id'] !== null ? (int) $transaction['invoice_id'] : null, (int) $transaction['user_id'], $amount, (string) $transaction['currency']);

        return PaymentOutcome::pending('Stripe accepted the refund; it will be recorded when Stripe confirms it.');
    }

    public function testConnection(): HealthResult
    {
        try {
            $balance = $this->client()->balance->retrieve();
        } catch (ApiErrorException $exception) {
            return HealthResult::down('Stripe: ' . $exception->getMessage());
        }

        $configured = $this->settings->string('payments.stripe.mode');
        $live = (bool) $balance->livemode;
        if (($configured === 'live') !== $live) {
            return HealthResult::degraded(sprintf('The key is a %s key but the mode is set to %s.', $live ? 'live' : 'test', $configured));
        }
        if ($this->settings->get('payments.stripe.webhook_secret') === null) {
            return HealthResult::degraded('Connected, but no webhook signing secret is configured.');
        }

        return HealthResult::healthy(sprintf('Connected to Stripe (%s mode).', $live ? 'live' : 'test'));
    }
}
