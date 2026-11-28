<?php

declare(strict_types=1);

namespace Nebrix\Finance\Bank;

use InvalidArgumentException;
use Nebrix\Audit\AuditLogger;
use Nebrix\Billing\InvoiceRepository;
use Nebrix\Billing\InvoiceService;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Payment\PaymentService;
use Nebrix\Repository\UserRepository;
use Nebrix\Settings\SettingsRepository;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Bank statement import and matching of incoming transfers to invoices.
 *
 * An incoming (credit) entry is booked automatically only when its
 * remittance text names exactly one invoice, that invoice is open, and the
 * amount and currency equal the invoice total. The payment goes through
 * PaymentService::recordPayment() like a manual payment recorded by staff
 * (invoice paid, confirmation e-mail, provisioning or renewal). Everything
 * else – partial and over-payments, several or unknown invoice numbers,
 * invoices that are no longer open – is put on the review list. Outgoing
 * (debit) entries are never booked.
 *
 * Duplicates: a file is imported once (SHA-256), an entry once (bank
 * reference or content hash, see StatementEntry::hash()); bookings use a
 * reference per entry, so the payment table rejects a second booking too.
 */
class BankReconciliation
{
    public const GATEWAY = 'bank';

    public const MATCHED = 'matched';
    public const ASSIGNED = 'assigned';
    public const CREDITED = 'credited';
    public const REVIEW = 'review';
    public const IGNORED = 'ignored';
    public const DEBIT = 'debit';

    public const STATUSES = [self::REVIEW, self::MATCHED, self::ASSIGNED, self::CREDITED, self::IGNORED, self::DEBIT];

