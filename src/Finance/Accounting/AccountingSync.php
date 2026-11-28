<?php

declare(strict_types=1);

namespace Nebrix\Finance\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use Nebrix\Billing\Documents\InvoiceDocuments;
use Nebrix\Billing\InvoiceRepository;
use Nebrix\Billing\Money;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Transfers issued revenue documents (order, renewal and upgrade invoices
 * and their correction documents) to lexoffice as vouchers with the archived
 * PDF.
 *
 * Idempotent: the voucher ID is stored per document (nx_accounting_sync);
 * a voucher is created at most once, an interrupted attempt is detected by
 * looking the voucher number up before creating it again. Credit top-ups and
 * gift cards are not revenue and are not transferred. Tested against a mock
 * only.
 */
class AccountingSync
{
    public const PROVIDER = 'lexoffice';
    private const MAX_ATTEMPTS = 10;
    private const REVENUE_TYPES = [InvoiceRepository::TYPE_ORDER, InvoiceRepository::TYPE_RENEWAL, InvoiceRepository::TYPE_UPGRADE];

    /** Pause between documents (lexoffice allows about two requests per second). */
    private int $pauseMicroseconds = 600_000;

    public function __construct(
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
        private readonly LexofficeClient $client,
        private readonly InvoiceDocuments $documents,
        private readonly AccountingConfig $config,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('lexoffice.enabled') && $this->settings->isSet('lexoffice.api_key');
    }

    public function withoutPause(): void
    {
        $this->pauseMicroseconds = 0;
    }

