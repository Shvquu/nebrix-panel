<?php

declare(strict_types=1);

namespace Nebrix\Finance\GiftCards;

use DateTimeImmutable;
use Nebrix\Audit\AuditLogger;
use Nebrix\Auth\User;
use Nebrix\Billing\CreditService;
use Nebrix\Billing\Money;
use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Mail\Mailer;
use Nebrix\Security\RateLimiter;
use Nebrix\Settings\SettingsRepository;
use Nebrix\View\Translator;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * Gift cards (credit vouchers).
 *
 * Codes have 16 characters from a 32-character alphabet (80 bits from
 * random_bytes). Only their SHA-256 hash and the last four characters are
 * stored; the full code is shown once to the staff member who created it
 * (and downloadable as CSV right then) or e-mailed to the buyer after a
 * confirmed payment. Redeeming books the value as account credit: single
 * use under a row lock, rate-limited per customer and network, audit-logged.
 */
class GiftCardService
{
    public const ACTIVE = 'active';
    public const REDEEMED = 'redeemed';
    public const VOID = 'void';

    /** Without easily confused characters (0/O, 1/I). */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const LENGTH = 16;
    public const MAX_QUANTITY = 500;

    /** Redemption attempts (counted before they are checked). */
    private const ATTEMPTS_PER_USER = 10;
    private const ATTEMPTS_PER_NETWORK = 30;
    private const ATTEMPT_WINDOW = 3600;

    public function __construct(
        private readonly Connection $db,
        private readonly CreditService $credit,
        private readonly SettingsRepository $settings,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
        private readonly Mailer $mailer,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function redeemEnabled(): bool
    {
        return $this->settings->bool('giftcard.enabled');
    }

    public function currency(): string
    {
        return strtoupper($this->settings->string('general.default_currency') ?: 'EUR');
    }

    /** A new random code, e.g. "K7QM-2ZXR-PL9D-WN4H". */
    public static function generateCode(): string
    {
        $code = '';
        foreach (str_split(random_bytes(self::LENGTH)) as $byte) {
            // 256 is a multiple of 32: every character is equally likely.
            $code .= self::ALPHABET[ord($byte) % 32];
        }

        return implode('-', str_split($code, 4));
    }

    /** Code as typed by a customer → canonical form (upper case, without separators), or null if malformed. */
    public static function normalize(string $code): ?string
    {
        $code = strtoupper((string) preg_replace('/[\s\-]+/', '', $code));

        return preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/', $code) === 1 ? $code : null;
    }

    public static function hash(string $normalized): string
    {
        return hash('sha256', 'gift-card:' . $normalized);
    }

    /**
     * Creates gift cards. The codes are returned once and never stored.
     *
     * @return array{batch: string, codes: list<array{id: int, code: string}>}
     */
    public function create(int $value, string $currency, ?DateTimeImmutable $expiresAt, int $quantity, ?string $note, ?int $actorId, string $source = 'admin', ?int $invoiceId = null, ?int $purchasedBy = null): array
    {
        if ($value < 1 || $value > 100_000_000) {
            throw new GiftCardException('The value must be greater than zero.');
        }
        if (strtoupper($currency) !== $this->currency()) {
            throw new GiftCardException('Gift cards are issued in the default currency, in which account credit is kept.');
        }
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new GiftCardException('Create between 1 and 500 gift cards at a time.');
        }
        $batch = bin2hex(random_bytes(8));
        $codes = [];
        $this->db->transaction(function () use ($value, $expiresAt, $quantity, $note, $actorId, $source, $invoiceId, $purchasedBy, $batch, &$codes): void {
            for ($i = 0; $i < $quantity; $i++) {
                for ($attempt = 0; ; $attempt++) {
                    $code = self::generateCode();
                    $normalized = (string) self::normalize($code);
                    try {
                        $id = $this->db->insert('nx_gift_cards', [
                            'code_hash' => self::hash($normalized),
                            'last4' => substr($normalized, -4),
                            'batch' => $batch,
                            'value' => $value,
                            'currency' => $this->currency(),
                            'status' => self::ACTIVE,
                            'source' => $source,
                            'invoice_id' => $invoiceId,
                            'purchased_by' => $purchasedBy,
                            'expires_at' => $expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                            'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
                            'created_by' => $actorId,
                            'created_at' => $this->clock->nowString(),
                        ]);
                        $codes[] = ['id' => $id, 'code' => $code];
                        break;
                    } catch (PDOException $exception) {
                        // A collision of 80-bit codes is practically impossible; try a new code once more.
                        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062 || $attempt >= 2 || $invoiceId !== null) {
                            throw $exception;
                        }
                    }
                }
            }
        });

