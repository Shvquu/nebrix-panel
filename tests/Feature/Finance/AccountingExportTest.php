<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature\Finance;

use DateTimeImmutable;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Billing\InvoiceService;
use Nebrix\Billing\OrderService;
use Nebrix\Billing\Tax\TaxService;
use Nebrix\Finance\Accounting\AccountingExport;
use Nebrix\Finance\Accounting\DatevWriter;
use Nebrix\Payment\PaymentService;
use Nebrix\Repository\ProductRepository;
use Nebrix\Repository\UserRepository;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * DATEV posting batch (EXTF 700, category 21, format version 13) and CSV
 * exports: file structure, encoding, accounts per tax case, correction
 * documents, payments, fiscal year and permission checks.
 */
final class AccountingExportTest extends FeatureTestCase
{
    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-03-10 09:00:00'));
        $this->settings = $this->app->container()->get(SettingsRepository::class);
        $this->settings->set('general.timezone', 'Europe/Berlin');
        $this->settings->set('accounting.consultant_number', '29098');
        $this->settings->set('accounting.client_number', '55003');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function customer(array $attributes): User
    {
        $user = $this->createUser(Role::CUSTOMER, $attributes);
        if (($attributes['vat_id'] ?? null) !== null) {
            $this->app->container()->get(TaxService::class)->verifyUser($user);
        }

        return $this->app->container()->get(UserRepository::class)->find($user->id) ?? $user;
    }

    private function order(User $customer, int $price = 1000): int
    {
        $product = $this->app->container()->get(ProductRepository::class)->create([
            'category' => 'dedicated', 'name' => 'Root ' . $price, 'slug' => 'root-' . bin2hex(random_bytes(3)), 'price' => $price, 'setup_fee' => 0, 'currency' => 'EUR',
            'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => [],
        ]);

        return $this->app->container()->get(OrderService::class)->place($customer, $product, ['hostname' => 'srv' . random_int(1, 99999)], '127.0.0.1')['invoice_id'];
    }

    private function pay(int $invoiceId, string $gateway): void
    {
        $total = (int) $this->db->fetchValue('SELECT `total` FROM `nx_invoices` WHERE `id` = ?', [$invoiceId]);
        self::assertTrue($this->app->container()->get(PaymentService::class)->recordPayment($gateway, $gateway . '-' . $invoiceId, $invoiceId, $total, 'EUR')->isSuccessful());
    }

    /**
     * @return array{header: list<string>, columns: list<string>, rows: list<list<string>>, raw: string}
     */
    private function parse(string $file): array
    {
        self::assertStringEndsWith("\r\n", $file);
        self::assertFalse(mb_check_encoding($file, 'UTF-8') && preg_match('/[\x80-\xFF]/', $file) === 1, 'Not UTF-8 encoded.');
        $utf8 = (string) mb_convert_encoding($file, 'UTF-8', 'Windows-1252');
        $lines = explode("\r\n", rtrim($utf8, "\r\n"));
        foreach ($lines as $line) {
            self::assertStringNotContainsString("\n", $line, 'Lines end with CRLF only.');
        }
        $split = static fn (string $line): array => str_getcsv($line, ';', '"', '');

        return ['header' => $split($lines[0]), 'columns' => $split($lines[1]), 'rows' => array_map($split, array_slice($lines, 2)), 'raw' => $utf8];
    }

    /**
     * @param list<list<string>> $rows
     * @return list<string>
     */
    private function row(array $rows, string $document, string $contraAccount = ''): array
    {
        foreach ($rows as $row) {
            if ($row[10] === $document && ($contraAccount === '' || $row[7] === $contraAccount)) {
                return $row;
            }
        }
        self::fail('No booking for ' . $document);
    }