    public function __construct(
        private readonly Connection $db,
        private readonly Camt053Parser $camt,
        private readonly BankCsvParser $csv,
        private readonly InvoiceReferenceFinder $references,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceService $invoiceService,
        private readonly PaymentService $payments,
        private readonly UserRepository $users,
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function maxBytes(): int
    {
        return max(64, $this->settings->int('bank.max_upload_kb') ?: 5120) * 1024;
    }

    /**
     * Imports a statement file.
     *
     * @param array<string, string>|null $mapping CSV column mapping (null for CAMT.053)
     * @return array{import_id: int, entries: int, duplicates: int, matched: int, review: int, debit: int, skipped: int}
     *
     * @throws InvalidArgumentException for invalid or already imported files
     */
    public function import(string $content, string $filename, string $format, ?array $mapping, ?string $profileName, int $actorId, ?string $ip): array
    {
        if ($content === '' || strlen($content) > $this->maxBytes()) {
            throw new InvalidArgumentException('The file is empty or larger than the allowed size.');
        }
        $sha = hash('sha256', $content);
        if ($this->db->fetchValue('SELECT `id` FROM `nx_bank_imports` WHERE `file_sha256` = ?', [$sha]) !== null) {
            throw new InvalidArgumentException('This file has already been imported.');
        }
        $currency = strtoupper($this->settings->string('general.default_currency') ?: 'EUR');
        if ($format === 'camt053') {
            $parsed = $this->camt->parse($content);
        } elseif ($format === 'csv' && $mapping !== null) {
            $parsed = $this->csv->parse($content, $mapping, $currency) + ['account' => '', 'statement' => ''];
        } else {
            throw new InvalidArgumentException('Unknown file format.');
        }

        $result = ['import_id' => 0, 'entries' => 0, 'duplicates' => 0, 'matched' => 0, 'review' => 0, 'debit' => 0, 'skipped' => $parsed['skipped']];
        $result['import_id'] = $this->db->transaction(function () use ($parsed, $sha, $filename, $format, $profileName, $actorId, &$result): int {
            $now = $this->clock->nowString();
            try {
                $importId = $this->db->insert('nx_bank_imports', [
                    'format' => $format,
                    'profile' => $profileName !== null ? mb_substr($profileName, 0, 100) : null,
                    'filename' => mb_substr((string) preg_replace('/[^\w.\- ]/u', '_', $filename), 0, 150) ?: 'statement',
                    'file_sha256' => $sha,
                    'account_iban' => $parsed['account'] !== '' ? $parsed['account'] : null,
                    'statement_id' => $parsed['statement'] !== '' ? $parsed['statement'] : null,
                    'created_by' => $actorId,
                    'created_at' => $now,
                ]);
            } catch (PDOException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                    throw new InvalidArgumentException('This file has already been imported.');
                }
                throw $exception;
            }
            $occurrences = [];
            foreach ($parsed['entries'] as $entry) {
                $key = $entry->contentKey($parsed['account']);
                $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
                $hash = $entry->hash($parsed['account'], $occurrences[$key]);
                if ($this->db->fetchValue('SELECT `id` FROM `nx_bank_entries` WHERE `entry_hash` = ?', [$hash]) !== null) {
                    $result['duplicates']++;
                    continue;
                }
                $this->db->insert('nx_bank_entries', [
                    'import_id' => $importId,
                    'entry_hash' => $hash,
                    'entry_reference' => $entry->reference,
                    'booking_date' => $entry->bookingDate,
                    'value_date' => $entry->valueDate,
                    'direction' => $entry->direction,
                    'amount' => $entry->amount,
                    'currency' => $entry->currency,
                    'counterparty_name' => $entry->counterpartyName !== '' ? $entry->counterpartyName : null,
                    'counterparty_iban' => $entry->counterpartyIban !== '' ? $entry->counterpartyIban : null,
                    'remittance' => $entry->remittance !== '' ? $entry->remittance : null,
                    'status' => $entry->direction === StatementEntry::DEBIT ? self::DEBIT : self::REVIEW,
                    'reason' => $entry->reversal ? 'reversal' : null,
                    'created_at' => $now,
                ]);
                $result['entries']++;
            }

            return $importId;
        });

        // Matching and booking per entry, each in its own transaction (one problem does not undo the import).
        foreach ($this->db->fetchAll('SELECT * FROM `nx_bank_entries` WHERE `import_id` = ? ORDER BY `id`', [$result['import_id']]) as $entry) {
            if ($entry['status'] === self::DEBIT) {
                $result['debit']++;
                continue;
            }
            $status = $this->process($entry, $actorId, $ip);
            $result[$status === self::MATCHED ? 'matched' : 'review']++;
        }
        $this->db->update('nx_bank_imports', ['entries' => $result['entries'], 'duplicates' => $result['duplicates'], 'matched' => $result['matched'], 'review' => $result['review']], ['id' => $result['import_id']]);
        $this->audit->log($actorId, 'bank.imported', 'bank_import', $result['import_id'], ['format' => $format, 'entries' => $result['entries'], 'duplicates' => $result['duplicates'], 'matched' => $result['matched'], 'review' => $result['review']], $ip);

        return $result;
    }

    /**
     * Matching result of an incoming entry.
     *
     * @param array<string, mixed> $entry
     * @return array{status: 'matched'|'review', reason: string|null, invoice: array<string, mixed>|null}
     */
    public function match(array $entry): array
    {
        if (($entry['reason'] ?? null) === 'reversal') {
            return ['status' => self::REVIEW, 'reason' => 'reversal', 'invoice' => null];
        }
        $numbers = $this->references->find((string) ($entry['remittance'] ?? ''));
        if ($numbers === []) {
            return ['status' => self::REVIEW, 'reason' => 'unknown', 'invoice' => null];
        }
        $found = $this->db->fetchAll(
            'SELECT * FROM `nx_invoices` WHERE `number` IN (' . implode(',', array_fill(0, count($numbers), '?')) . ") AND `type` <> 'credit_note' ORDER BY `id`",
            $numbers,
        );
        if ($found === []) {
            return ['status' => self::REVIEW, 'reason' => 'unknown', 'invoice' => null];
        }
        if (count($found) > 1) {
            $open = array_values(array_filter($found, static fn (array $invoice): bool => $invoice['status'] === InvoiceRepository::STATUS_UNPAID));

            return ['status' => self::REVIEW, 'reason' => 'multiple', 'invoice' => $open[0] ?? $found[0]];
        }
        $invoice = $found[0];
        $reason = match (true) {
            $invoice['status'] === InvoiceRepository::STATUS_CANCELLED => 'cancelled',
            $invoice['status'] !== InvoiceRepository::STATUS_UNPAID => 'paid',
            strtoupper((string) $invoice['currency']) !== strtoupper((string) $entry['currency']) => 'currency',
            (int) $entry['amount'] < (int) $invoice['total'] => 'partial',
            (int) $entry['amount'] > (int) $invoice['total'] => 'overpaid',
            default => null,
        };

        return ['status' => $reason === null ? self::MATCHED : self::REVIEW, 'reason' => $reason, 'invoice' => $invoice];
    }

