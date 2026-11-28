<?php

declare(strict_types=1);

namespace Nebrix\Billing;

use DateTimeImmutable;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Payment\AutopayAttempts;
use Nebrix\Payment\PaymentMethodRepository;
use Nebrix\Payment\Stripe\StripeGateway;
use Nebrix\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pays open renewal invoices automatically with the customer's default saved
 * payment method (card or SEPA direct debit at Stripe) when the customer
 * switched this on (nx_users.auto_pay_saved).
 *
 * - Charged at the earliest one day before the due date, like the automatic
 *   credit payment (which takes precedence when the credit covers the invoice).
 * - SEPA: the customer first receives a pre-notification with amount and
 *   debit date; the debit happens no earlier than the agreed number of days
 *   later. A failed SEPA debit is not retried automatically (return debits
 *   cost fees) – the customer pays manually.
 * - Cards: up to three attempts, three days apart. When the bank asks for
 *   authentication (3-D Secure) the customer is asked to pay manually.
 * - Every attempt is reserved in nx_autopay_attempts before Stripe is called
 *   (unique per invoice and attempt) and uses a fixed idempotency key, so an
 *   invoice is never charged twice, even by parallel runs.
 */
class SavedMethodAutoPayment
{
    private const RETRY_DAYS = 3;
    private const PRENOTICE_HORIZON_DAYS = 14;

    public function __construct(
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
        private readonly StripeGateway $stripe,
        private readonly PaymentMethodRepository $methods,
        private readonly AutopayAttempts $attempts,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{charged: int, prenotified: int}
     */
    public function run(): array
    {
        $result = ['charged' => 0, 'prenotified' => 0];
        if (!$this->stripe->savedMethodsEnabled()) {
            return $result;
        }

        $now = $this->clock->now();
        $creditFirst = $this->settings->bool('billing.auto_pay_credit') && $this->settings->bool('payments.credit.enabled');
        $invoices = $this->db->fetchAll(
            "SELECT i.*, u.`auto_pay_credit`, u.`credit_balance` FROM `nx_invoices` i JOIN `nx_users` u ON u.`id` = i.`user_id`
             LEFT JOIN `nx_services` s ON s.`id` = i.`service_id`
             WHERE i.`type` = 'renewal' AND i.`status` = 'unpaid' AND i.`total` >= 50
               AND u.`auto_pay_saved` = 1 AND u.`status` = 'active' AND i.`due_at` <= ?
               AND (i.`service_id` IS NULL OR (s.`status` IN ('active', 'suspended') AND s.`cancel_at` IS NULL))
             ORDER BY i.`due_at`, i.`id` LIMIT 500",
            [$now->modify('+' . self::PRENOTICE_HORIZON_DAYS . ' days')->format('Y-m-d H:i:s')],
        );

        foreach ($invoices as $invoice) {
            if ($creditFirst && (int) $invoice['auto_pay_credit'] === 1 && (int) $invoice['credit_balance'] >= (int) $invoice['total']) {
                continue; // settled from the credit balance
            }
            $method = $this->methods->defaultFor((int) $invoice['user_id']);
            if ($method === null) {
                continue;
            }
            try {
                $step = $this->process($invoice, $method, $now);
            } catch (Throwable $exception) {
                $this->logger->error('Automatic payment with a saved method failed', ['invoice' => $invoice['id'], 'message' => $exception->getMessage()]);
                continue;
            }
            if ($step !== null) {
                $result[$step]++;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $method
     *
     * @return 'charged'|'prenotified'|null
     */
    private function process(array $invoice, array $method, DateTimeImmutable $now): ?string
    {
        $sepa = $method['type'] === 'sepa_debit';
        if ($sepa && strtoupper((string) $invoice['currency']) !== 'EUR') {
            return null;
        }
        $chargeAt = (new DateTimeImmutable((string) $invoice['due_at']))->modify('-1 day');

        if ($sepa) {
            $notice = $this->attempts->prenotice((int) $invoice['id']);
            if ($notice === null) {
                $days = max(1, min(14, $this->settings->int('payments.stripe.sepa_prenotice_days') ?: 2));
                $earliest = $now->modify('+' . $days . ' days');
                $debitOn = ($chargeAt > $earliest ? $chargeAt : $earliest)->setTime(0, 0);
                $id = $this->attempts->reserve((int) $invoice['id'], (int) $invoice['user_id'], (int) $method['id'], 'prenotice', 0, (int) $invoice['total'], (string) $invoice['currency'], 'sent', $debitOn->format('Y-m-d'));
                if ($id === null) {
                    return null;
                }
                $this->attempts->sendPrenotice($invoice, $method, $debitOn);

                return 'prenotified';
            }
            // The announced mandate and date are binding: another mandate needs a new notice (staff).
            if ((int) $notice['payment_method_id'] !== (int) $method['id'] || $now->format('Y-m-d') < (string) $notice['debit_on']) {
                return null;
            }
        } elseif ($now < $chargeAt) {
            return null;
        }

        $last = $this->attempts->lastCharge((int) $invoice['id']);
        $maxAttempts = $sepa ? 1 : AutopayAttempts::MAX_ATTEMPTS;
        if ($last === null) {
            $attempt = 1;
            $attemptId = $this->attempts->reserve((int) $invoice['id'], (int) $invoice['user_id'], (int) $method['id'], 'charge', 1, (int) $invoice['total'], (string) $invoice['currency'], 'processing');
        } elseif ($last['status'] === 'retry') {
            // Interrupted before Stripe answered: repeat the same attempt (same idempotency key).
            $attempt = (int) $last['attempt'];
            $attemptId = (int) $last['id'];
            if ((int) $last['payment_method_id'] !== (int) $method['id']) {
                return null; // the customer switched methods meanwhile – staff check the open attempt
            }
        } elseif ($last['status'] === 'failed' && (int) $last['attempt'] < $maxAttempts
            && new DateTimeImmutable((string) $last['updated_at']) <= $now->modify('-' . self::RETRY_DAYS . ' days')) {
            $attempt = (int) $last['attempt'] + 1;
            $attemptId = $this->attempts->reserve((int) $invoice['id'], (int) $invoice['user_id'], (int) $method['id'], 'charge', $attempt, (int) $invoice['total'], (string) $invoice['currency'], 'processing');
        } else {
            return null; // processing, succeeded, waiting for the customer or attempts used up
        }
        if ($attemptId === null) {
            return null; // reserved by a parallel run
        }

        // The invoice may have been paid meanwhile (another tab, a webhook).
        if ($this->db->fetchValue('SELECT `status` FROM `nx_invoices` WHERE `id` = ?', [(int) $invoice['id']]) !== 'unpaid') {
            $this->attempts->update($attemptId, 'failed', null, 'invoice already settled');

            return null;
        }

        $outcome = $this->stripe->chargeOffSession($invoice, $method, $attempt);
        $this->attempts->update($attemptId, $outcome['status'], $outcome['intent'], $outcome['error']);

        if ($outcome['status'] === 'failed' || $outcome['status'] === 'action_required') {
            $this->attempts->notifyFailure($invoice, $outcome['status'] === 'action_required', $outcome['status'] === 'failed' && $attempt < $maxAttempts);
            $this->logger->info('Automatic payment declined', ['invoice' => $invoice['id'], 'attempt' => $attempt, 'status' => $outcome['status'], 'error' => $outcome['error']]);

            return null;
        }

        return in_array($outcome['status'], ['succeeded', 'processing'], true) ? 'charged' : null;
    }
}
