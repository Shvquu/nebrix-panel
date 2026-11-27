<?php

declare(strict_types=1);

namespace Nebrix\Referral;

use Nebrix\Audit\AuditLogger;
use Nebrix\Auth\User;
use Nebrix\Billing\CreditService;
use Nebrix\Billing\InvoiceRepository;
use Nebrix\Billing\Money;
use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Mail\Mailer;
use Nebrix\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * "Refer a friend": every customer has a personal link (/r/{code}). A
 * customer who registers through it is linked to the referrer.
 *
 * pending   → the referred customer pays an order or renewal invoice with
 *             real money (Stripe, PayPal, recorded bank transfer – not
 *             account credit and not free orders) of at least the minimum
 * qualified → after the holding period, if that invoice was not refunded,
 *             the referrer (and optionally the new customer) receives
 *             account credit
 * rewarded
 *
 * Sign-ups from an address the referrer recently signed in from are held
 * for review ("review") and only continue after a staff member approves
 * them. A monthly limit per referrer caps the damage of fake accounts.
 */
class ReferralService
{
    public const PENDING = 'pending';
    public const REVIEW = 'review';
    public const QUALIFIED = 'qualified';
    public const REWARDED = 'rewarded';
    public const REJECTED = 'rejected';

    public const STATUSES = [self::PENDING, self::REVIEW, self::QUALIFIED, self::REWARDED, self::REJECTED];

    /** Cookie that remembers the code between the link and the registration. */
    public const COOKIE = 'nx_ref';
    public const COOKIE_DAYS = 30;

    /** Without easily confused characters (0/O, 1/I). */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Payments that do not count: account credit and free orders. */
    private const EXCLUDED_GATEWAYS = ['credit', 'free'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly SettingsRepository $settings,
        private readonly CreditService $credit,
        private readonly Mailer $mailer,
        private readonly AuditLogger $audit,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('referral.enabled');
    }

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function isValidCode(string $code): bool
    {
        return preg_match('/^[' . self::ALPHABET . ']{8}$/', $code) === 1;
    }