    /**
     * Matches an entry that is waiting for review and books it if the match
     * is clear and automatic booking is on.
     *
     * @param array<string, mixed> $entry
     */
    private function process(array $entry, int $actorId, ?string $ip): string
    {
        $match = $this->match($entry);
        $invoiceId = $match['invoice'] !== null ? (int) $match['invoice']['id'] : null;
        if ($match['status'] === self::MATCHED && $invoiceId !== null && $this->settings->bool('bank.auto_book')) {
            try {
                if ($this->book((int) $entry['id'], $invoiceId, false, self::MATCHED, $actorId, null) !== null) {
                    $this->audit->log($actorId, 'bank.entry_matched', 'bank_entry', (int) $entry['id'], ['invoice' => $invoiceId, 'amount' => (int) $entry['amount']], $ip);

                    return self::MATCHED;
                }
            } catch (InvalidArgumentException $exception) {
                // Changed in the meantime (e.g. paid by card): left for review.
                $this->logger->info('Bank entry not booked automatically', ['entry' => $entry['id'], 'reason' => $exception->getMessage()]);
                $match['reason'] = 'paid';
            }
        }
        $this->db->run(
            "UPDATE `nx_bank_entries` SET `reason` = ?, `invoice_id` = ? WHERE `id` = ? AND `status` = 'review'",
            [$match['status'] === self::MATCHED ? 'confirm' : $match['reason'], $invoiceId, (int) $entry['id']],
        );

        return self::REVIEW;
    }

    /**
     * Books an incoming entry as payment of an open invoice (automatically or
     * by staff). With $excessAsCredit, an amount above the invoice total is
     * booked as account credit (top-up invoice paid by the same transfer).
     *
     * @return int|null ID of the payment transaction
     *
     * @throws InvalidArgumentException when the entry or invoice does not allow it
     */
    public function book(int $entryId, int $invoiceId, bool $excessAsCredit, string $status, int $actorId, ?string $note): ?int
    {
        return $this->db->transaction(function () use ($entryId, $invoiceId, $excessAsCredit, $status, $actorId, $note): ?int {
            $entry = $this->lockOpenCredit($entryId);
            $invoice = $this->invoices->lock($invoiceId);
            if ($invoice === null || $invoice['type'] === InvoiceRepository::TYPE_CREDIT_NOTE) {
                throw new InvalidArgumentException('The invoice was not found.');
            }
            if ($invoice['status'] !== InvoiceRepository::STATUS_UNPAID) {
                throw new InvalidArgumentException('The invoice is not open any more.');
            }
            if (strtoupper((string) $invoice['currency']) !== strtoupper((string) $entry['currency'])) {
                throw new InvalidArgumentException('The currency of the transfer differs from the invoice.');
            }
            $amount = (int) $entry['amount'];
            $total = (int) $invoice['total'];
            if ($amount < $total) {
                throw new InvalidArgumentException('The transfer is less than the invoice total. Partial payments cannot be booked on an invoice; credit the amount to the customer account instead.');
            }
            if ($amount > $total && !$excessAsCredit) {
                throw new InvalidArgumentException('The transfer is more than the invoice total. Book the difference as account credit or credit the whole amount.');
            }

            $reference = 'bank-' . substr((string) $entry['entry_hash'], 0, 40);
            $metadata = ['bank_entry' => $entryId, 'booking_date' => $entry['booking_date'], 'payer' => $entry['counterparty_name'], 'recorded_by' => $actorId];
            $outcome = $this->payments->recordPayment(self::GATEWAY, $reference, $invoiceId, $total, (string) $entry['currency'], $metadata);
            if (!$outcome->isSuccessful()) {
                throw new InvalidArgumentException($outcome->message);
            }
            if ($amount > $total) {
                $this->creditExcess($entry, (int) $invoice['user_id'], $amount - $total, $reference . '-excess', $metadata);
            }
            $this->db->update('nx_bank_entries', [
                'status' => $status,
                'reason' => null,
                'invoice_id' => $invoiceId,
                'transaction_id' => $outcome->transactionId,
                'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
                'processed_by' => $actorId,
                'processed_at' => $this->clock->nowString(),
            ], ['id' => $entryId]);

            return $outcome->transactionId;
        });
    }