    public function testDatevPostingBatchStructureAccountsAndTaxCases(): void
    {
        $this->settings->set('tax.oss', true);
        $this->settings->set('tax.non_eu_exempt', true);
        $this->settings->set('accounting.export_payments', true);

        $domestic = $this->customer(['country' => 'DE', 'company' => 'Müller & Söhne "Hosting" GmbH']);
        $business = $this->customer(['country' => 'AT', 'vat_id' => 'ATU12345678', 'company' => 'Example Trading GmbH']);
        $consumer = $this->customer(['country' => 'FR']);
        $foreign = $this->customer(['country' => 'US']);

        $invoiceDe = $this->order($domestic);            // 10.00 + 19 % = 11.90
        $invoiceAt = $this->order($business);            // reverse charge, 10.00
        $invoiceFr = $this->order($consumer);            // OSS 20 % = 12.00
        $invoiceUs = $this->order($foreign);             // outside the EU, 10.00
        $this->pay($invoiceDe, 'stripe');
        $this->pay($invoiceFr, 'paypal');
        $cancelled = $this->order($domestic, 2000);      // 23.80, cancelled → correction document
        $this->app->container()->get(InvoiceService::class)->cancel($cancelled);
        $topUp = $this->app->container()->get(InvoiceService::class)->createCreditTopUp($domestic, 5000, 'EUR');
        $this->pay($topUp, 'manual');
        $usd = $this->order($foreign);
        $this->db->update('nx_invoices', ['currency' => 'USD'], ['id' => $usd]);

        $number = fn (int $id): string => (string) $this->db->fetchValue('SELECT `number` FROM `nx_invoices` WHERE `id` = ?', [$id]);
        $creditNote = (int) $this->db->fetchValue('SELECT `id` FROM `nx_invoices` WHERE `reference_invoice_id` = ?', [$cancelled]);

        $result = $this->app->container()->get(AccountingExport::class)->datev('2026-03-01', '2026-03-31', '', 'Ada Admin');
        $file = $this->parse($result['content']);

        // Header (31 fields).
        $header = $file['header'];
        self::assertCount(31, $header);
        self::assertSame(['EXTF', '700', '21', 'Buchungsstapel', '13'], array_slice($header, 0, 5));
        self::assertMatchesRegularExpression('/^\d{17}$/', $header[5]);
        self::assertSame(['29098', '55003', '20260101', '4', '20260301', '20260331'], array_slice($header, 10, 6));
        self::assertSame('Ada Admin', $header[8]);
        self::assertSame(['1', '0', '0', 'EUR'], array_slice($header, 18, 4));
        self::assertSame('03', $header[26]);
        self::assertStringStartsWith('"EXTF";700;21;"Buchungsstapel";13;', $file['raw'], 'Text fields quoted, numbers not.');

        // Column names (125 fields) and rows of the same length.
        self::assertSame(DatevWriter::COLUMNS, $file['columns']);
        self::assertCount(125, $file['columns']);
        self::assertSame(['Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz'], array_slice($file['columns'], 0, 3));
        self::assertSame(['Konto', 'Gegenkonto (ohne BU-Schlüssel)', 'BU-Schlüssel', 'Belegdatum', 'Belegfeld 1'], array_slice($file['columns'], 6, 5));
        self::assertSame('Buchungstext', $file['columns'][13]);
        self::assertSame(['EU-Land u. UStID (Bestimmung)', 'EU-Steuersatz (Bestimmung)'], array_slice($file['columns'], 39, 2));
        foreach ($file['rows'] as $row) {
            self::assertCount(125, $row);
            self::assertMatchesRegularExpression('/^\d+,\d{2}$/', $row[0], 'Decimal comma, no thousands separator, positive.');
            self::assertContains($row[1], ['S', 'H']);
            self::assertSame('EUR', $row[2]);
            self::assertSame('1003', $row[9], 'Belegdatum TTMM (local date).');
        }

        $de = $this->row($file['rows'], $number($invoiceDe), '8400');
        self::assertSame(['11,90', 'S', 'EUR'], array_slice($de, 0, 3));
        self::assertSame([(string) (10000 + $domestic->id), '8400', ''], array_slice($de, 6, 3));
        self::assertStringContainsString("Müller & Söhne 'Hosting' GmbH", $de[13], 'Umlauts survive Windows-1252, quotes are replaced.');
        self::assertLessThanOrEqual(60, mb_strlen($de[13]));

        $at = $this->row($file['rows'], $number($invoiceAt));
        self::assertSame(['10,00', 'S', '8336', 'ATU12345678', ''], [$at[0], $at[1], $at[7], $at[39], $at[40]]);
        $fr = $this->row($file['rows'], $number($invoiceFr), '8320');
        self::assertSame(['12,00', 'FR', '20,00'], [$fr[0], $fr[39], $fr[40]]);
        $us = $this->row($file['rows'], $number($invoiceUs));
        self::assertSame(['10,00', '8338'], [$us[0], $us[7]]);

        // Cancelled invoice and its cancellation document offset each other.
        self::assertSame(['23,80', 'S'], array_slice($this->row($file['rows'], $number($cancelled)), 0, 2));
        $rk = $this->row($file['rows'], $number($creditNote));
        self::assertSame(['23,80', 'H', '8400'], [$rk[0], $rk[1], $rk[7]]);
        self::assertStringStartsWith('RK-', $rk[10]);

        // Top-up: no revenue.
        self::assertSame(['50,00', 'S', '1710'], [$this->row($file['rows'], $number($topUp), '1710')[0], $this->row($file['rows'], $number($topUp), '1710')[1], $this->row($file['rows'], $number($topUp), '1710')[7]]);

        // Payments against the clearing accounts.
        $payments = array_values(array_filter($file['rows'], static fn (array $row): bool => str_starts_with($row[13], 'Zahlung')));
        $lines = array_map(static fn (array $row): string => $row[6] . '|' . $row[0] . '|' . $row[1] . '|' . $row[7], $payments);
        sort($lines);
        self::assertSame([
            '1200|50,00|S|' . (10000 + $domestic->id),
            '1360|11,90|S|' . (10000 + $domestic->id),
            '1360|12,00|S|' . (10000 + $consumer->id),
        ], $lines, 'Bank transfers to the bank account, Stripe and PayPal to money in transit.');

        // Documents in another currency are reported, not exported.
        self::assertCount(1, $result['skipped']);
        self::assertStringContainsString($number($usd), $result['skipped'][0]);
        self::assertSame('EXTF_Buchungsstapel_20260301_20260331.csv', $result['filename']);
    }

