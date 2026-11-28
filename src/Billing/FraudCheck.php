<?php

declare(strict_types=1);

namespace Nebrix\Billing;

use DateTimeImmutable;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Mail\Mailer;
use Nebrix\Queue\JobQueue;
use Nebrix\Repository\UserRepository;
use Nebrix\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * Fraud check of paid orders before they are set up (setting fraud.enabled).
 *
 * Rules use only data the panel already has – no external lookups: throw-away
 * e-mail domains, payment country (card issuer / PayPal account) differing
 * from the billing country, many orders in a short time, several accounts
 * ordering from the same IP address, new accounts with high order values,
 * earlier chargebacks and earlier rejected orders. Each matching rule adds
 * points; from the review score on, the order waits in Admin → Order review
 * until staff approve it (it is then set up) or reject it (nothing is set up;
 * a refund is issued by staff through the payment).
 */
class FraudCheck
{
    public const STATUS_CLEAR = 'clear';
    public const STATUS_REVIEW = 'review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** @var list<string>|null */
    private ?array $disposable = null;

    public function __construct(
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
        private readonly UserRepository $users,
        private readonly JobQueue $jobs,
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
        private readonly string $listFile = __DIR__ . '/../../resources/fraud/disposable-domains.txt',
    ) {
    }

    /**
     * Called by the fulfilment job: whether the order may be set up now.
     * The first call assesses the order; held orders return false until staff
     * approve them.
     */
    public function clearForFulfilment(int $orderId): bool
    {
        if (!$this->settings->bool('fraud.enabled')) {
            return true;
        }
        $order = $this->db->fetchOne('SELECT * FROM `nx_orders` WHERE `id` = ?', [$orderId]);
        if ($order === null) {
            return true; // the fulfilment reports the missing order
        }
        $status = $order['fraud_status'];
        if ($status === null) {
            $result = $this->assess($order);
            $status = $result['score'] >= max(1, $this->settings->int('fraud.review_score') ?: 50) ? self::STATUS_REVIEW : self::STATUS_CLEAR;
            $updated = $this->db->run(
                'UPDATE `nx_orders` SET `fraud_score` = ?, `fraud_status` = ?, `fraud_reasons` = ?, `fraud_checked_at` = ? WHERE `id` = ? AND `fraud_status` IS NULL',
                [min(65535, $result['score']), $status, json_encode($result['reasons'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $this->clock->nowString(), $orderId],
            )->rowCount();
            if ($updated === 0) {
                $status = (string) $this->db->fetchValue('SELECT `fraud_status` FROM `nx_orders` WHERE `id` = ?', [$orderId]);
            } elseif ($status === self::STATUS_REVIEW) {
                $this->logger->warning('Order held for fraud review', ['order' => $orderId, 'score' => $result['score'], 'rules' => array_column($result['reasons'], 'rule')]);
                $this->notifyCustomer($order);
            }
        }

        return in_array($status, [self::STATUS_CLEAR, self::STATUS_APPROVED], true);
    }

    /**
     * @param array<string, mixed> $order
     * @return array{score: int, reasons: list<array{rule: string, points: int, detail: string}>}
     */
    public function assess(array $order): array
    {
        $reasons = [];
        $add = static function (string $rule, int $points, string $detail) use (&$reasons): void {
            $reasons[] = ['rule' => $rule, 'points' => $points, 'detail' => $detail];
        };
        $userId = (int) $order['user_id'];
        $user = $this->users->find($userId);
        $orderedAt = new DateTimeImmutable((string) $order['created_at']);
        $ip = is_string($order['ip'] ?? null) && $order['ip'] !== '' ? $order['ip'] : null;

        if ($user !== null) {
            $domain = mb_strtolower((string) substr(strrchr($user->email, '@') ?: '', 1));
            if ($domain !== '' && $this->isDisposable($domain)) {
                $add('disposable_email', 40, $domain);
            }
            $created = $user->attribute('created_at');
            $high = max(0, $this->settings->int('fraud.high_value_amount')) * 100;
            if (is_string($created) && new DateTimeImmutable($created) > $orderedAt->modify('-1 day') && $high > 0 && (int) $order['total'] >= $high) {
                $add('new_account_high_value', 20, Money::format((int) $order['total'], (string) $order['currency']));
            }
            if (trim((string) $user->attribute('last_name')) === '' || trim((string) $user->attribute('country')) === '') {
                $add('incomplete_billing_details', 10, '');
            }
        }

        $invoice = $this->db->fetchOne('SELECT `payment_country`, `tax_country`, `billing_details` FROM `nx_invoices` WHERE `order_id` = ? ORDER BY `id` LIMIT 1', [(int) $order['id']]);
        if ($invoice !== null && is_string($invoice['payment_country']) && $invoice['payment_country'] !== '') {
            $billing = strtoupper((string) ($invoice['tax_country'] ?? ''));
            if ($billing === '') {
                $snapshot = json_decode((string) ($invoice['billing_details'] ?? ''), true);
                $billing = is_array($snapshot) ? strtoupper(trim((string) ($snapshot['country'] ?? ''))) : '';
            }
            if ($billing !== '' && $billing !== strtoupper($invoice['payment_country'])) {
                $add('payment_country_mismatch', 25, $billing . ' ≠ ' . strtoupper($invoice['payment_country']));
            }
        }

        $recent = (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_orders` WHERE `user_id` = ? AND `created_at` BETWEEN ? AND ?', [$userId, $orderedAt->modify('-1 day')->format('Y-m-d H:i:s'), $orderedAt->format('Y-m-d H:i:s')]);
        if ($recent > 3) {
            $add('many_orders', 15, (string) $recent);
        }

        if ($ip !== null) {
            $accounts = (int) $this->db->fetchValue('SELECT COUNT(DISTINCT `user_id`) FROM `nx_orders` WHERE `ip` = ? AND `user_id` <> ? AND `created_at` >= ?', [$ip, $userId, $orderedAt->modify('-30 days')->format('Y-m-d H:i:s')]);
            if ($accounts >= 2) {
                $add('shared_ip', 25, (string) $accounts);
            }
            $foreignDisputes = (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM `nx_transactions` t WHERE t.`type` = 'dispute' AND t.`user_id` <> ? AND t.`user_id` IN (SELECT o.`user_id` FROM `nx_orders` o WHERE o.`ip` = ?)",
                [$userId, $ip],
            );
            if ($foreignDisputes > 0) {
                $add('ip_with_chargebacks', 30, (string) $foreignDisputes);
            }
        }

        if ((int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_transactions` WHERE `user_id` = ? AND `type` = 'dispute'", [$userId]) > 0) {
            $add('previous_chargeback', 60, '');
        }
        if ((int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_orders` WHERE `user_id` = ? AND `id` <> ? AND `fraud_status` = 'rejected'", [$userId, (int) $order['id']]) > 0) {
            $add('previously_rejected', 60, '');
        }

        return ['score' => array_sum(array_column($reasons, 'points')), 'reasons' => $reasons];
    }

    /**
     * Staff decision on a held order.
     *
     * @return bool false when the order is not waiting for review
     */
    public function decide(int $orderId, bool $approve, int $staffId): bool
    {
        $updated = $this->db->run(
            "UPDATE `nx_orders` SET `fraud_status` = ?, `fraud_reviewed_by` = ?, `fraud_reviewed_at` = ? WHERE `id` = ? AND `fraud_status` = 'review'",
            [$approve ? self::STATUS_APPROVED : self::STATUS_REJECTED, $staffId, $this->clock->nowString(), $orderId],
        )->rowCount();
        if ($updated === 0) {
            return false;
        }
        if ($approve) {
            $this->jobs->dispatch(InvoiceService::JOB_FULFIL_ORDER, ['order_id' => $orderId], InvoiceService::JOB_FULFIL_ORDER . ':' . $orderId . ':approved');
        }

        return true;
    }

    /**
     * @return list<array<string, mixed>> orders waiting for review and the latest decisions
     */
    public function queue(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT o.*, u.`email`, u.`first_name`, u.`last_name`, u.`company` FROM `nx_orders` o JOIN `nx_users` u ON u.`id` = o.`user_id`
             WHERE o.`fraud_status` IN ('review', 'approved', 'rejected') AND (o.`fraud_status` = 'review' OR o.`fraud_reviewed_at` >= ?)
             ORDER BY o.`fraud_status` = 'review' DESC, o.`id` DESC LIMIT 200",
            [$this->clock->now()->modify('-30 days')->format('Y-m-d H:i:s')],
        );
        foreach ($rows as &$row) {
            $reasons = json_decode((string) ($row['fraud_reasons'] ?? '[]'), true);
            $row['reasons'] = is_array($reasons) ? $reasons : [];
        }

        return $rows;
    }

    public function isDisposable(string $domain): bool
    {
        if ($this->disposable === null) {
            $lines = is_file($this->listFile) ? (file($this->listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
            $custom = preg_split('/[\s,;]+/', $this->settings->string('fraud.blocked_email_domains')) ?: [];
            $this->disposable = array_values(array_unique(array_filter(array_map(
                static fn (string $line): string => mb_strtolower(trim($line)),
                [...$lines, ...$custom],
            ), static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'))));
        }
        $domain = mb_strtolower(trim($domain, ". \t"));
        foreach ($this->disposable as $listed) {
            if ($domain === $listed || str_ends_with($domain, '.' . $listed)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $order */
    private function notifyCustomer(array $order): void
    {
        $user = $this->users->find((int) $order['user_id']);
        if ($user === null) {
            return;
        }
        $this->mailer->queue($user->email, $user->displayName(), 'order_review', [
            'name' => $user->displayName(),
            'order_number' => (string) $order['number'],
        ], (string) ($user->attribute('language') ?? 'en') ?: 'en');
    }
}