    /**
     * Books the whole incoming amount as account credit of a customer
     * (partial payments, transfers without a usable reference): a credit
     * top-up invoice is issued and paid by the transfer.
     *
     * @return int ID of the top-up invoice
     */
    public function credit(int $entryId, int $userId, int $actorId, ?string $note): int
    {
        return $this->db->transaction(function () use ($entryId, $userId, $actorId, $note): int {
            $entry = $this->lockOpenCredit($entryId);
            $reference = 'bank-' . substr((string) $entry['entry_hash'], 0, 40);
            $invoiceId = $this->creditExcess($entry, $userId, (int) $entry['amount'], $reference, ['bank_entry' => $entryId, 'booking_date' => $entry['booking_date'], 'payer' => $entry['counterparty_name'], 'recorded_by' => $actorId]);
            $this->db->update('nx_bank_entries', [
                'status' => self::CREDITED,
                'reason' => null,
                'invoice_id' => $invoiceId,
                'transaction_id' => (int) $this->db->fetchValue("SELECT `id` FROM `nx_transactions` WHERE `gateway` = ? AND `type` = 'payment' AND `reference` = ?", [self::GATEWAY, $reference]),
                'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
                'processed_by' => $actorId,
                'processed_at' => $this->clock->nowString(),
            ], ['id' => $entryId]);

            return $invoiceId;
        });
    }

