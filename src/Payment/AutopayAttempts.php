<?php

declare(strict_types=1);

namespace Nebrix\Payment;

use Nebrix\Billing\Money;
use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Mail\Mailer;
use Nebrix\Repository\UserRepository;
use Nebrix\View\Translator;
use PDOException;

/**
 * Log of automatic payments with saved payment methods: one row per charge
 * attempt and per SEPA pre-notification, unique per invoice and attempt, so
 * an invoice is never charged twice in parallel. Also sends the customer
 * e-mails about pre-notifications and failed or blocked payments.
 */
final class AutopayAttempts
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Mailer $mailer,
        private readonly UserRepository $users,
        private readonly Config $config,
        private readonly Translator $translator,
    ) {
    }

    /**
     * Reserves an attempt. Returns its ID, or null when another run reserved
     * the same attempt first (unique key).
     */
    public function reserve(int $invoiceId, int $userId, ?int $methodId, string $kind, int $attempt, int $amount, string $currency, string $status, ?string $debitOn = null): ?int
    {
        $now = $this->clock->nowString();
        try {
            return $this->db->insert('nx_autopay_attempts', [
                'invoice_id' => $invoiceId,
                'user_id' => $userId,
                'payment_method_id' => $methodId,
                'kind' => $kind,
                'attempt' => $attempt,
                'status' => $status,
                'amount' => $amount,
                'currency' => strtoupper($currency),
                'debit_on' => $debitOn,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $exception;
        }
    }

    public function update(int $id, string $status, ?string $intentId = null, ?string $error = null): void
    {
        $data = ['status' => $status, 'updated_at' => $this->clock->nowString()];
        if ($intentId !== null) {
            $data['payment_intent_id'] = mb_substr($intentId, 0, 64);
        }
        if ($error !== null) {
            $data['error'] = mb_substr($error, 0, 255);
        }
        $this->db->update('nx_autopay_attempts', $data, ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function lastCharge(int $invoiceId): ?array
    {
        return $this->db->fetchOne("SELECT * FROM `nx_autopay_attempts` WHERE `invoice_id` = ? AND `kind` = 'charge' ORDER BY `attempt` DESC LIMIT 1", [$invoiceId]);
    }

    /** @return array<string, mixed>|null */
    public function prenotice(int $invoiceId): ?array
    {
        return $this->db->fetchOne("SELECT * FROM `nx_autopay_attempts` WHERE `invoice_id` = ? AND `kind` = 'prenotice' LIMIT 1", [$invoiceId]);
    }

    /** @return array<string, mixed>|null */
    public function byIntent(string $intentId): ?array
    {
        return $this->db->fetchOne("SELECT * FROM `nx_autopay_attempts` WHERE `payment_intent_id` = ? AND `kind` = 'charge' LIMIT 1", [$intentId]);
    }

    /**
     * Recent attempts of a customer (account page).
     *
     * @return list<array<string, mixed>>
     */
    public function recentFor(int $userId, int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT a.*, i.`number` AS invoice_number FROM `nx_autopay_attempts` a JOIN `nx_invoices` i ON i.`id` = a.`invoice_id`
             WHERE a.`user_id` = ? AND a.`kind` = 'charge' ORDER BY a.`id` DESC LIMIT " . max(1, min(50, $limit)),
            [$userId],
        );
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $method
     */
    public function sendPrenotice(array $invoice, array $method, \DateTimeImmutable $debitOn): void
    {
        $this->mail((int) $invoice['user_id'], 'sepa_prenotification', $invoice, static fn (string $locale): array => [
            'debit_date' => Translator::date($locale, $debitOn),
            'mandate_reference' => (string) ($method['mandate_reference'] ?? '') !== '' ? (string) $method['mandate_reference'] : '–',
            'account_last4' => (string) ($method['last4'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $invoice
     */
    public function notifyFailure(array $invoice, bool $actionRequired, bool $willRetry): void
    {
        $this->mail((int) $invoice['user_id'], $actionRequired ? 'autopay_action_required' : 'autopay_failed', $invoice, static fn (): array => [
            'retry_note' => $willRetry ? 'retry' : 'manual',
        ]);
    }

    /**
     * @param array<string, mixed> $invoice
     * @param callable(string): array<string, string> $extra
     */
    private function mail(int $userId, string $template, array $invoice, callable $extra): void
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            return;
        }
        $locale = (string) ($user->attribute('language') ?? 'en') ?: 'en';
        $variables = [
            'name' => $user->displayName(),
            'invoice_number' => (string) $invoice['number'],
            'invoice_total' => Money::format((int) $invoice['total'], (string) $invoice['currency'], $locale),
            'invoice_url' => rtrim($this->config->string('app.url'), '/') . '/invoices/' . (int) $invoice['id'],
        ] + $extra($locale);
        if (isset($variables['retry_note'])) {
            $variables['retry_note'] = $this->translator->translateTo($locale, $variables['retry_note'] === 'retry'
                ? 'We will try again in a few days.'
                : 'Please pay the invoice manually.');
        }
        $this->mailer->queue($user->email, $user->displayName(), $template, $variables, $locale);
    }
}