        return ['batch' => $batch, 'codes' => $codes];
    }

    /**
     * Redeems a code for the customer: the value is added to the account
     * credit. Attempts are counted before the code is checked, so codes
     * cannot be found by trying.
     *
     * @return int credited amount (minor units)
     *
     * @throws GiftCardException
     */
    public function redeem(User $user, string $input, string $ip): int
    {
        if (!$this->redeemEnabled()) {
            throw new GiftCardException('Gift cards cannot be redeemed at the moment.');
        }
        $network = RateLimiter::network($ip);
        $byUser = $this->limiter->attempt('gift-card-redeem:user:' . $user->id, self::ATTEMPTS_PER_USER, self::ATTEMPT_WINDOW);
        $byNetwork = $this->limiter->attempt('gift-card-redeem:ip:' . $network, self::ATTEMPTS_PER_NETWORK, self::ATTEMPT_WINDOW);
        if (!$byUser || !$byNetwork) {
            $this->audit->log($user->id, 'gift_card.redeem_throttled', 'user', $user->id, [], $ip);

            throw new GiftCardException('Too many attempts. Please try again in an hour.');
        }

        $code = self::normalize($input);
        $invalid = 'This gift card code is not valid or has already been redeemed.';
        if ($code === null) {
            $this->audit->log($user->id, 'gift_card.redeem_failed', 'user', $user->id, ['reason' => 'malformed'], $ip);

            throw new GiftCardException($invalid);
        }

        /** @var array{0: int|null, 1: string|null, 2: int|null} $result */
        $result = $this->db->transaction(function () use ($user, $code): array {
            $card = $this->db->fetchOne('SELECT * FROM `nx_gift_cards` WHERE `code_hash` = ? FOR UPDATE', [self::hash($code)]);
            if ($card === null || $card['status'] !== self::ACTIVE) {
                return [null, $card === null ? 'unknown' : (string) $card['status'], $card !== null ? (int) $card['id'] : null];
            }
            if ($card['expires_at'] !== null && (string) $card['expires_at'] < $this->clock->nowString()) {
                return [null, 'expired', (int) $card['id']];
            }
            if (strtoupper((string) $card['currency']) !== $this->currency()) {
                return [null, 'currency', (int) $card['id']];
            }
            $this->db->update('nx_gift_cards', ['status' => self::REDEEMED, 'redeemed_by' => $user->id, 'redeemed_at' => $this->clock->nowString()], ['id' => (int) $card['id']]);
            $this->credit->adjust($user->id, (int) $card['value'], 'Gift card ****' . $card['last4'], 'gift_card', (string) $card['id']);

            return [(int) $card['value'], null, (int) $card['id']];
        });
        [$value, $reason, $cardId] = $result;
        if ($value === null) {
            $this->audit->log($user->id, 'gift_card.redeem_failed', 'gift_card', $cardId, ['reason' => $reason], $ip);

            throw new GiftCardException($reason === 'expired' ? 'This gift card has expired.' : $invalid);
        }
        $this->audit->log($user->id, 'gift_card.redeemed', 'gift_card', $cardId, ['value' => $value], $ip);

        return $value;
    }

    /** Staff void an unused gift card (e.g. lost or issued by mistake). */
    public function void(int $id, int $actorId, string $reason, ?string $ip): bool
    {
        $changed = $this->db->run(
            "UPDATE `nx_gift_cards` SET `status` = 'void', `voided_by` = ?, `voided_at` = ?, `void_reason` = ? WHERE `id` = ? AND `status` = 'active'",
            [$actorId, $this->clock->nowString(), mb_substr($reason, 0, 255), $id],
        )->rowCount() > 0;
        if ($changed) {
            $this->audit->log($actorId, 'gift_card.voided', 'gift_card', $id, ['reason' => $reason], $ip);
        }

        return $changed;
    }

    /**
     * Issues the gift card of a paid purchase invoice and e-mails the code to
     * the buyer. Called inside the payment transaction (InvoiceService::markPaid),
     * i.e. only after a payment was confirmed by the provider or recorded by
     * staff. Idempotent per invoice.
     *
     * @param array<string, mixed> $invoice
     */
    public function issueForInvoice(array $invoice): void
    {
        $invoiceId = (int) $invoice['id'];
        if ($this->db->fetchValue('SELECT `id` FROM `nx_gift_cards` WHERE `invoice_id` = ?', [$invoiceId]) !== null) {
            return;
        }
        $months = max(0, $this->settings->int('giftcard.validity_months'));
        $expires = $months > 0 ? $this->clock->now()->modify('+' . $months . ' months')->setTime(23, 59, 59) : null;
        $created = $this->create((int) $invoice['subtotal'], (string) $invoice['currency'], $expires, 1, 'Purchase ' . $invoice['number'], null, 'purchase', $invoiceId, (int) $invoice['user_id']);
        $card = $created['codes'][0];
        $this->audit->log(null, 'gift_card.purchased', 'gift_card', $card['id'], ['invoice' => $invoiceId, 'value' => (int) $invoice['subtotal']]);

        $buyer = $this->db->fetchOne('SELECT `email`, `first_name`, `last_name`, `language` FROM `nx_users` WHERE `id` = ?', [(int) $invoice['user_id']]);
        if ($buyer === null) {
            $this->logger->error('Gift card bought by an unknown customer', ['invoice' => $invoiceId]);

            return;
        }
        $locale = (string) ($buyer['language'] ?? 'en') ?: 'en';
        $name = trim((string) $buyer['first_name'] . ' ' . (string) $buyer['last_name']);
        $this->mailer->queue((string) $buyer['email'], $name !== '' ? $name : null, 'gift_card_purchased', [
            'name' => $name !== '' ? $name : (string) $buyer['email'],
            'code' => $card['code'],
            'value' => Money::format((int) $invoice['subtotal'], (string) $invoice['currency'], $locale),
            'valid_until' => $expires !== null ? Translator::date($locale, $expires) : '–',
            'redeem_url' => rtrim($this->config->string('app.url'), '/') . '/account/gift-cards',
            'invoice_number' => (string) $invoice['number'],
        ], $locale);
    }

    /**
     * The purchase was refunded or charged back: an unused card is voided;
     * a redeemed card's credit is taken back from the redeeming account (the
     * balance may become negative, which blocks new orders until settled).
     * Runs inside the refund/dispute transaction.
     *
     * @param array<string, mixed> $invoice
     */
    public function reverseForInvoice(array $invoice, string $reason): void
    {
        $card = $this->db->fetchOne('SELECT * FROM `nx_gift_cards` WHERE `invoice_id` = ? FOR UPDATE', [(int) $invoice['id']]);
        if ($card === null || $card['status'] === self::VOID) {
            return;
        }
        if ($card['status'] === self::REDEEMED && $card['redeemed_by'] !== null) {
            $this->credit->adjust((int) $card['redeemed_by'], -(int) $card['value'], sprintf('%s (gift card ****%s, invoice %s)', $reason, $card['last4'], $invoice['number']), 'gift_card', (string) $card['id'], null, true);
            $this->logger->warning('Redeemed gift card reversed', ['gift_card' => $card['id'], 'invoice' => $invoice['id']]);
        }
        $this->db->update('nx_gift_cards', ['status' => self::VOID, 'voided_at' => $this->clock->nowString(), 'void_reason' => mb_substr($reason . ($card['status'] === self::REDEEMED ? ' after redemption' : ''), 0, 255)], ['id' => (int) $card['id']]);
        $this->audit->log(null, 'gift_card.reversed', 'gift_card', (int) $card['id'], ['invoice' => (int) $invoice['id'], 'was' => $card['status']]);
    }

    /** Whether the gift card bought with this invoice was already redeemed (refunds are refused then). */
    public function redeemedForInvoice(int $invoiceId): bool
    {
        return $this->db->fetchValue("SELECT `id` FROM `nx_gift_cards` WHERE `invoice_id` = ? AND `status` = 'redeemed'", [$invoiceId]) !== null;
    }

    /**
     * @param array{status?: string, search?: string} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $filters, int $page, int $perPage = 50): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (in_array($filters['status'] ?? '', [self::ACTIVE, self::REDEEMED, self::VOID], true)) {
            $where[] = 'g.`status` = ?';
            $params[] = $filters['status'];
        }
        $search = strtoupper(trim((string) ($filters['search'] ?? '')));
        if ($search !== '') {
            $where[] = '(g.`last4` = ? OR g.`batch` = ? OR g.`note` LIKE ?)';
            array_push($params, substr((string) preg_replace('/[^A-Z0-9]/', '', $search), -4), strtolower($search), '%' . addcslashes($search, '%_\\') . '%');
        }
        $sql = implode(' AND ', $where);

        return [
            'items' => $this->db->fetchAll(
                "SELECT g.*, r.`email` AS redeemed_email, b.`email` AS buyer_email, i.`number` AS invoice_number
                   FROM `nx_gift_cards` g
              LEFT JOIN `nx_users` r ON r.`id` = g.`redeemed_by`
              LEFT JOIN `nx_users` b ON b.`id` = g.`purchased_by`
              LEFT JOIN `nx_invoices` i ON i.`id` = g.`invoice_id`
                  WHERE {$sql} ORDER BY g.`id` DESC LIMIT ? OFFSET ?",
                [...$params, $perPage, max(0, ($page - 1) * $perPage)],
            ),
            'total' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_gift_cards` g WHERE {$sql}", $params),
        ];
    }

    /**
     * Gift cards a customer bought or redeemed (without codes).
     *
     * @return list<array<string, mixed>>
     */
    public function forCustomer(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT g.`id`, g.`last4`, g.`value`, g.`currency`, g.`status`, g.`expires_at`, g.`redeemed_at`, g.`created_at`, g.`purchased_by`, g.`redeemed_by`, i.`id` AS invoice_id, i.`number` AS invoice_number
               FROM `nx_gift_cards` g LEFT JOIN `nx_invoices` i ON i.`id` = g.`invoice_id`
              WHERE g.`purchased_by` = ? OR g.`redeemed_by` = ? ORDER BY g.`id` DESC LIMIT 100',
            [$userId, $userId],
        );
    }
}