    /** The customer's code; created on first use. */
    public function codeFor(int $userId): string
    {
        $code = $this->db->fetchValue('SELECT `referral_code` FROM `nx_users` WHERE `id` = ?', [$userId]);
        if (is_string($code) && $code !== '') {
            return $code;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = '';
            for ($i = 0; $i < 8; $i++) {
                $candidate .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            if ($this->db->fetchValue('SELECT `id` FROM `nx_users` WHERE `referral_code` = ?', [$candidate]) !== null) {
                continue;
            }
            try {
                $this->db->run('UPDATE `nx_users` SET `referral_code` = ? WHERE `id` = ? AND `referral_code` IS NULL', [$candidate, $userId]);
            } catch (\PDOException $exception) {
                // Taken in the meantime (unique key): try another one.
                if ((string) $exception->getCode() === '23000') {
                    continue;
                }
                throw $exception;
            }
            $code = $this->db->fetchValue('SELECT `referral_code` FROM `nx_users` WHERE `id` = ?', [$userId]);
            if (is_string($code) && $code !== '') {
                return $code;
            }
        }

        throw new \RuntimeException('No referral code could be created.');
    }

    public function link(string $code): string
    {
        return rtrim($this->config->string('app.url'), '/') . '/r/' . rawurlencode($code);
    }

    /**
     * The customer a code belongs to, if referrals are enabled and the
     * account can receive rewards.
     */
    public function referrerByCode(string $code): ?int
    {
        $code = self::normalize($code);
        if (!$this->enabled() || !self::isValidCode($code)) {
            return null;
        }
        $id = $this->db->fetchValue('SELECT `id` FROM `nx_users` WHERE `referral_code` = ? AND `status` = ?', [$code, User::STATUS_ACTIVE]);

        return $id !== null ? (int) $id : null;
    }

    /**
     * Links a new customer to the referrer (called right after registration).
     * Unknown or invalid codes are ignored: the registration itself must not
     * fail because of a referral.
     */
    public function attach(int $referredId, string $code, string $ip): void
    {
        $referrerId = $this->referrerByCode($code);
        if ($referrerId === null || $referrerId === $referredId) {
            return;
        }
        if ($this->db->fetchValue('SELECT `id` FROM `nx_referrals` WHERE `referred_id` = ?', [$referredId]) !== null) {
            return;
        }

        // Same network as the referrer's recent sign-ins: a person may be referring themselves.
        $since = $this->clock->now()->modify('-90 days')->format('Y-m-d H:i:s');
        $sameNetwork = $ip !== '' && (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM `nx_login_history` WHERE `user_id` = ? AND `success` = 1 AND `ip` = ? AND `created_at` >= ?',
            [$referrerId, $ip, $since],
        ) > 0;
        $status = $sameNetwork ? self::REVIEW : self::PENDING;

        try {
            $this->db->transaction(function () use ($referrerId, $referredId, $ip, $status, $sameNetwork): void {
                $this->db->insert('nx_referrals', [
                    'referrer_id' => $referrerId,
                    'referred_id' => $referredId,
                    'status' => $status,
                    'signup_ip' => $ip !== '' ? mb_substr($ip, 0, 45) : null,
                    'note' => $sameNetwork ? 'Signed up from an address the referrer used.' : null,
                    'created_at' => $this->clock->nowString(),
                ]);
                $this->db->update('nx_users', ['referred_by' => $referrerId], ['id' => $referredId]);
            });
        } catch (Throwable $exception) {
            // The account exists already; a failed referral must not turn the registration into an error page.
            $this->logger->error('Referral could not be recorded', ['referred' => $referredId, 'referrer' => $referrerId, 'message' => $exception->getMessage()]);

            return;
        }
        $this->audit->log($referredId, 'referral.attached', 'user', $referrerId, ['status' => $status], $ip);
    }

    /**
     * Statistics and the list for the customer page. The referred
     * customers are not named (their data is not the referrer's business).
     *
     * @return array{rows: list<array<string, mixed>>, rewarded: int, open: int, earned: int}
     */
    public function overview(int $referrerId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `id`, `status`, `reward`, `currency`, `created_at`, `qualified_at`, `rewarded_at` FROM `nx_referrals` WHERE `referrer_id` = ? ORDER BY `id` DESC LIMIT 200',
            [$referrerId],
        );
        $rewarded = 0;
        $open = 0;
        $earned = 0;
        foreach ($rows as $row) {
            if ($row['status'] === self::REWARDED) {
                $rewarded++;
                $earned += (int) $row['reward'];
            } elseif (in_array($row['status'], [self::PENDING, self::REVIEW, self::QUALIFIED], true)) {
                $open++;
            }
        }

        return ['rows' => $rows, 'rewarded' => $rewarded, 'open' => $open, 'earned' => $earned];
    }