    /** Marks an entry as handled without a booking (e.g. not a customer payment). */
    public function ignore(int $entryId, int $actorId, string $note): void
    {
        $this->db->transaction(function () use ($entryId, $actorId, $note): void {
            $entry = $this->db->fetchOne('SELECT * FROM `nx_bank_entries` WHERE `id` = ? FOR UPDATE', [$entryId]);
            if ($entry === null || !in_array($entry['status'], [self::REVIEW, self::DEBIT], true)) {
                throw new InvalidArgumentException('This entry has already been processed.');
            }
            $this->db->update('nx_bank_entries', ['status' => self::IGNORED, 'note' => mb_substr($note, 0, 255), 'processed_by' => $actorId, 'processed_at' => $this->clock->nowString()], ['id' => $entryId]);
        });
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $metadata
     */
    private function creditExcess(array $entry, int $userId, int $amount, string $reference, array $metadata): int
    {
        $user = $this->users->find($userId) ?? throw new InvalidArgumentException('The customer was not found.');
        $default = strtoupper($this->settings->string('general.default_currency') ?: 'EUR');
        if (strtoupper((string) $entry['currency']) !== $default) {
            throw new InvalidArgumentException('Account credit is kept in the default currency; this transfer is in another currency.');
        }
        $invoiceId = $this->invoiceService->createCreditTopUp($user, $amount, $default);
        $outcome = $this->payments->recordPayment(self::GATEWAY, $reference, $invoiceId, $amount, $default, $metadata);
        if (!$outcome->isSuccessful()) {
            throw new RuntimeException('The credit could not be booked: ' . $outcome->message);
        }

        return $invoiceId;
    }

    /**
     * @return array<string, mixed>
     */
    private function lockOpenCredit(int $entryId): array
    {
        $entry = $this->db->fetchOne('SELECT * FROM `nx_bank_entries` WHERE `id` = ? FOR UPDATE', [$entryId]);
        if ($entry === null) {
            throw new InvalidArgumentException('The entry was not found.');
        }
        if ($entry['direction'] !== StatementEntry::CREDIT) {
            throw new InvalidArgumentException('Outgoing payments are never booked.');
        }
        if ($entry['status'] !== self::REVIEW) {
            throw new InvalidArgumentException('This entry has already been processed.');
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT e.*, i.`number` AS invoice_number, i.`total` AS invoice_total, i.`status` AS invoice_status, i.`user_id` AS invoice_user_id, p.`email` AS processed_by_email
               FROM `nx_bank_entries` e
          LEFT JOIN `nx_invoices` i ON i.`id` = e.`invoice_id`
          LEFT JOIN `nx_users` p ON p.`id` = e.`processed_by`
              WHERE e.`id` = ?',
            [$id],
        );
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function entries(?string $status, int $page, int $perPage = 50): array
    {
        $where = $status !== null && in_array($status, self::STATUSES, true) ? 'WHERE e.`status` = ?' : '';
        $params = $where !== '' ? [$status] : [];

        return [
            'items' => $this->db->fetchAll(
                "SELECT e.*, i.`number` AS invoice_number FROM `nx_bank_entries` e LEFT JOIN `nx_invoices` i ON i.`id` = e.`invoice_id`
                 {$where} ORDER BY e.`booking_date` DESC, e.`id` DESC LIMIT ? OFFSET ?",
                [...$params, $perPage, max(0, ($page - 1) * $perPage)],
            ),
            'total' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_bank_entries` e {$where}", $params),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function imports(int $limit = 20): array
    {
        return $this->db->fetchAll('SELECT b.*, u.`email` FROM `nx_bank_imports` b LEFT JOIN `nx_users` u ON u.`id` = b.`created_by` ORDER BY b.`id` DESC LIMIT ?', [$limit]);
    }

    /**
     * @return array<string, int> status => number of entries
     */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->fetchAll('SELECT `status`, COUNT(*) AS n FROM `nx_bank_entries` GROUP BY `status`') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Saved CSV profiles.
     *
     * @return list<array<string, mixed>>
     */
    public function profiles(): array
    {
        return $this->db->fetchAll('SELECT * FROM `nx_bank_csv_profiles` ORDER BY `name`');
    }

    /**
     * Column mapping of a preset ("preset:sparkasse") or saved profile ("profile:3").
     *
     * @return array{0: array<string, string>, 1: string} mapping and its name
     */
    public function mapping(string $choice): array
    {
        if (str_starts_with($choice, 'preset:') && isset(BankCsvParser::PRESETS[substr($choice, 7)])) {
            $preset = BankCsvParser::PRESETS[substr($choice, 7)];

            return [$preset['mapping'], $preset['label']];
        }
        if (preg_match('/^profile:(\d{1,10})$/', $choice, $match) === 1) {
            $profile = $this->db->fetchOne('SELECT * FROM `nx_bank_csv_profiles` WHERE `id` = ?', [(int) $match[1]]);
            $mapping = $profile !== null ? json_decode((string) $profile['mapping'], true) : null;
            if (is_array($mapping)) {
                return [BankCsvParser::normalizeMapping($mapping), (string) $profile['name']];
            }
        }

        throw new InvalidArgumentException('Choose a CSV format.');
    }

    /**
     * @param array<string, string> $mapping
     */
    public function saveProfile(string $name, array $mapping, int $actorId): int
    {
        $mapping = BankCsvParser::normalizeMapping($mapping);
        $now = $this->clock->nowString();
        $existing = $this->db->fetchValue('SELECT `id` FROM `nx_bank_csv_profiles` WHERE `name` = ?', [$name]);
        if ($existing !== null) {
            $this->db->update('nx_bank_csv_profiles', ['mapping' => json_encode($mapping, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => $now], ['id' => (int) $existing]);

            return (int) $existing;
        }

        return $this->db->insert('nx_bank_csv_profiles', ['name' => $name, 'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function deleteProfile(int $id): void
    {
        $this->db->delete('nx_bank_csv_profiles', ['id' => $id]);
    }
}