    public function testSkr04CollectiveDebtorLockingAndBuKeys(): void
    {
        $this->settings->set('accounting.chart', 'skr04');
        $this->settings->set('accounting.debtor_mode', 'collective');
        $this->settings->set('accounting.lock_bookings', true);
        $this->settings->set('accounting.account_revenue_standard', '4401');
        $this->settings->set('accounting.bu_keys', 'standard=3, reverse_charge=94');
        $customer = $this->customer(['country' => 'DE']);
        $invoice = $this->order($customer);

        $file = $this->parse($this->app->container()->get(AccountingExport::class)->datev('2026-03-10', '2026-03-10', 'Rechnungen März', 'Staff')['content']);
        self::assertSame('04', $file['header'][26]);
        self::assertSame('1', $file['header'][20], 'Festschreibung in the header.');
        self::assertSame('Rechnungen März', $file['header'][16]);
        $row = $file['rows'][0];
        self::assertSame(['10000', '4401', '3'], array_slice($row, 6, 3));
        self::assertSame('1', $row[113]);
        self::assertSame((string) $this->db->fetchValue('SELECT `number` FROM `nx_invoices` WHERE `id` = ?', [$invoice]), $row[10]);
    }

    public function testInvalidRangesAndSettingsAreRefused(): void
    {
        $export = $this->app->container()->get(AccountingExport::class);
        $this->settings->set('accounting.fiscal_year_start', '7');
        $refused = static function (callable $call): string {
            try {
                $call();
            } catch (\InvalidArgumentException|\RuntimeException $exception) {
                return $exception->getMessage();
            }
            self::fail('Not refused.');
        };
        self::assertStringContainsString('one fiscal year', $refused(fn () => $export->datev('2026-06-01', '2026-07-31', '', 'x')));
        self::assertSame('20250701', $export->fiscalYearStart(new DateTimeImmutable('2026-03-01'))->format('Ymd'));
        self::assertStringContainsString('end date', $refused(fn () => $export->datev('2026-03-02', '2026-03-01', '', 'x')));
        self::assertStringContainsString('valid dates', $refused(fn () => $export->datev('2026-02-30', '2026-03-01', '', 'x')));

        $this->settings->set('accounting.consultant_number', '');
        self::assertStringContainsString('consultant number', $refused(fn () => $export->datev('2026-03-01', '2026-03-31', '', 'x')));
        $this->settings->set('accounting.consultant_number', '29098');

        // Account numbers must have the configured length; debtors must stay in the debtor range.
        $customer = $this->customer(['country' => 'DE']);
        $this->order($customer);
        $this->settings->set('accounting.account_revenue_standard', '84000');
        self::assertStringContainsString('does not have 4 digits', $refused(fn () => $export->datev('2026-03-01', '2026-03-31', '', 'x')));
        $this->settings->set('accounting.account_revenue_standard', '');
        $this->settings->set('accounting.debtor_account', '69999');
        self::assertStringContainsString('outside the debtor range', $refused(fn () => $export->datev('2026-03-01', '2026-03-31', '', 'x')));
    }

