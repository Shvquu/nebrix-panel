<?php

declare(strict_types=1);

namespace Nebrix\Finance\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nebrix\Billing\InvoiceRepository;
use Nebrix\Billing\Money;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Finance\Csv;
use Nebrix\Settings\SettingsRepository;

/**
 * Accounting export of issued documents (invoices, cancellation and
 * correction documents) and optionally payments for a date range: DATEV
 * posting batch or plain CSV files.
 *
 * Issued documents are booked when they are issued (accrual basis): a
 * cancelled invoice and its cancellation document both appear and offset
 * each other. Nothing is changed in the database.
 */
class AccountingExport
{
    /** Payment gateways and the clearing account they are booked to. */
    private const GATEWAY_ACCOUNTS = ['stripe' => 'stripe', 'paypal' => 'paypal', 'manual' => 'bank', 'bank' => 'bank', 'credit' => AccountingConfig::CUSTOMER_CREDIT];

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingConfig $config,
        private readonly SettingsRepository $settings,
        private readonly Clock $clock,
        private readonly DatevWriter $writer,
    ) {
    }

    public function timezone(): DateTimeZone
    {
        try {
            return new DateTimeZone($this->settings->string('general.timezone') ?: 'UTC');
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * Validates a date range (local dates, inclusive).
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public function range(string $from, string $to): array
    {
        $zone = $this->timezone();
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $zone);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $zone);
        if ($start === false || $end === false || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to) {
            throw new InvalidArgumentException('Enter valid dates.');
        }
        if ($end < $start) {
            throw new InvalidArgumentException('The end date must not be before the start date.');
        }
        if ($start->modify('+1 year') <= $end) {
            throw new InvalidArgumentException('Export at most one year at a time.');
        }

        return [$start, $end];
    }

    /** Start of the fiscal year that contains the date. */
    public function fiscalYearStart(DateTimeImmutable $date): DateTimeImmutable
    {
        $month = max(1, min(12, (int) ($this->settings->string('accounting.fiscal_year_start') ?: '1')));
        $year = (int) $date->format('Y');
        if ((int) $date->format('n') < $month) {
            $year--;
        }

        return $date->setDate($year, $month, 1)->setTime(0, 0);
    }

    /**
     * @return array{content: string, filename: string, bookings: int, skipped: list<string>}
     */
    public function datev(string $from, string $to, string $description, string $exportedBy): array
    {
        [$start, $end] = $this->range($from, $to);
        $fiscalYear = $this->fiscalYearStart($start);
        if ($this->fiscalYearStart($end) != $fiscalYear) {
            throw new InvalidArgumentException('A DATEV posting batch must lie within one fiscal year. Split the export at the start of the fiscal year.');
        }
        $currency = $this->currency();
        $skipped = [];
        $bookings = $this->documentBookings($start, $end, $currency, $skipped);
        if ($this->settings->bool('accounting.export_payments')) {
            $bookings = [...$bookings, ...$this->paymentBookings($start, $end, $currency, $skipped)];
        }
        usort($bookings, static fn (Booking $a, Booking $b): int => [$a->date, $a->document] <=> [$b->date, $b->document]);

        $content = $this->writer->write($bookings, [
            'consultant' => $this->settings->string('accounting.consultant_number'),
            'client' => $this->settings->string('accounting.client_number'),
            'fiscal_year_start' => $fiscalYear,
            'account_length' => $this->config->accountLength(),
            'from' => $start,
            'to' => $end,
            'description' => $description !== '' ? $description : 'Nebrix ' . $start->format('d.m.') . '-' . $end->format('d.m.Y'),
            'exported_by' => $exportedBy,
            'currency' => $currency,
            'chart' => $this->config->chart(),
            'locked' => $this->settings->bool('accounting.lock_bookings'),
            'created' => $this->clock->now()->setTimezone($this->timezone()),
        ]);

        return [
            'content' => $content,
            'filename' => sprintf('EXTF_Buchungsstapel_%s_%s.csv', $start->format('Ymd'), $end->format('Ymd')),
            'bookings' => count($bookings),
            'skipped' => $skipped,
        ];
    }

    /**
     * Booking lines of all documents issued in the range.
     *
     * @param list<string> $skipped
     * @return list<Booking>
     */
    public function documentBookings(DateTimeImmutable $start, DateTimeImmutable $end, string $currency, array &$skipped): array
    {
        $bookings = [];
        foreach ($this->documents($start, $end) as $invoice) {
            if ((int) $invoice['total'] === 0) {
                continue;
            }
            if (strtoupper((string) $invoice['currency']) !== $currency) {
                $skipped[] = sprintf('%s: currency %s (the batch is in %s)', $invoice['number'], $invoice['currency'], $currency);
                continue;
            }
            $isCorrection = $invoice['type'] === InvoiceRepository::TYPE_CREDIT_NOTE;
            // Correction documents are booked like the document they correct, on the other side.
            $baseType = $isCorrection ? (string) ($invoice['reference_type'] ?? '') : (string) $invoice['type'];
            $side = $isCorrection ? 'H' : 'S';
            $debtor = $this->config->debtorAccount((int) $invoice['user_id']);
            $date = $this->localDate((string) $invoice['created_at']);
            $billing = json_decode((string) ($invoice['billing_details'] ?? '{}'), true);
            $billing = is_array($billing) ? $billing : [];
            $name = trim((string) ($billing['company'] ?? '')) !== '' ? (string) $billing['company'] : (string) ($billing['name'] ?? '');
            $text = trim(($isCorrection ? 'Gutschrift/Storno ' : 'Rechnung ') . $invoice['number'] . ' ' . $name);

            if (in_array($baseType, [InvoiceRepository::TYPE_CREDIT, InvoiceRepository::TYPE_GIFT_CARD], true)) {
                $bookings[] = new Booking((int) $invoice['total'], $side, $currency, $debtor, $this->config->account(AccountingConfig::CUSTOMER_CREDIT), '', $date, (string) $invoice['number'], $text, '', null, AccountingConfig::CUSTOMER_CREDIT);
                continue;
            }

            foreach ($this->rateGroups($invoice) as $rate => $gross) {
                if ($gross === 0) {
                    continue;
                }
                $case = $this->config->taxCase($invoice['tax_category'] !== null ? (string) $invoice['tax_category'] : null, $invoice['tax_country'] !== null ? (string) $invoice['tax_country'] : null, $rate);
                [$field40, $field41] = match ($case) {
                    AccountingConfig::REVERSE_CHARGE => [strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($billing['vat_id'] ?? ''))), null],
                    AccountingConfig::OSS => [strtoupper((string) $invoice['tax_country']), $rate],
                    default => ['', null],
                };
                // A negative group (e.g. a discount line) is booked on the other side.
                $bookings[] = new Booking(abs($gross), $gross < 0 ? ($side === 'S' ? 'H' : 'S') : $side, $currency, $debtor, $this->config->account($case), $this->config->buKey($case), $date, (string) $invoice['number'], $text, $field40, $field41, $case);
            }
        }

        return $bookings;
    }

    /**
     * Payment and refund bookings against the clearing accounts.
     *
     * @param list<string> $skipped
     * @return list<Booking>
     */
    public function paymentBookings(DateTimeImmutable $start, DateTimeImmutable $end, string $currency, array &$skipped): array
    {
        $bookings = [];
        foreach ($this->payments($start, $end) as $payment) {
            $amount = (int) $payment['amount'];
            if ($amount <= 0 || $payment['gateway'] === 'free') {
                continue;
            }
            if (strtoupper((string) $payment['currency']) !== $currency) {
                $skipped[] = sprintf('payment %s: currency %s', $payment['reference'], $payment['currency']);
                continue;
            }
            $purpose = self::GATEWAY_ACCOUNTS[(string) $payment['gateway']] ?? 'bank';
            $isRefund = $payment['type'] === 'refund';
            $number = (string) ($payment['invoice_number'] ?? '');
            $bookings[] = new Booking(
                $amount,
                $isRefund ? 'H' : 'S',
                $currency,
                $this->config->account($purpose),
                $this->config->debtorAccount((int) $payment['user_id']),
                '',
                $this->localDate((string) $payment['created_at']),
                $number,
                trim(($isRefund ? 'Erstattung ' : 'Zahlung ') . $number . ' ' . ucfirst((string) $payment['gateway'])),
                '',
                null,
                'payment',
            );
        }

        return $bookings;
    }

    /**
     * Invoices and correction documents issued in the range (local dates).
     *
     * @return list<array<string, mixed>>
     */
    public function documents(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        [$from, $to] = $this->utcBounds($start, $end);

        return $this->db->fetchAll(
            'SELECT i.*, r.`type` AS reference_type, r.`number` AS reference_number, u.`email`
               FROM `nx_invoices` i
               JOIN `nx_users` u ON u.`id` = i.`user_id`
          LEFT JOIN `nx_invoices` r ON r.`id` = i.`reference_invoice_id`
              WHERE i.`created_at` >= ? AND i.`created_at` < ?
           ORDER BY i.`created_at`, i.`id`',
            [$from, $to],
        );
    }

    /**
     * Completed payments and refunds in the range.
     *
     * @return list<array<string, mixed>>
     */
    public function payments(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        [$from, $to] = $this->utcBounds($start, $end);

        return $this->db->fetchAll(
            "SELECT t.*, i.`number` AS invoice_number, u.`email`, u.`first_name`, u.`last_name`, u.`company`
               FROM `nx_transactions` t
               JOIN `nx_users` u ON u.`id` = t.`user_id`
          LEFT JOIN `nx_invoices` i ON i.`id` = t.`invoice_id`
              WHERE t.`status` = 'completed' AND t.`created_at` >= ? AND t.`created_at` < ?
           ORDER BY t.`created_at`, t.`id`",
            [$from, $to],
        );
    }

    /**
     * Plain CSV of the documents (UTF-8 with BOM, semicolon, decimal point).
     */
    public function invoicesCsv(string $from, string $to): string
    {
        [$start, $end] = $this->range($from, $to);
        $rows = [['number', 'date', 'type', 'status', 'customer_number', 'customer', 'email', 'country', 'vat_id', 'tax_category', 'tax_country', 'net', 'tax', 'gross', 'currency', 'paid_at', 'refunded', 'corrects']];
        foreach ($this->documents($start, $end) as $invoice) {
            $billing = json_decode((string) ($invoice['billing_details'] ?? '{}'), true);
            $billing = is_array($billing) ? $billing : [];
            $currency = (string) $invoice['currency'];
            $rows[] = [
                (string) $invoice['number'],
                $this->localDate((string) $invoice['created_at'])->format('Y-m-d'),
                (string) $invoice['type'],
                (string) $invoice['status'],
                (string) $invoice['user_id'],
                trim((string) ($billing['company'] ?? '')) !== '' ? (string) $billing['company'] : (string) ($billing['name'] ?? ''),
                (string) $invoice['email'],
                (string) ($billing['country'] ?? ''),
                (string) ($billing['vat_id'] ?? ''),
                (string) ($invoice['tax_category'] ?? ''),
                (string) ($invoice['tax_country'] ?? ''),
                Money::toDecimal((int) $invoice['subtotal'], $currency),
                Money::toDecimal((int) $invoice['tax'], $currency),
                Money::toDecimal((int) $invoice['total'], $currency),
                $currency,
                $invoice['paid_at'] !== null ? $this->localDate((string) $invoice['paid_at'])->format('Y-m-d') : '',
                Money::toDecimal((int) $invoice['amount_refunded'], $currency),
                (string) ($invoice['reference_number'] ?? ''),
            ];
        }

        return Csv::build($rows);
    }

    public function paymentsCsv(string $from, string $to): string
    {
        [$start, $end] = $this->range($from, $to);
        $rows = [['date', 'gateway', 'type', 'amount', 'currency', 'reference', 'invoice', 'customer_number', 'customer', 'email']];
        foreach ($this->payments($start, $end) as $payment) {
            $name = trim((string) ($payment['company'] ?? '')) !== '' ? (string) $payment['company'] : trim($payment['first_name'] . ' ' . $payment['last_name']);
            $rows[] = [
                $this->localDate((string) $payment['created_at'])->format('Y-m-d'),
                (string) $payment['gateway'],
                (string) $payment['type'],
                Money::toDecimal((int) $payment['amount'], (string) $payment['currency']),
                (string) $payment['currency'],
                (string) $payment['reference'],
                (string) ($payment['invoice_number'] ?? ''),
                (string) $payment['user_id'],
                $name,
                (string) $payment['email'],
            ];
        }

        return Csv::build($rows);
    }

    public function currency(): string
    {
        return strtoupper($this->settings->string('general.default_currency') ?: 'EUR');
    }

    /**
     * Gross amounts per tax rate of one document. The tax is calculated per
     * item like on the invoice; a rounding difference to the stored total
     * is put on the largest group, so the bookings always add up to the
     * document.
     *
     * @param array<string, mixed> $invoice
     * @return array<int, int> rate (bp) => gross
     */
    private function rateGroups(array $invoice): array
    {
        $groups = [];
        foreach ($this->db->fetchAll('SELECT `tax_rate_bp`, `total` FROM `nx_invoice_items` WHERE `invoice_id` = ? ORDER BY `id`', [(int) $invoice['id']]) as $item) {
            $rate = (int) $item['tax_rate_bp'];
            $net = (int) $item['total'];
            $groups[$rate] = ($groups[$rate] ?? 0) + $net + ($invoice['tax_category'] === null || $invoice['tax_category'] === 'S' ? Money::tax($net, $rate) : 0);
        }
        if ($groups === []) {
            return [0 => (int) $invoice['total']];
        }
        $difference = (int) $invoice['total'] - array_sum($groups);
        if ($difference !== 0) {
            $largest = array_search(max(array_map('abs', $groups)), array_map('abs', $groups), true);
            $groups[(int) $largest] += $difference;
        }

        return $groups;
    }

    private function localDate(string $utc): DateTimeImmutable
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($this->timezone());
    }

    /**
     * @return array{0: string, 1: string} UTC bounds [start, end of the last day)
     */
    private function utcBounds(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $utc = new DateTimeZone('UTC');

        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }
}