    /**
     * Admin list.
     *
     * @return list<array<string, mixed>>
     */
    public function all(?string $status): array
    {
        $where = $status !== null && in_array($status, self::STATUSES, true) ? 'WHERE r.`status` = ?' : '';

        return $this->db->fetchAll(
            "SELECT r.*, a.`email` AS referrer_email, b.`email` AS referred_email, i.`number` AS invoice_number
               FROM `nx_referrals` r
               JOIN `nx_users` a ON a.`id` = r.`referrer_id`
               JOIN `nx_users` b ON b.`id` = r.`referred_id`
          LEFT JOIN `nx_invoices` i ON i.`id` = r.`invoice_id`
              {$where} ORDER BY (r.`status` = 'review') DESC, r.`id` DESC LIMIT 500",
            $where !== '' ? [$status] : [],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_referrals` WHERE `id` = ?', [$id]);
    }

    /** Staff confirmed a held referral: it continues like any other. */
    public function approve(int $id, int $actorId, string $ip): bool
    {
        $changed = $this->db->run(
            "UPDATE `nx_referrals` SET `status` = 'pending', `note` = 'Approved after review.' WHERE `id` = ? AND `status` = 'review'",
            [$id],
        )->rowCount() > 0;
        if ($changed) {
            $this->audit->log($actorId, 'referral.approved', 'referral', $id, [], $ip);
        }

        return $changed;
    }

    /** Staff rejected a referral that has not been rewarded yet. */
    public function reject(int $id, int $actorId, string $ip, string $reason): bool
    {
        $changed = $this->db->run(
            "UPDATE `nx_referrals` SET `status` = 'rejected', `note` = ? WHERE `id` = ? AND `status` IN ('pending', 'review', 'qualified')",
            [mb_substr($reason !== '' ? $reason : 'Rejected by staff.', 0, 255), $id],
        )->rowCount() > 0;
        if ($changed) {
            $this->audit->log($actorId, 'referral.rejected', 'referral', $id, ['reason' => $reason], $ip);
        }

        return $changed;
    }

    /**
     * Scheduler task: qualifies, rewards and rechecks referrals.
     *
     * @return array{qualified: int, rewarded: int, reset: int, reversed: int}
     */
    public function process(): array
    {
        $result = ['qualified' => 0, 'rewarded' => 0, 'reset' => 0, 'reversed' => 0];
        // The sign-up address is only needed for the review; it is kept as long as the login history.
        $retention = max(7, $this->settings->int('system.login_history_retention_days'));
        $this->db->run(
            'UPDATE `nx_referrals` SET `signup_ip` = NULL WHERE `signup_ip` IS NOT NULL AND `created_at` < ?',
            [$this->clock->now()->modify('-' . $retention . ' days')->format('Y-m-d H:i:s')],
        );
        // Also while the feature is switched off: credited rewards must follow their payment.
        $result['reversed'] = $this->reverseLostRewards();
        if (!$this->enabled()) {
            return $result;
        }
        $currency = $this->settings->string('general.default_currency') ?: 'EUR';
        $minimum = $this->amount('referral.min_amount', $currency);

        foreach ($this->db->fetchAll("SELECT * FROM `nx_referrals` WHERE `status` = 'pending' ORDER BY `id` LIMIT 500") as $referral) {
            $invoice = $this->qualifyingInvoice($referral, $currency, $minimum);
            if ($invoice === null) {
                continue;
            }
            $this->db->run(
                "UPDATE `nx_referrals` SET `status` = 'qualified', `invoice_id` = ?, `qualified_at` = ? WHERE `id` = ? AND `status` = 'pending'",
                [$invoice, $this->clock->nowString(), $referral['id']],
            );
            $result['qualified']++;
        }

        $holdUntil = $this->clock->now()->modify('-' . max(0, $this->settings->int('referral.holding_days')) . ' days')->format('Y-m-d H:i:s');
        foreach ($this->db->fetchAll("SELECT * FROM `nx_referrals` WHERE `status` = 'qualified' AND `qualified_at` <= ? ORDER BY `qualified_at` LIMIT 500", [$holdUntil]) as $referral) {
            try {
                $outcome = $this->reward($referral, $currency);
            } catch (Throwable $exception) {
                // One failing referral must not block the others; it is retried on the next run.
                $this->logger->error('Referral reward failed', ['referral' => $referral['id'], 'message' => $exception->getMessage()]);
                continue;
            }
            if ($outcome === 'rewarded') {
                $result['rewarded']++;
            } elseif ($outcome === 'reset') {
                $result['reset']++;
            }
        }

        return $result;
    }

    /**
     * The qualifying payment still stands: paid, nothing refunded, no chargeback.
     *
     * @param array<string, mixed> $invoice
     */
    private function paymentStands(array $invoice): bool
    {
        return $invoice['status'] === InvoiceRepository::STATUS_PAID
            && (int) $invoice['amount_refunded'] === 0
            && $this->db->fetchValue("SELECT `id` FROM `nx_payment_events` WHERE `invoice_id` = ? AND `event_type` = 'payment.disputed' LIMIT 1", [(int) $invoice['id']]) === null;
    }

    /**
     * Rewards whose qualifying payment was refunded, cancelled or charged
     * back afterwards are taken back from the credit balances.
     */
    private function reverseLostRewards(): int
    {
        $reversed = 0;
        $candidates = $this->db->fetchAll(
            "SELECT r.`id` FROM `nx_referrals` r JOIN `nx_invoices` i ON i.`id` = r.`invoice_id`
              WHERE r.`status` = 'rewarded' AND (i.`status` <> 'paid' OR i.`amount_refunded` > 0
                    OR EXISTS (SELECT 1 FROM `nx_payment_events` e WHERE e.`invoice_id` = i.`id` AND e.`event_type` = 'payment.disputed'))
              LIMIT 200",
        );
        foreach ($candidates as $candidate) {
            $this->db->transaction(function () use ($candidate, &$reversed): void {
                $row = $this->db->fetchOne('SELECT * FROM `nx_referrals` WHERE `id` = ? FOR UPDATE', [$candidate['id']]);
                if ($row === null || $row['status'] !== self::REWARDED) {
                    return;
                }
                if ((int) $row['reward'] > 0) {
                    $this->credit->adjust((int) $row['referrer_id'], -(int) $row['reward'], 'Referral reward reversed', 'referral', (string) $row['id'], null, true);
                }
                if ((int) $row['welcome'] > 0) {
                    $this->credit->adjust((int) $row['referred_id'], -(int) $row['welcome'], 'Referral welcome credit reversed', 'referral', (string) $row['id'], null, true);
                }
                $this->db->update('nx_referrals', ['status' => self::REJECTED, 'note' => 'Reward reversed: the qualifying payment was refunded or charged back.'], ['id' => $row['id']]);
                $reversed++;
            });
            $this->audit->log(null, 'referral.reward_reversed', 'referral', (int) $candidate['id']);
        }

        return $reversed;
    }

    /**
     * @param array<string, mixed> $referral
     */
    private function qualifyingInvoice(array $referral, string $currency, int $minimum): ?int
    {
        $excluded = implode(',', array_map(static fn (string $gateway): string => "'" . $gateway . "'", self::EXCLUDED_GATEWAYS));
        $id = $this->db->fetchValue(
            "SELECT i.`id` FROM `nx_invoices` i
              WHERE i.`user_id` = ? AND i.`type` IN (?, ?) AND i.`status` = ? AND i.`amount_refunded` = 0 AND i.`paid_at` >= ?
                AND (? = 0 OR (i.`currency` = ? AND i.`subtotal` >= ?))
                AND EXISTS (SELECT 1 FROM `nx_transactions` t WHERE t.`invoice_id` = i.`id` AND t.`type` = 'payment' AND t.`status` = 'completed'
                               AND t.`amount` > 0 AND t.`gateway` NOT IN ({$excluded}))
                AND NOT EXISTS (SELECT 1 FROM `nx_payment_events` e WHERE e.`invoice_id` = i.`id` AND e.`event_type` = 'payment.disputed')
              ORDER BY i.`paid_at`, i.`id` LIMIT 1",
            [
                $referral['referred_id'], InvoiceRepository::TYPE_ORDER, InvoiceRepository::TYPE_RENEWAL, InvoiceRepository::STATUS_PAID,
                $referral['created_at'], $minimum, $currency, $minimum,
            ],
        );

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param array<string, mixed> $referral
     * @return 'rewarded'|'reset'|'waiting'
     */
    private function reward(array $referral, string $currency): string
    {
        $reward = $this->amount('referral.reward', $currency);
        $welcome = $this->amount('referral.welcome', $currency);

        /** @var array{0: 'rewarded'|'reset'|'waiting', 1: int} $result */
        $result = $this->db->transaction(function () use ($referral, $currency, $reward, $welcome): array {
            $row = $this->db->fetchOne('SELECT * FROM `nx_referrals` WHERE `id` = ? FOR UPDATE', [$referral['id']]);
            if ($row === null || $row['status'] !== self::QUALIFIED) {
                return ['waiting', 0];
            }
            // The qualifying payment must still stand; otherwise the referral waits for another one.
            $invoice = $this->db->fetchOne('SELECT `id`, `status`, `amount_refunded` FROM `nx_invoices` WHERE `id` = ?', [$row['invoice_id']]);
            if ($invoice === null || !$this->paymentStands($invoice)) {
                $this->db->run(
                    "UPDATE `nx_referrals` SET `status` = 'pending', `invoice_id` = NULL, `qualified_at` = NULL, `note` = 'The qualifying payment was refunded or cancelled.' WHERE `id` = ?",
                    [$row['id']],
                );

                return ['reset', 0];
            }
            $accounts = $this->db->fetchAll('SELECT `id`, `status` FROM `nx_users` WHERE `id` IN (?, ?) FOR UPDATE', [$row['referrer_id'], $row['referred_id']]);
            $status = array_column($accounts, 'status', 'id');
            if (($status[$row['referrer_id']] ?? null) !== User::STATUS_ACTIVE || ($status[$row['referred_id']] ?? null) !== User::STATUS_ACTIVE) {
                // A suspended account (either side) waits until staff decide; closing an account already rejected the referral.
                return ['waiting', 0];
            }
            $monthStart = $this->clock->now()->format('Y-m-01 00:00:00');
            $thisMonth = (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM `nx_referrals` WHERE `referrer_id` = ? AND `status` = 'rewarded' AND `rewarded_at` >= ?",
                [$row['referrer_id'], $monthStart],
            );
            if ($thisMonth >= max(1, $this->settings->int('referral.monthly_limit'))) {
                return ['waiting', 0];
            }

            if ($reward > 0) {
                $this->credit->adjust((int) $row['referrer_id'], $reward, 'Referral reward', 'referral', (string) $row['id']);
            }
            $welcomeGranted = $welcome;
            if ($welcomeGranted > 0) {
                $this->credit->adjust((int) $row['referred_id'], $welcomeGranted, 'Referral welcome credit', 'referral', (string) $row['id']);
            }
            $this->db->update('nx_referrals', [
                'status' => self::REWARDED,
                'reward' => $reward,
                'welcome' => $welcomeGranted,
                'currency' => $currency,
                'rewarded_at' => $this->clock->nowString(),
            ], ['id' => $row['id']]);

            return ['rewarded', $welcomeGranted];
        });
        [$outcome, $welcomeGranted] = $result;

        if ($outcome === 'rewarded') {
            $this->audit->log(null, 'referral.rewarded', 'referral', (int) $referral['id'], ['reward' => $reward, 'welcome' => $welcomeGranted, 'currency' => $currency]);
            $this->notify((int) $referral['referrer_id'], 'referral_reward', $reward, $currency);
            $this->notify((int) $referral['referred_id'], 'referral_welcome', $welcomeGranted, $currency);
        }

        return $outcome;
    }

    private function notify(int $userId, string $template, int $amount, string $currency): void
    {
        if ($amount <= 0) {
            return;
        }
        $user = $this->db->fetchOne('SELECT `email`, `first_name`, `last_name`, `language`, `status` FROM `nx_users` WHERE `id` = ?', [$userId]);
        if ($user === null || $user['status'] !== User::STATUS_ACTIVE) {
            return;
        }
        $name = trim((string) $user['first_name'] . ' ' . (string) $user['last_name']);
        $locale = (string) ($user['language'] ?? 'en');
        $this->mailer->queue((string) $user['email'], $name !== '' ? $name : null, $template, [
            'name' => $name !== '' ? $name : (string) $user['email'],
            'amount' => Money::format($amount, $currency, $locale),
            'referrals_url' => rtrim($this->config->string('app.url'), '/') . '/account/referrals',
            'invoices_url' => rtrim($this->config->string('app.url'), '/') . '/invoices',
        ]);
    }

    /** A decimal amount setting in minor units (0 when empty or invalid). */
    private function amount(string $key, string $currency): int
    {
        $value = trim($this->settings->string($key));
        if ($value === '') {
            return 0;
        }
        try {
            return max(0, Money::parse($value, $currency));
        } catch (\InvalidArgumentException) {
            $this->logger->warning('Invalid referral amount setting', ['setting' => $key]);

            return 0;
        }
    }
}