    public function testCsvExportsAndPermissions(): void
    {
        $customer = $this->customer(['country' => 'DE', 'company' => '=HYPERLINK("http://evil")']);
        $invoice = $this->order($customer);
        $this->pay($invoice, 'manual');

        $this->actingAs($this->createUser(Role::SUPPORT));
        self::assertSame(403, $this->get('/admin/accounting')->status());
        self::assertSame(403, $this->post('/admin/accounting/export', ['format' => 'invoices', 'from' => '2026-03-01', 'to' => '2026-03-31'])->status());
        $this->actingAs($this->createUser(Role::CUSTOMER));
        self::assertSame(403, $this->post('/admin/accounting/export', ['format' => 'datev', 'from' => '2026-03-01', 'to' => '2026-03-31'])->status());

        $finance = $this->createUser(Role::FINANCE);
        $this->actingAs($finance);
        self::assertStringContainsString('DATEV posting batch', $this->get('/admin/accounting')->body());
        $response = $this->post('/admin/accounting/export', ['format' => 'invoices', 'from' => '2026-03-01', 'to' => '2026-03-31']);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('attachment; filename="invoices_20260301_20260331.csv"', (string) $response->header('Content-Disposition'));
        $csv = $response->body();
        self::assertStringStartsWith("\xEF\xBB\xBF" . 'number;date;type;status;customer_number', $csv);
        self::assertStringContainsString(';"\'=HYPERLINK(', $csv, 'Formulas are neutralized.');
        self::assertStringContainsString(';10.00;1.90;11.90;EUR;2026-03-10;', $csv);

        $payments = $this->post('/admin/accounting/export', ['format' => 'payments', 'from' => '2026-03-01', 'to' => '2026-03-31'])->body();
        self::assertStringContainsString('2026-03-10;manual;payment;11.90;EUR;manual-' . $invoice, $payments);

        $datev = $this->post('/admin/accounting/export', ['format' => 'datev', 'from' => '2026-03-01', 'to' => '2026-03-31']);
        self::assertSame(200, $datev->status());
        self::assertStringStartsWith('"EXTF";700;21;', $datev->body());
        self::assertSame(3, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = 'accounting.exported' AND `actor_id` = ?", [$finance->id]));

        // A refused export (fiscal year) returns to the form with the error.
        $this->settings->set('accounting.fiscal_year_start', '3');
        self::assertTrue($this->post('/admin/accounting/export', ['format' => 'datev', 'from' => '2026-02-01', 'to' => '2026-03-31'])->isRedirect());
    }
}