    /**
     * Transfers up to $limit documents. With $retryAll, documents that failed
     * too often are tried again (manual "sync now").
     *
     * @return array{synced: int, failed: int, skipped: int}
     */
    public function run(int $limit = 50, bool $retryAll = false): array
    {
        $result = ['synced' => 0, 'failed' => 0, 'skipped' => 0];
        if (!$this->enabled()) {
            return $result;
        }

        // One transfer at a time (scheduler and "sync now" may overlap).
        return $this->db->withLock('nebrix:lexoffice-sync', 5, function () use ($limit, $retryAll, $result): array {
            $apiKey = (string) $this->settings->get('lexoffice.api_key');
            foreach ($this->candidates($limit, $retryAll) as $index => $invoice) {
                if ($index > 0 && $this->pauseMicroseconds > 0) {
                    usleep($this->pauseMicroseconds);
                }
                $outcome = $this->push($invoice, $apiKey);
                $result[$outcome]++;
            }

            return $result;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidates(int $limit, bool $retryAll): array
    {
        $from = $this->settings->string('lexoffice.sync_from');
        $types = "'" . implode("','", self::REVENUE_TYPES) . "'";

        return $this->db->fetchAll(
            "SELECT i.*, r.`type` AS reference_type FROM `nx_invoices` i
          LEFT JOIN `nx_invoices` r ON r.`id` = i.`reference_invoice_id`
          LEFT JOIN `nx_accounting_sync` s ON s.`invoice_id` = i.`id` AND s.`provider` = ?
              WHERE i.`total` <> 0 AND i.`currency` = 'EUR' AND i.`created_at` >= ?
                AND (i.`type` IN ({$types}) OR (i.`type` = 'credit_note' AND r.`type` IN ({$types})))
                AND (s.`id` IS NULL OR (s.`status` <> 'synced' AND (? = 1 OR s.`attempts` < ?)))
           ORDER BY i.`id` LIMIT ?",
            [self::PROVIDER, $from !== '' ? $from . ' 00:00:00' : '2000-01-01 00:00:00', $retryAll ? 1 : 0, self::MAX_ATTEMPTS, $limit],
        );
    }

    /**
     * @param array<string, mixed> $invoice
     * @return 'synced'|'failed'|'skipped'
     */
    private function push(array $invoice, #[\SensitiveParameter] string $apiKey): string
    {
        $invoiceId = (int) $invoice['id'];
        $now = $this->clock->nowString();
        $this->db->run(
            'INSERT IGNORE INTO `nx_accounting_sync` (`provider`, `invoice_id`, `status`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?)',
            [self::PROVIDER, $invoiceId, 'pending', $now, $now],
        );
        $row = (array) $this->db->fetchOne('SELECT * FROM `nx_accounting_sync` WHERE `provider` = ? AND `invoice_id` = ?', [self::PROVIDER, $invoiceId]);

        try {
            $voucherId = $row['external_id'] !== null ? (string) $row['external_id'] : null;
            if ($voucherId === null) {
                $voucher = $this->voucher($invoice);
                // A previous attempt may have created the voucher without receiving the answer.
                if ((int) $row['attempts'] > 0 || $row['status'] === 'creating') {
                    $voucherId = $this->client->findVoucher($apiKey, (string) $invoice['number']);
                }
                if ($voucherId === null) {
                    $this->mark((int) $row['id'], ['status' => 'creating']);
                    $voucherId = $this->client->createVoucher($apiKey, $voucher);
                }
                $this->mark((int) $row['id'], ['status' => 'created', 'external_id' => $voucherId]);
            }
            if ((int) $row['file_uploaded'] !== 1) {
                $this->client->uploadFile($apiKey, $voucherId, $this->documents->pdf($invoiceId), $this->documents->filename($invoice) . '.pdf');
            }
            $this->mark((int) $row['id'], ['status' => 'synced', 'file_uploaded' => 1, 'last_error' => null, 'synced_at' => $this->clock->nowString()]);

            return 'synced';
        } catch (\PDOException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 500);
            $this->db->run(
                "UPDATE `nx_accounting_sync` SET `status` = IF(`status` = 'creating', 'creating', 'failed'), `attempts` = `attempts` + 1, `last_error` = ?, `updated_at` = ? WHERE `id` = ?",
                [$message, $this->clock->nowString(), (int) $row['id']],
            );
            $this->logger->warning('lexoffice transfer failed', ['invoice' => $invoiceId, 'message' => $message]);

            return 'failed';
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mark(int $id, array $data): void
    {
        $this->db->update('nx_accounting_sync', $data + ['updated_at' => $this->clock->nowString()], ['id' => $id]);
    }

    /**
     * The voucher for a document: gross amounts per tax rate with the posting
     * category of its tax case, collective customer contact.
     *
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    public function voucher(array $invoice): array
    {
        $zone = new DateTimeZone($this->settings->string('general.timezone') ?: 'UTC');
        $date = static fn (string $utc): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d\TH:i:s.vP');
        $isCorrection = $invoice['type'] === InvoiceRepository::TYPE_CREDIT_NOTE;
        $items = [];
        $groups = [];
        foreach ($this->db->fetchAll('SELECT `tax_rate_bp`, `total` FROM `nx_invoice_items` WHERE `invoice_id` = ? ORDER BY `id`', [(int) $invoice['id']]) as $item) {
            $rate = (int) $item['tax_rate_bp'];
            $net = (int) $item['total'];
            $tax = $invoice['tax_category'] === null || $invoice['tax_category'] === 'S' ? Money::tax($net, $rate) : 0;
            $groups[$rate] ??= ['gross' => 0, 'tax' => 0];
            $groups[$rate]['gross'] += $net + $tax;
            $groups[$rate]['tax'] += $tax;
        }
        foreach ($groups as $rate => $group) {
            $case = $this->config->taxCase($invoice['tax_category'] !== null ? (string) $invoice['tax_category'] : null, $invoice['tax_country'] !== null ? (string) $invoice['tax_country'] : null, $rate);
            $items[] = [
                'amount' => self::decimal($group['gross']),
                'taxAmount' => self::decimal($group['tax']),
                'taxRatePercent' => $rate / 100,
                'categoryId' => $this->category($case),
            ];
        }

        return array_filter([
            'type' => $isCorrection ? 'salescreditnote' : 'salesinvoice',
            'voucherNumber' => (string) $invoice['number'],
            'voucherDate' => $date((string) $invoice['created_at']),
            'shippingDate' => $date((string) $invoice['created_at']),
            'dueDate' => $isCorrection ? null : $date((string) $invoice['due_at']),
            'totalGrossAmount' => self::decimal((int) $invoice['total']),
            'totalTaxAmount' => self::decimal((int) $invoice['tax']),
            'taxType' => 'gross',
            'useCollectiveContact' => true,
            'remark' => mb_substr('Nebrix ' . $invoice['number'] . ' (C' . $invoice['user_id'] . ')', 0, 255),
            'voucherItems' => $items,
            'version' => 0,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function category(string $case): string
    {
        $setting = match ($case) {
            AccountingConfig::REVERSE_CHARGE => 'lexoffice.category_reverse_charge',
            AccountingConfig::OSS => 'lexoffice.category_oss',
            AccountingConfig::THIRD_COUNTRY => 'lexoffice.category_third_country',
            AccountingConfig::SMALL_BUSINESS => 'lexoffice.category_small_business',
            default => 'lexoffice.category_standard',
        };
        $category = $this->settings->string($setting);
        if ($category === '') {
            throw new \RuntimeException(sprintf('No lexoffice posting category is configured for this tax case (%s).', $case));
        }

        return $category;
    }

    private static function decimal(int $minor): float
    {
        return round($minor / 100, 2);
    }

    /**
     * Transfer state for the admin page.
     *
     * @return array{synced: int, open: int, failed: list<array<string, mixed>>}
     */
    public function status(): array
    {
        return [
            'synced' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_accounting_sync` WHERE `provider` = ? AND `status` = 'synced'", [self::PROVIDER]),
            'open' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_accounting_sync` WHERE `provider` = ? AND `status` <> 'synced'", [self::PROVIDER]),
            'failed' => $this->db->fetchAll(
                "SELECT s.*, i.`number` FROM `nx_accounting_sync` s JOIN `nx_invoices` i ON i.`id` = s.`invoice_id`
                  WHERE s.`provider` = ? AND s.`status` <> 'synced' AND s.`attempts` > 0 ORDER BY s.`updated_at` DESC LIMIT 50",
                [self::PROVIDER],
            ),
        ];
    }
}
