<?php

declare(strict_types=1);

namespace Nebrix\Finance\Partner;

use DateTimeImmutable;
use InvalidArgumentException;
use Nebrix\Audit\AuditLogger;
use Nebrix\Auth\User;
use Nebrix\Billing\Money;
use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Finance\Csv;
use Nebrix\Mail\Mailer;
use Nebrix\Security\Encrypter;
use Nebrix\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Partner program: cash commissions for referrals ("refer a friend" in
 * cash mode).
 *
 * Customers opt in with consent to the partner terms. For every invoice of
 * a referred customer that is paid with real money (not account credit,
 * not free) within N months after the sign-up, the partner earns a
 * percentage of the net amount. Referrals held for review (sign-up from the
 * partner's own network) or rejected earn nothing. A commission is "pending" during the
 * holding period and "payable" afterwards; refunds, cancellations and
 * chargebacks of the invoice reverse it (fully or proportionally; when it
 * was already paid out, a negative adjustment is set off against later
 * payouts). Partners request a payout above a minimum; staff approve it, pay
 * it by bank transfer (optionally from a SEPA XML file) and mark it paid.
 *
 * Payout details (IBAN, account holder) are encrypted at rest and bound to
 * the partner or payout row. Commissions are a service of the partner to the
 * operator: the partner invoices it (or a self-billing credit note is agreed)
 * and handles VAT – the panel does not issue that document.
 */
class PartnerService
{
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';

    /** Payments that do not count: account credit and free orders. */
    private const EXCLUDED_GATEWAYS = "'credit', 'free'";

    public function __construct(
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
        private readonly Encrypter $encrypter,
        private readonly AuditLogger $audit,
        private readonly Mailer $mailer,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly SepaCreditTransfer $sepa,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('partner.enabled') && $this->settings->bool('referral.enabled');
    }

    public function currency(): string
    {
        return strtoupper($this->settings->string('general.default_currency') ?: 'EUR');
    }

    /** Commission rate in basis points (10 % = 1000). */
    public function rateBp(): int
    {
        $value = str_replace(',', '.', trim($this->settings->string('partner.commission_percent')));

        return preg_match('/^\d{1,2}(\.\d{1,2})?$/', $value) === 1 ? (int) round((float) $value * 100) : 0;
    }

    public function minimumPayout(): int
    {
        try {
            return max(1, Money::parse($this->settings->string('partner.min_payout') ?: '0', $this->currency()));
        } catch (InvalidArgumentException) {
            return 5000;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function partner(int $userId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_partners` WHERE `user_id` = ?', [$userId]);
    }

    /** Joins the program (or rejoins after it ended) with consent to the current terms. */
    public function join(User $user, string $ip): void
    {
        if (!$this->enabled()) {
            throw new InvalidArgumentException('The partner program is not available.');
        }
        $existing = $this->partner($user->id);
        if ($existing !== null && $existing['status'] === self::SUSPENDED) {
            throw new InvalidArgumentException('Your partner account is suspended. Please contact us.');
        }
        $now = $this->clock->nowString();
        $terms = mb_substr($this->settings->string('partner.terms_version'), 0, 50);
        $consent = ['status' => self::ACTIVE, 'terms_version' => $terms, 'consented_at' => $now, 'consent_ip' => mb_substr($ip, 0, 45), 'updated_at' => $now];
        if ($existing === null) {
            $this->db->insert('nx_partners', $consent + ['user_id' => $user->id, 'created_at' => $now]);
        } elseif ($existing['status'] !== self::ACTIVE) {
            $this->db->update('nx_partners', $consent, ['user_id' => $user->id]);
        }
        $this->audit->log($user->id, 'partner.joined', 'user', $user->id, ['terms_version' => $terms], $ip);
    }

    /** Stores the payout details (encrypted). */
    public function saveDetails(int $userId, string $iban, string $holder, string $bic, string $ip): void
    {
        $partner = $this->partner($userId) ?? throw new InvalidArgumentException('Join the partner program first.');
        $iban = Iban::normalize($iban);
        if (!Iban::isValid($iban)) {
            throw new InvalidArgumentException('The IBAN is not valid. Please check it (SEPA countries only).');
        }
        $holder = trim((string) preg_replace('/\s+/u', ' ', $holder));
        if ($holder === '' || mb_strlen($holder) > 70) {
            throw new InvalidArgumentException('Enter the name of the account holder (max. 70 characters).');
        }
        $bic = strtoupper(trim($bic));
        if ($bic !== '' && preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $bic) !== 1) {
            throw new InvalidArgumentException('The BIC is not valid.');
        }
        $this->db->update('nx_partners', [
            'iban_encrypted' => $this->encrypter->encrypt($iban, 'partner:' . $userId . ':iban'),
            'holder_encrypted' => $this->encrypter->encrypt($holder, 'partner:' . $userId . ':holder'),
            'bic' => $bic !== '' ? $bic : null,
            'iban_last4' => substr($iban, -4),
            'details_updated_at' => $this->clock->nowString(),
            'updated_at' => $this->clock->nowString(),
        ], ['user_id' => (int) $partner['user_id']]);
        // The audit log records that the details changed, never the details.
        $this->audit->log($userId, 'partner.details_changed', 'user', $userId, ['iban_last4' => substr($iban, -4)], $ip);
    }

    /**
     * Commission totals of a partner.
     *
     * @return array{pending: int, payable: int, requested: int, paid: int}
     */
    public function balance(int $userId): array
    {
        $totals = ['pending' => 0, 'payable' => 0, 'requested' => 0, 'paid' => 0];
        foreach ($this->db->fetchAll("SELECT `status`, SUM(`amount`) AS total FROM `nx_partner_commissions` WHERE `partner_id` = ? AND `status` <> 'reversed' GROUP BY `status`", [$userId]) as $row) {
            if (isset($totals[(string) $row['status']])) {
                $totals[(string) $row['status']] = (int) $row['total'];
            }
        }

        return $totals;
    }

    /**
     * Requests a payout of all payable commissions.
     *
     * @return int ID of the payout
     */
    public function requestPayout(int $userId, string $ip): int
    {
        if (!$this->enabled()) {
            throw new InvalidArgumentException('The partner program is not available.');
        }
        // Commissions whose invoice was refunded in the meantime are taken into account first.
        $this->reverseLost($userId);

        $payoutId = $this->db->transaction(function () use ($userId): int {
            $partner = $this->db->fetchOne('SELECT * FROM `nx_partners` WHERE `user_id` = ? FOR UPDATE', [$userId]);
            if ($partner === null || $partner['status'] !== self::ACTIVE) {
                throw new InvalidArgumentException('Only active partners can request a payout.');
            }
            if ($partner['iban_encrypted'] === null || $partner['holder_encrypted'] === null) {
                throw new InvalidArgumentException('Enter your payout details first.');
            }
            if ($this->db->fetchValue("SELECT `id` FROM `nx_partner_payouts` WHERE `partner_id` = ? AND `status` IN ('requested', 'approved')", [$userId]) !== null) {
                throw new InvalidArgumentException('A payout is already being processed.');
            }
            $rows = $this->db->fetchAll("SELECT `id`, `amount`, `currency` FROM `nx_partner_commissions` WHERE `partner_id` = ? AND `status` = 'payable' FOR UPDATE", [$userId]);
            $amount = array_sum(array_map(static fn (array $row): int => (int) $row['amount'], $rows));
            if ($amount < $this->minimumPayout()) {
                throw new InvalidArgumentException('The payable amount has not reached the minimum payout yet.');
            }
            $now = $this->clock->nowString();
            $payoutId = $this->db->insert('nx_partner_payouts', [
                'partner_id' => $userId,
                'amount' => $amount,
                'currency' => $this->currency(),
                'status' => 'requested',
                'bic' => $partner['bic'],
                'iban_last4' => $partner['iban_last4'],
                'reference' => 'NXP-' . strtoupper(bin2hex(random_bytes(6))),
                'requested_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            // Snapshot of the payout details: a later change does not redirect this payout.
            $this->db->update('nx_partner_payouts', [
                'iban_encrypted' => $this->encrypter->encrypt($this->encrypter->decrypt((string) $partner['iban_encrypted'], 'partner:' . $userId . ':iban'), 'partner_payout:' . $payoutId . ':iban'),
                'holder_encrypted' => $this->encrypter->encrypt($this->encrypter->decrypt((string) $partner['holder_encrypted'], 'partner:' . $userId . ':holder'), 'partner_payout:' . $payoutId . ':holder'),
            ], ['id' => $payoutId]);
            $this->db->run(
                "UPDATE `nx_partner_commissions` SET `status` = 'requested', `payout_id` = ?, `updated_at` = ? WHERE `partner_id` = ? AND `status` = 'payable'",
                [$payoutId, $now, $userId],
            );

            return $payoutId;
        });
        $this->audit->log($userId, 'partner.payout_requested', 'partner_payout', $payoutId, [], $ip);

        return $payoutId;
    }

    /** Staff approve a requested payout (not their own). */
    public function approve(int $payoutId, int $actorId, ?string $ip): void
    {
        $this->transition($payoutId, ['requested'], 'approved', $actorId, ['approved_by' => $actorId, 'approved_at' => $this->clock->nowString()]);
        $this->audit->log($actorId, 'partner.payout_approved', 'partner_payout', $payoutId, [], $ip);
    }

    /** Staff reject a payout: its commissions become payable again. */
    public function reject(int $payoutId, int $actorId, string $note, ?string $ip): void
    {
        $this->db->transaction(function () use ($payoutId, $actorId, $note): void {
            $this->transition($payoutId, ['requested', 'approved'], 'rejected', $actorId, ['rejected_by' => $actorId, 'rejected_at' => $this->clock->nowString(), 'note' => mb_substr($note, 0, 255)]);
            $this->db->run("UPDATE `nx_partner_commissions` SET `status` = 'payable', `payout_id` = NULL, `updated_at` = ? WHERE `payout_id` = ? AND `status` = 'requested'", [$this->clock->nowString(), $payoutId]);
        });
        $this->audit->log($actorId, 'partner.payout_rejected', 'partner_payout', $payoutId, ['note' => $note], $ip);
    }

    /** Staff transferred the money: payout and commissions are paid. */
    public function markPaid(int $payoutId, int $actorId, string $bankReference, ?string $ip): void
    {
        $payout = $this->db->transaction(function () use ($payoutId, $actorId, $bankReference): array {
            $payout = $this->transition($payoutId, ['approved'], 'paid', $actorId, ['paid_by' => $actorId, 'paid_at' => $this->clock->nowString(), 'bank_reference' => mb_substr($bankReference, 0, 100) ?: null]);
            $this->db->run("UPDATE `nx_partner_commissions` SET `status` = 'paid', `updated_at` = ? WHERE `payout_id` = ? AND `status` = 'requested'", [$this->clock->nowString(), $payoutId]);

            return $payout;
        });
        $this->audit->log($actorId, 'partner.payout_paid', 'partner_payout', $payoutId, ['amount' => (int) $payout['amount'], 'bank_reference' => $bankReference], $ip);

        $user = $this->db->fetchOne('SELECT `email`, `first_name`, `last_name`, `language` FROM `nx_users` WHERE `id` = ?', [(int) $payout['partner_id']]);
        if ($user !== null) {
            $locale = (string) ($user['language'] ?? 'en') ?: 'en';
            $name = trim((string) $user['first_name'] . ' ' . (string) $user['last_name']);
            $this->mailer->queue((string) $user['email'], $name !== '' ? $name : null, 'partner_payout_paid', [
                'name' => $name !== '' ? $name : (string) $user['email'],
                'amount' => Money::format((int) $payout['amount'], (string) $payout['currency'], $locale),
                'reference' => (string) $payout['reference'],
                'iban' => Iban::mask((string) ($payout['iban_last4'] ?? '')),
                'partner_url' => rtrim($this->config->string('app.url'), '/') . '/account/partner',
            ], $locale);
        }
    }

    /**
     * @param list<string> $from
     * @param array<string, mixed> $data
     * @return array<string, mixed> the payout before the change
     */
    private function transition(int $payoutId, array $from, string $to, int $actorId, array $data): array
    {
        return $this->db->transaction(function () use ($payoutId, $from, $to, $actorId, $data): array {
            $payout = $this->db->fetchOne('SELECT * FROM `nx_partner_payouts` WHERE `id` = ? FOR UPDATE', [$payoutId]);
            if ($payout === null) {
                throw new InvalidArgumentException('The payout was not found.');
            }
            if (!in_array($payout['status'], $from, true)) {
                throw new InvalidArgumentException('The payout is not in a state that allows this.');
            }
            if ((int) $payout['partner_id'] === $actorId) {
                throw new InvalidArgumentException('You cannot process your own payout.');
            }
            $this->db->update('nx_partner_payouts', $data + ['status' => $to, 'updated_at' => $this->clock->nowString()], ['id' => $payoutId]);

            return $payout;
        });
    }

    /**
     * SEPA credit transfer file (pain.001.001.09) for approved payouts that
     * were not exported yet (or the given ones). Marks them as exported.
     *
     * @param list<int> $payoutIds empty = all approved, not yet exported
     * @return array{xml: string, count: int, total: int, message_id: string}
     */
    public function sepaExport(array $payoutIds, int $actorId, ?string $ip): array
    {
        $debtor = [
            'name' => $this->settings->string('invoice.bank_account_holder') ?: $this->settings->string('general.company_name'),
            'iban' => $this->settings->string('invoice.bank_iban'),
            'bic' => $this->settings->string('invoice.bank_bic'),
        ];
        $result = $this->db->transaction(function () use ($payoutIds, $debtor): array {
            $where = $payoutIds === [] ? 'p.`sepa_exported_at` IS NULL' : 'p.`id` IN (' . implode(',', array_map('intval', $payoutIds)) . ')';
            $payouts = $this->db->fetchAll(
                "SELECT p.* FROM `nx_partner_payouts` p WHERE p.`status` = 'approved' AND p.`currency` = 'EUR' AND {$where} ORDER BY p.`id` FOR UPDATE",
            );
            $transfers = [];
            foreach ($payouts as $payout) {
                $id = (int) $payout['id'];
                $transfers[] = [
                    'end_to_end' => (string) $payout['reference'],
                    'amount' => (int) $payout['amount'],
                    'name' => $this->encrypter->decrypt((string) $payout['holder_encrypted'], 'partner_payout:' . $id . ':holder'),
                    'iban' => $this->encrypter->decrypt((string) $payout['iban_encrypted'], 'partner_payout:' . $id . ':iban'),
                    'bic' => (string) ($payout['bic'] ?? ''),
                    'remittance' => 'Partnerprovision ' . $payout['reference'],
                ];
            }
            $now = $this->clock->now();
            $messageId = 'NXSEPA-' . $now->format('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $xml = $this->sepa->build($messageId, $now, $this->nextBusinessDay($now), $debtor, $transfers);
            foreach ($payouts as $payout) {
                $this->db->update('nx_partner_payouts', ['sepa_exported_at' => $this->clock->nowString(), 'updated_at' => $this->clock->nowString()], ['id' => (int) $payout['id']]);
            }

            return ['xml' => $xml, 'count' => count($transfers), 'total' => array_sum(array_column($transfers, 'amount')), 'message_id' => $messageId];
        });
        $this->audit->log($actorId, 'partner.sepa_exported', 'partner_payout', null, ['count' => $result['count'], 'total' => $result['total'], 'message_id' => $result['message_id']], $ip);

        return $result;
    }

    private function nextBusinessDay(DateTimeImmutable $date): DateTimeImmutable
    {
        $next = $date->modify('+1 day');
        while ((int) $next->format('N') >= 6) {
            $next = $next->modify('+1 day');
        }

        return $next;
    }

    /**
     * Scheduler task: reverse commissions of refunded invoices, accrue new
     * ones, release commissions after the holding period.
     *
     * @return array{accrued: int, reversed: int, payable: int}
     */
    public function process(): array
    {
        // Also while the program is switched off: commissions must follow their invoice.
        $result = ['accrued' => 0, 'reversed' => $this->reverseLost(null), 'payable' => 0];
        if ($this->enabled() && $this->rateBp() > 0) {
            $result['accrued'] = $this->accrue();
        }
        $result['payable'] = $this->db->run(
            "UPDATE `nx_partner_commissions` SET `status` = 'payable', `updated_at` = ? WHERE `status` = 'pending' AND `payable_at` <= ?",
            [$this->clock->nowString(), $this->clock->nowString()],
        )->rowCount();

        return $result;
    }

    /** New commissions for invoices paid by referred customers of active partners. */
    private function accrue(): int
    {
        $rate = $this->rateBp();
        $months = max(1, $this->settings->int('partner.commission_months'));
        $holding = max(0, $this->settings->int('partner.holding_days'));
        $candidates = $this->db->fetchAll(
            "SELECT r.`id` AS referral_id, r.`referrer_id`, i.`id` AS invoice_id, i.`subtotal`, i.`currency`, i.`paid_at`
               FROM `nx_referrals` r
               JOIN `nx_partners` p ON p.`user_id` = r.`referrer_id` AND p.`status` = 'active'
               JOIN `nx_invoices` i ON i.`user_id` = r.`referred_id`
              WHERE r.`status` IN ('pending', 'qualified', 'rewarded')
                AND i.`type` IN ('order', 'renewal', 'upgrade') AND i.`status` = 'paid' AND i.`amount_refunded` = 0
                AND i.`currency` = ? AND i.`subtotal` > 0
                AND i.`paid_at` >= r.`created_at` AND i.`paid_at` >= p.`consented_at`
                AND i.`paid_at` < DATE_ADD(r.`created_at`, INTERVAL {$months} MONTH)
                AND EXISTS (SELECT 1 FROM `nx_transactions` t WHERE t.`invoice_id` = i.`id` AND t.`type` = 'payment' AND t.`status` = 'completed'
                               AND t.`amount` > 0 AND t.`gateway` NOT IN (" . self::EXCLUDED_GATEWAYS . "))
                AND NOT EXISTS (SELECT 1 FROM `nx_payment_events` e WHERE e.`invoice_id` = i.`id` AND e.`event_type` = 'payment.disputed')
                AND NOT EXISTS (SELECT 1 FROM `nx_partner_commissions` c WHERE c.`accrual_key` = CONCAT('inv:', i.`id`))
           ORDER BY i.`paid_at`, i.`id` LIMIT 500",
            [$this->currency()],
        );
        $accrued = 0;
        foreach ($candidates as $row) {
            $amount = Money::discount((int) $row['subtotal'], $rate);
            if ($amount <= 0) {
                continue;
            }
            $now = $this->clock->nowString();
            $payableAt = (new DateTimeImmutable((string) $row['paid_at'], new \DateTimeZone('UTC')))->modify('+' . $holding . ' days')->format('Y-m-d H:i:s');
            $inserted = $this->db->run(
                'INSERT IGNORE INTO `nx_partner_commissions` (`partner_id`, `referral_id`, `invoice_id`, `kind`, `accrual_key`, `basis`, `rate_bp`, `amount`, `currency`, `status`, `payable_at`, `created_at`, `updated_at`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [(int) $row['referrer_id'], (int) $row['referral_id'], (int) $row['invoice_id'], 'accrual', 'inv:' . $row['invoice_id'], (int) $row['subtotal'], $rate, $amount, (string) $row['currency'], 'pending', $payableAt, $now, $now],
            )->rowCount();
            $accrued += $inserted;
        }

        return $accrued;
    }

    /**
     * Reverses commissions whose invoice was refunded (fully or partly),
     * cancelled or charged back.
     */
    private function reverseLost(?int $partnerId): int
    {
        $rows = $this->db->fetchAll(
            "SELECT c.`id` FROM `nx_partner_commissions` c JOIN `nx_invoices` i ON i.`id` = c.`invoice_id`
              WHERE c.`kind` = 'accrual' AND c.`status` <> 'reversed' " . ($partnerId !== null ? 'AND c.`partner_id` = ' . $partnerId : '') . "
                AND (i.`status` <> 'paid' OR i.`amount_refunded` > 0
                     OR EXISTS (SELECT 1 FROM `nx_payment_events` e WHERE e.`invoice_id` = i.`id` AND e.`event_type` = 'payment.disputed'))
              LIMIT 500",
        );
        $reversed = 0;
        foreach ($rows as $row) {
            try {
                $reversed += $this->reverseOne((int) $row['id']) ? 1 : 0;
            } catch (Throwable $exception) {
                if ($exception instanceof \PDOException) {
                    throw $exception;
                }
                $this->logger->error('Commission reversal failed', ['commission' => $row['id'], 'message' => $exception->getMessage()]);
            }
        }

        return $reversed;
    }

    private function reverseOne(int $commissionId): bool
    {
        return $this->db->transaction(function () use ($commissionId): bool {
            $accrual = $this->db->fetchOne('SELECT * FROM `nx_partner_commissions` WHERE `id` = ? FOR UPDATE', [$commissionId]);
            if ($accrual === null || $accrual['status'] === 'reversed') {
                return false;
            }
            $invoice = (array) $this->db->fetchOne('SELECT `id`, `status`, `total`, `amount_refunded` FROM `nx_invoices` WHERE `id` = ?', [(int) $accrual['invoice_id']]);
            $disputed = $this->db->fetchValue("SELECT `id` FROM `nx_payment_events` WHERE `invoice_id` = ? AND `event_type` = 'payment.disputed' LIMIT 1", [(int) $invoice['id']]) !== null;
            $total = (int) $invoice['total'];
            $standing = $disputed || !in_array($invoice['status'], ['paid', 'refunded'], true) || $total <= 0 ? 0 : max(0, $total - (int) $invoice['amount_refunded']);
            $target = $total > 0 ? intdiv((int) $accrual['amount'] * $standing, $total) : 0;
            $adjustments = (int) $this->db->fetchValue(
                "SELECT COALESCE(SUM(`amount`), 0) FROM `nx_partner_commissions` WHERE `partner_id` = ? AND `invoice_id` = ? AND `kind` = 'adjustment' AND `status` <> 'reversed'",
                [(int) $accrual['partner_id'], (int) $invoice['id']],
            );
            $delta = $target - ((int) $accrual['amount'] + $adjustments);
            if ($delta >= 0) {
                return false;
            }
            $now = $this->clock->nowString();
            $note = $disputed ? 'The payment was charged back.' : ($invoice['status'] === 'cancelled' ? 'The invoice was cancelled.' : 'The invoice was refunded.');
            if ($target === 0 && $adjustments === 0 && in_array($accrual['status'], ['pending', 'payable'], true)) {
                $this->db->update('nx_partner_commissions', ['status' => 'reversed', 'note' => $note, 'updated_at' => $now], ['id' => $commissionId]);
            } else {
                // Already requested or paid out (or partly refunded): a negative entry is set off against the next payout.
                $this->db->insert('nx_partner_commissions', [
                    'partner_id' => (int) $accrual['partner_id'],
                    'referral_id' => (int) $accrual['referral_id'],
                    'invoice_id' => (int) $invoice['id'],
                    'kind' => 'adjustment',
                    'accrual_key' => null,
                    'basis' => 0,
                    'rate_bp' => (int) $accrual['rate_bp'],
                    'amount' => $delta,
                    'currency' => (string) $accrual['currency'],
                    'status' => $accrual['status'] === 'pending' ? 'pending' : 'payable',
                    'payable_at' => $accrual['status'] === 'pending' ? (string) $accrual['payable_at'] : $now,
                    'note' => $note,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->audit->log(null, 'partner.commission_reversed', 'partner_commission', $commissionId, ['amount' => $delta, 'invoice' => (int) $invoice['id']]);

            return true;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function commissions(int $userId, ?string $from = null, ?string $to = null, int $limit = 200, bool $withInvoice = false): array
    {
        $where = '`partner_id` = ?';
        $params = [$userId];
        if ($from !== null && $to !== null) {
            $where .= ' AND `created_at` >= ? AND `created_at` < ?';
            array_push($params, $from, $to);
        }

        // The referred customer is not named and partners do not see invoice numbers: that data is not their business.
        return $this->db->fetchAll('SELECT ' . ($withInvoice ? '`invoice_id`, ' : '') . "`id`, `kind`, `basis`, `rate_bp`, `amount`, `currency`, `status`, `payable_at`, `note`, `created_at`, `payout_id` FROM `nx_partner_commissions` WHERE {$where} ORDER BY `id` DESC LIMIT " . max(1, $limit), $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payouts(int $userId): array
    {
        return $this->db->fetchAll('SELECT `id`, `amount`, `currency`, `status`, `reference`, `iban_last4`, `requested_at`, `approved_at`, `paid_at`, `bank_reference`, `note` FROM `nx_partner_payouts` WHERE `partner_id` = ? ORDER BY `id` DESC LIMIT 100', [$userId]);
    }

    /** Statement of a year (CSV): commissions and payouts. */
    public function statementCsv(int $userId, int $year): string
    {
        $from = sprintf('%04d-01-01 00:00:00', $year);
        $to = sprintf('%04d-01-01 00:00:00', $year + 1);
        $rows = [['date', 'entry', 'basis', 'rate_percent', 'amount', 'currency', 'status', 'note']];
        foreach (array_reverse($this->commissions($userId, $from, $to, 10000)) as $row) {
            $rows[] = [
                substr((string) $row['created_at'], 0, 10),
                $row['kind'] === 'accrual' ? 'commission' : 'reversal',
                Money::toDecimal((int) $row['basis'], (string) $row['currency']),
                number_format((int) $row['rate_bp'] / 100, 2, '.', ''),
                Money::toDecimal((int) $row['amount'], (string) $row['currency']),
                (string) $row['currency'],
                (string) $row['status'],
                (string) ($row['note'] ?? ''),
            ];
        }
        foreach ($this->db->fetchAll("SELECT * FROM `nx_partner_payouts` WHERE `partner_id` = ? AND `status` = 'paid' AND `paid_at` >= ? AND `paid_at` < ? ORDER BY `paid_at`", [$userId, $from, $to]) as $payout) {
            $rows[] = [substr((string) $payout['paid_at'], 0, 10), 'payout ' . $payout['reference'], '', '', Money::toDecimal(-(int) $payout['amount'], (string) $payout['currency']), (string) $payout['currency'], 'paid', 'IBAN ' . Iban::mask((string) $payout['iban_last4'])];
        }

        return Csv::build($rows);
    }

    /**
     * Admin overview of partners with their totals.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->fetchAll(
            "SELECT p.`user_id`, p.`status`, p.`consented_at`, p.`terms_version`, p.`iban_last4`, u.`email`, u.`first_name`, u.`last_name`, u.`company`,
                    COALESCE(SUM(CASE WHEN c.`status` = 'pending' THEN c.`amount` END), 0) AS pending,
                    COALESCE(SUM(CASE WHEN c.`status` = 'payable' THEN c.`amount` END), 0) AS payable,
                    COALESCE(SUM(CASE WHEN c.`status` = 'paid' THEN c.`amount` END), 0) AS paid
               FROM `nx_partners` p
               JOIN `nx_users` u ON u.`id` = p.`user_id`
          LEFT JOIN `nx_partner_commissions` c ON c.`partner_id` = p.`user_id`
           GROUP BY p.`user_id`, p.`status`, p.`consented_at`, p.`terms_version`, p.`iban_last4`, u.`email`, u.`first_name`, u.`last_name`, u.`company`
           ORDER BY p.`created_at` DESC LIMIT 500",
        );
    }

    /**
     * Payouts for staff, newest first; open ones first.
     *
     * @return list<array<string, mixed>>
     */
    public function allPayouts(?int $partnerId = null): array
    {
        return $this->db->fetchAll(
            "SELECT p.`id`, p.`partner_id`, p.`amount`, p.`currency`, p.`status`, p.`reference`, p.`iban_last4`, p.`bic`, p.`requested_at`, p.`approved_at`, p.`paid_at`, p.`bank_reference`, p.`note`, p.`sepa_exported_at`, u.`email`
               FROM `nx_partner_payouts` p JOIN `nx_users` u ON u.`id` = p.`partner_id`
              " . ($partnerId !== null ? 'WHERE p.`partner_id` = ' . $partnerId : '') . "
           ORDER BY FIELD(p.`status`, 'requested', 'approved') DESC, p.`id` DESC LIMIT 500",
        );
    }

    /** Staff suspend or reactivate a partner. */
    public function setStatus(int $userId, string $status, int $actorId, ?string $ip): bool
    {
        if (!in_array($status, [self::ACTIVE, self::SUSPENDED], true)) {
            throw new InvalidArgumentException('Unknown status.');
        }
        $changed = $this->db->run('UPDATE `nx_partners` SET `status` = ?, `updated_at` = ? WHERE `user_id` = ? AND `status` <> ?', [$status, $this->clock->nowString(), $userId, $status])->rowCount() > 0;
        if ($changed) {
            $this->audit->log($actorId, 'partner.status_changed', 'user', $userId, ['status' => $status], $ip);
        }

        return $changed;
    }

    /** Whether a referrer takes part in the program (cash commissions instead of the credit reward). */
    public function isActivePartner(int $userId): bool
    {
        return $this->db->fetchValue("SELECT `user_id` FROM `nx_partners` WHERE `user_id` = ? AND `status` = 'active'", [$userId]) !== null;
    }
}
