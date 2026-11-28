<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature\Finance;

use DateTimeImmutable;
use InvalidArgumentException;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Billing\InvoiceService;
use Nebrix\Billing\OrderService;
use Nebrix\Finance\Bank\BankCsvParser;
use Nebrix\Finance\Bank\BankReconciliation;
use Nebrix\Finance\Bank\Camt053Parser;
use Nebrix\Finance\Bank\InvoiceReferenceFinder;
use Nebrix\Payment\PaymentService;
use Nebrix\Repository\ProductRepository;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Bank statement import: CAMT.053 and CSV parsing (including XXE attempts),
 * matching rules, automatic booking with the effects of a manual payment,
 * review actions, duplicate protection and permissions.
 */
final class BankImportTest extends FeatureTestCase
{
    private BankReconciliation $bank;
    private User $customer;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-05-04 08:00:00'));
        $this->bank = $this->app->container()->get(BankReconciliation::class);
        $this->customer = $this->createUser(Role::CUSTOMER, ['country' => 'DE']);
        $this->staff = $this->createUser(Role::FINANCE);
    }

    /** An open order invoice of 11.90 EUR; returns [id, number]. */
    private function invoice(int $price = 1000, ?User $customer = null): array
    {
        $product = $this->app->container()->get(ProductRepository::class)->create([
            'category' => 'dedicated', 'name' => 'Root ' . $price, 'slug' => 'root-' . bin2hex(random_bytes(3)), 'price' => $price, 'setup_fee' => 0, 'currency' => 'EUR',
            'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => [],
        ]);
        $id = $this->app->container()->get(OrderService::class)->place($customer ?? $this->customer, $product, ['hostname' => 'srv' . random_int(1, 99999)], '127.0.0.1')['invoice_id'];

        return [$id, (string) $this->db->fetchValue('SELECT `number` FROM `nx_invoices` WHERE `id` = ?', [$id])];
    }

    /**
     * @param list<array{amount: string, cd?: string, text?: string, status?: string, ref?: string, name?: string, tx?: list<array{amount: string, text: string}>}> $entries
     */
    private function camt(array $entries, string $version = '08', string $statementId = 'STMT-1'): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.' . $version . '"><BkToCstmrStmt><GrpHdr><MsgId>M1</MsgId><CreDtTm>2026-05-04T06:00:00</CreDtTm></GrpHdr>'
            . '<Stmt><Id>' . $statementId . '</Id><Acct><Id><IBAN>DE02120300000000202051</IBAN></Id></Acct>';
        foreach ($entries as $entry) {
            $status = $version === '02' ? '<Sts>' . ($entry['status'] ?? 'BOOK') . '</Sts>' : '<Sts><Cd>' . ($entry['status'] ?? 'BOOK') . '</Cd></Sts>';
            $party = ($entry['cd'] ?? 'CRDT') === 'CRDT' ? 'Dbtr' : 'Cdtr';
            $details = '';
            foreach ($entry['tx'] ?? [['amount' => $entry['amount'], 'text' => $entry['text'] ?? '']] as $tx) {
                $amount = $version === '02' ? '<AmtDtls><TxAmt><Amt Ccy="EUR">' . $tx['amount'] . '</Amt></TxAmt></AmtDtls>' : '<Amt Ccy="EUR">' . $tx['amount'] . '</Amt>';
                $name = $version === '02' ? '<' . $party . '><Nm>' . ($entry['name'] ?? 'Max Mustermann') . '</Nm></' . $party . '>' : '<' . $party . '><Pty><Nm>' . ($entry['name'] ?? 'Max Mustermann') . '</Nm></Pty></' . $party . '>';
                $details .= '<TxDtls><Refs><EndToEndId>NOTPROVIDED</EndToEndId></Refs>' . $amount . '<RltdPties>' . $name . '<' . $party . 'Acct><Id><IBAN>DE89370400440532013000</IBAN></Id></' . $party . 'Acct></RltdPties>'
                    . '<RmtInf><Ustrd>' . htmlspecialchars($tx['text'], ENT_XML1) . '</Ustrd></RmtInf></TxDtls>';
            }
            $xml .= '<Ntry>' . (isset($entry['ref']) ? '<NtryRef>x</NtryRef>' : '') . '<Amt Ccy="EUR">' . $entry['amount'] . '</Amt><CdtDbtInd>' . ($entry['cd'] ?? 'CRDT') . '</CdtDbtInd>' . $status
                . '<BookgDt><Dt>2026-05-03</Dt></BookgDt><ValDt><Dt>2026-05-03</Dt></ValDt>' . (isset($entry['ref']) ? '<AcctSvcrRef>' . $entry['ref'] . '</AcctSvcrRef>' : '')
                . '<BkTxCd/><NtryDtls>' . $details . '</NtryDtls></Ntry>';
        }

        return $xml . '</Stmt></BkToCstmrStmt></Document>';
    }

    private function import(string $content, string $format = 'camt053', ?array $mapping = null): array
    {
        return $this->bank->import($content, 'statement.xml', $format, $mapping, null, $this->staff->id, '127.0.0.1');
    }

    private function invoiceStatus(int $invoiceId): string
    {
        return (string) $this->db->fetchValue('SELECT `status` FROM `nx_invoices` WHERE `id` = ?', [$invoiceId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $text): array
    {
        return (array) $this->db->fetchOne('SELECT * FROM `nx_bank_entries` WHERE `remittance` LIKE ? ORDER BY `id` DESC LIMIT 1', ['%' . $text . '%']);
    }

    public function testInvoiceNumbersAreFoundInDifferentSpellings(): void
    {
        $finder = $this->app->container()->get(InvoiceReferenceFinder::class);
        self::assertSame(['NX-2026-000123'], $finder->find('Rechnung NX-2026-000123 vielen Dank'));
        self::assertSame(['NX-2026-000123'], $finder->find('RECHNUNGNX2026000123DANKE'));
        self::assertSame(['NX-2026-000123'], $finder->find('nx 2026 123'));
        self::assertSame(['NX-2026-000045', 'NX-2026-000046'], $finder->find('NX.2026/000045 und NX - 2026 - 46, NX-2026-000045'));
        self::assertSame([], $finder->find('NX-2026-0001234 is too long; 2026-000123 has no prefix'));
    }

    public function testClearMatchesAreBookedLikeManualPaymentsAndTheRestIsReviewed(): void
    {
        [$exact, $exactNumber] = $this->invoice();
        [$partial, $partialNumber] = $this->invoice();
        [$over, $overNumber] = $this->invoice();
        [$first, $firstNumber] = $this->invoice();
        [$second, $secondNumber] = $this->invoice();
        [$batchA, $batchANumber] = $this->invoice(2000);
        [$batchB, $batchBNumber] = $this->invoice(3000);
        [$paid, $paidNumber] = $this->invoice();
        $this->app->container()->get(PaymentService::class)->recordPayment('stripe', 'pi_1', $paid, 1190, 'EUR');

        $result = $this->import($this->camt([
            ['amount' => '11.90', 'text' => 'Rechnung ' . str_replace('-', ' ', $exactNumber), 'ref' => 'BANKREF-1'],
            ['amount' => '5.00', 'text' => 'Anzahlung ' . $partialNumber],
            ['amount' => '20.00', 'text' => $overNumber],
            ['amount' => '23.80', 'text' => $firstNumber . ' ' . $secondNumber],
            ['amount' => '11.90', 'text' => 'Miete Mai'],
            ['amount' => '11.90', 'text' => 'nochmal ' . $paidNumber],
            ['amount' => '99.00', 'cd' => 'DBIT', 'text' => 'Lastschrift Server ' . $firstNumber, 'name' => 'Hetzner'],
            ['amount' => '11.90', 'status' => 'PDNG', 'text' => $secondNumber],
            ['amount' => '59.50', 'text' => 'batch', 'tx' => [['amount' => '23.80', 'text' => $batchANumber], ['amount' => '35.70', 'text' => $batchBNumber]]],
        ]));
        self::assertSame(['entries' => 9, 'duplicates' => 0, 'matched' => 3, 'review' => 5, 'debit' => 1, 'skipped' => 1], array_diff_key($result, ['import_id' => true]));

        // Exact match: paid through the payment path (confirmation e-mail, fulfilment job).
        self::assertSame('paid', $this->invoiceStatus($exact));
        $transaction = (array) $this->db->fetchOne("SELECT * FROM `nx_transactions` WHERE `invoice_id` = ? AND `status` = 'completed'", [$exact]);
        self::assertSame(['bank', 1190], [$transaction['gateway'], (int) $transaction['amount']]);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_payment_events` WHERE `invoice_id` = ? AND `event_type` = 'payment.completed'", [$exact]));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_jobs` WHERE `type` = 'order.fulfil' AND `unique_key` = ?", ['order.fulfil:' . $this->db->fetchValue('SELECT `order_id` FROM `nx_invoices` WHERE `id` = ?', [$exact])]));
        self::assertTrue($this->mailQueued('payment_confirmation'));
        $booked = $this->entry('Rechnung');
        self::assertSame(['matched', $exact, (int) $transaction['id'], 'BANKREF-1'], [$booked['status'], (int) $booked['invoice_id'], (int) $booked['transaction_id'], $booked['entry_reference']]);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = 'bank.entry_matched' AND `target_id` = ?", [(string) $booked['id']]));

        // Batch entry: one entry per transaction, both booked.
        self::assertSame(['paid', 'paid'], [$this->invoiceStatus($batchA), $this->invoiceStatus($batchB)]);

        // Review list with the reason and a suggestion.
        $reasons = [];
        foreach ($this->db->fetchAll("SELECT `reason`, `invoice_id` FROM `nx_bank_entries` WHERE `status` = 'review' ORDER BY `id`") as $row) {
            $reasons[] = [$row['reason'], $row['invoice_id'] !== null ? (int) $row['invoice_id'] : null];
        }
        self::assertSame([['partial', $partial], ['overpaid', $over], ['multiple', $first], ['unknown', null], ['paid', $paid]], $reasons);
        self::assertSame(['unpaid', 'unpaid', 'unpaid', 'unpaid'], [$this->invoiceStatus($partial), $this->invoiceStatus($over), $this->invoiceStatus($first), $this->invoiceStatus($second)]);
        $debit = $this->entry('Lastschrift');
        self::assertSame(['debit', 'debit', null], [$debit['status'], $debit['direction'], $debit['transaction_id']]);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_transactions` WHERE `invoice_id` = ? AND `status` = 'completed'", [$paid]), 'No second payment of a paid invoice.');
    }

    public function testDuplicateImportsAreRefusedOrSkipped(): void
    {
        [$invoice, $number] = $this->invoice();
        $file = $this->camt([['amount' => '11.90', 'text' => $number], ['amount' => '3.00', 'text' => 'Spende'], ['amount' => '3.00', 'text' => 'Spende']]);
        $first = $this->import($file);
        self::assertSame([3, 1], [$first['entries'], $first['matched']], 'Two identical transfers in one file stay two entries.');

        try {
            $this->import($file);
            self::fail('The same file was imported twice.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('This file has already been imported.', $exception->getMessage());
        }

        // An overlapping statement (another file) with the same entries plus a new one.
        $second = $this->import($this->camt([['amount' => '11.90', 'text' => $number], ['amount' => '3.00', 'text' => 'Spende'], ['amount' => '3.00', 'text' => 'Spende'], ['amount' => '4.00', 'text' => 'neu']], '08', 'STMT-2'));
        self::assertSame(['entries' => 1, 'duplicates' => 3, 'matched' => 0], ['entries' => $second['entries'], 'duplicates' => $second['duplicates'], 'matched' => $second['matched']]);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_transactions` WHERE `invoice_id` = ? AND `type` = 'payment'", [$invoice]));
        self::assertSame(4, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_bank_entries`'));
    }

    public function testUnsafeOrInvalidXmlIsRejected(): void
    {
        $parser = new Camt053Parser();
        $secret = tempnam(sys_get_temp_dir(), 'nx-secret');
        file_put_contents((string) $secret, 'TOP-SECRET-CONTENT');
        $xxe = '<?xml version="1.0"?><!DOCTYPE Document [<!ENTITY xxe SYSTEM "file://' . $secret . '">]>'
            . '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"><BkToCstmrStmt><Stmt><Id>&xxe;</Id></Stmt></BkToCstmrStmt></Document>';
        $laughs = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">]><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08">&lol2;</Document>';
        $remote = '<?xml version="1.0"?><!doctype Document SYSTEM "http://203.0.113.1/evil.dtd"><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"/>';
        foreach ([$xxe, $laughs, $remote] as $xml) {
            try {
                $parser->parse($xml);
                self::fail('A document with a DTD was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('document type declaration', $exception->getMessage());
                self::assertStringNotContainsString('TOP-SECRET', $exception->getMessage());
            }
        }
        unlink((string) $secret);

        $refused = static function (string $xml) use ($parser): string {
            try {
                $parser->parse($xml);
            } catch (InvalidArgumentException $exception) {
                return $exception->getMessage();
            }
            self::fail('Accepted: ' . $xml);
        };
        self::assertSame('The file is not valid XML.', $refused('<Document><unclosed>'));
        self::assertSame('The file is not a CAMT.053 bank statement.', $refused('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.09"/>'));
        self::assertSame('An entry has an invalid amount.', $refused($this->camt([['amount' => '1e5', 'text' => 'x']])));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_bank_imports`'));

        // Size limit.
        $this->app->container()->get(SettingsRepository::class)->set('bank.max_upload_kb', 64);
        try {
            $this->import(str_repeat(' ', 65 * 1024 + 1));
            self::fail('The size limit was not applied.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The file is empty or larger than the allowed size.', $exception->getMessage());
        }

        // camt.053.001.02 (amounts in AmtDtls, plain Sts) is read as well.
        $parsed = $parser->parse($this->camt([['amount' => '7.50', 'text' => 'Test', 'name' => 'Erika Müller']], '02'));
        self::assertSame([750, 'credit', 'Erika Müller', 'DE89370400440532013000', 'Test', '2026-05-03'], [$parsed['entries'][0]->amount, $parsed['entries'][0]->direction, $parsed['entries'][0]->counterpartyName, $parsed['entries'][0]->counterpartyIban, $parsed['entries'][0]->remittance, $parsed['entries'][0]->bookingDate]);
        self::assertSame('DE02120300000000202051', $parsed['account']);
    }

    public function testCsvExportsWithPresetsAndProfiles(): void
    {
        [$invoice, $number] = $this->invoice();
        $sparkasse = "\"Auftragskonto\";\"Buchungstag\";\"Valutadatum\";\"Buchungstext\";\"Verwendungszweck\";\"Beguenstigter/Zahlungspflichtiger\";\"Kontonummer/IBAN\";\"BIC (SWIFT-Code)\";\"Betrag\";\"Waehrung\";\"Info\"\r\n"
            . "\"DE02120300000000202051\";\"03.05.26\";\"03.05.26\";\"GUTSCHR. UEBERWEISUNG\";\"Rechnung {$number} Jürgen\";\"Jürgen Weiß\";\"DE89370400440532013000\";\"COBADEFFXXX\";\"11,90\";\"EUR\";\"Umsatz gebucht\"\r\n"
            . "\"DE02120300000000202051\";\"03.05.26\";\"03.05.26\";\"LASTSCHRIFT\";\"Strom\";\"Stadtwerke\";\"DE89370400440532013001\";\"COBADEFFXXX\";\"-1.234,56\";\"EUR\";\"Umsatz gebucht\"\r\n";
        $result = $this->import((string) mb_convert_encoding($sparkasse, 'Windows-1252', 'UTF-8'), 'csv', BankCsvParser::PRESETS['sparkasse']['mapping']);
        self::assertSame([2, 1, 1], [$result['entries'], $result['matched'], $result['debit']]);
        self::assertSame('paid', $this->invoiceStatus($invoice));
        $entry = $this->entry('Rechnung');
        self::assertSame(['Jürgen Weiß', 'DE89370400440532013000', '2026-05-03'], [$entry['counterparty_name'], $entry['counterparty_iban'], $entry['booking_date']]);
        self::assertSame(123456, (int) $this->entry('Strom')['amount']);

        // DKB: preamble lines, two-digit years, only booked rows.
        [$dkbInvoice, $dkbNumber] = $this->invoice();
        $dkb = "\"Girokonto\";\"DE02120300000000202051\"\n\"Kontostand vom 04.05.2026:\";\"1.000,00 €\"\n\n"
            . "\"Buchungsdatum\";\"Wertstellung\";\"Status\";\"Zahlungspflichtige*r\";\"Zahlungsempfänger*in\";\"Verwendungszweck\";\"Umsatztyp\";\"IBAN\";\"Betrag (€)\";\"Gläubiger-ID\";\"Mandatsreferenz\";\"Kundenreferenz\"\n"
            . "\"04.05.26\";\"04.05.26\";\"Vorgemerkt\";\"Ada\";\"Nebrix\";\"{$dkbNumber}\";\"Eingang\";\"DE89370400440532013000\";\"11,90\";\"\";\"\";\"\"\n"
            . "\"03.05.26\";\"03.05.26\";\"Gebucht\";\"Ada\";\"Nebrix\";\"RE {$dkbNumber}\";\"Eingang\";\"DE89370400440532013000\";\"11,90\";\"\";\"\";\"\"\n";
        $result = $this->import($dkb, 'csv', BankCsvParser::PRESETS['dkb']['mapping']);
        self::assertSame([1, 1, 1], [$result['entries'], $result['matched'], $result['skipped']]);
        self::assertSame('paid', $this->invoiceStatus($dkbInvoice));

        // Saved profile through the admin page; a file without the mapped columns is refused.
        $this->actingAs($this->staff);
        $this->post('/admin/bank/profiles', ['name' => 'Hausbank', 'delimiter' => 'comma', 'encoding' => 'UTF-8', 'date_format' => 'Y-m-d', 'decimal' => 'point', 'booking_date' => 'Date', 'amount' => 'Amount', 'remittance' => 'Purpose|Memo', 'counterparty_name' => 'Payer']);
        $profileId = (int) $this->db->fetchValue("SELECT `id` FROM `nx_bank_csv_profiles` WHERE `name` = 'Hausbank'");
        [$mapping, $name] = $this->bank->mapping('profile:' . $profileId);
        self::assertSame(['Hausbank', ',', '.'], [$name, $mapping['delimiter'], $mapping['decimal']]);
        [$third, $thirdNumber] = $this->invoice();
        $result = $this->bank->import("Date,Amount,Purpose,Memo,Payer\n2026-05-02,11.90,Invoice,{$thirdNumber},Bob\n", 'h.csv', 'csv', $mapping, $name, $this->staff->id, null);
        self::assertSame(1, $result['matched']);
        self::assertStringContainsString('Hausbank', $this->get('/admin/bank/profiles')->body());
        try {
            $this->bank->import("Datum;Wert\n01.01.2026;1\n", 'x.csv', 'csv', $mapping, $name, $this->staff->id, null);
            self::fail('A file without the mapped columns was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('columns of the mapping were not found', $exception->getMessage());
        }
        $this->post('/admin/bank/profiles/' . $profileId . '/delete');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_bank_csv_profiles`'));
    }

    public function testStaffResolveTheReviewList(): void
    {
        [$partial, $partialNumber] = $this->invoice();
        [$over, $overNumber] = $this->invoice();
        [$first, $firstNumber] = $this->invoice();
        [, $secondNumber] = $this->invoice();
        $this->import($this->camt([
            ['amount' => '5.00', 'text' => 'Teil ' . $partialNumber],
            ['amount' => '20.00', 'text' => 'Mehr ' . $overNumber],
            ['amount' => '11.90', 'text' => 'Beide ' . $firstNumber . ' ' . $secondNumber],
            ['amount' => '8.00', 'text' => 'Unbekannt'],
            ['amount' => '50.00', 'cd' => 'DBIT', 'text' => 'Ausgang'],
        ]));
        $partialEntry = (int) $this->entry('Teil')['id'];
        $overEntry = (int) $this->entry('Mehr')['id'];
        $bothEntry = (int) $this->entry('Beide')['id'];
        $unknownEntry = (int) $this->entry('Unbekannt')['id'];
        $debitEntry = (int) $this->entry('Ausgang')['id'];

        // Permission bank.import (support staff and customers have none).
        $this->actingAs($this->createUser(Role::SUPPORT));
        self::assertSame(403, $this->get('/admin/bank')->status());
        self::assertSame(403, $this->post('/admin/bank/entries/' . $bothEntry . '/assign', ['invoice' => $firstNumber])->status());
        $this->actingAs($this->customer);
        self::assertSame(403, $this->post('/admin/bank/entries/' . $bothEntry . '/assign', ['invoice' => $firstNumber])->status());
        self::assertSame('unpaid', $this->invoiceStatus($first));

        $this->actingAs($this->staff);
        $page = $this->get('/admin/bank')->body();
        self::assertStringContainsString('Less than the invoice total', $page);
        self::assertStringContainsString('Several invoice numbers', $page);
        self::assertStringContainsString($firstNumber, $this->get('/admin/bank/entries/' . $bothEntry)->body());

        // A partial payment cannot be booked on the invoice …
        $this->post('/admin/bank/entries/' . $partialEntry . '/assign', ['invoice' => $partialNumber]);
        self::assertSame(['unpaid', 'review'], [$this->invoiceStatus($partial), $this->entry('Teil')['status']]);
        // … but as account credit of the customer (top-up invoice paid by the transfer).
        $this->post('/admin/bank/entries/' . $partialEntry . '/credit', ['customer' => (string) $this->customer->id, 'note' => 'Teilzahlung']);
        $credited = $this->entry('Teil');
        self::assertSame(['credited', 'Teilzahlung'], [$credited['status'], $credited['note']]);
        self::assertSame(['credit', 'paid', 500], [(string) $this->db->fetchValue('SELECT `type` FROM `nx_invoices` WHERE `id` = ?', [$credited['invoice_id']]), $this->invoiceStatus((int) $credited['invoice_id']), (int) $this->db->fetchValue('SELECT `credit_balance` FROM `nx_users` WHERE `id` = ?', [$this->customer->id])]);

        // Over-payment: only with the excess booked as credit.
        $this->post('/admin/bank/entries/' . $overEntry . '/assign', ['invoice' => $overNumber]);
        self::assertSame('unpaid', $this->invoiceStatus($over));
        $this->post('/admin/bank/entries/' . $overEntry . '/assign', ['invoice' => $overNumber, 'excess_as_credit' => '1']);
        self::assertSame(['paid', 'assigned'], [$this->invoiceStatus($over), $this->entry('Mehr')['status']]);
        self::assertSame(500 + 810, (int) $this->db->fetchValue('SELECT `credit_balance` FROM `nx_users` WHERE `id` = ?', [$this->customer->id]));

        // Several invoice numbers: staff choose one (the invoice number may be typed in lower case).
        $this->post('/admin/bank/entries/' . $bothEntry . '/assign', ['invoice' => strtolower($firstNumber)]);
        self::assertSame(['paid', 'assigned'], [$this->invoiceStatus($first), $this->entry('Beide')['status']]);
        self::assertSame((int) $this->staff->id, (int) $this->entry('Beide')['processed_by']);
        // Processed entries cannot be booked again.
        $this->post('/admin/bank/entries/' . $bothEntry . '/credit', ['customer' => $this->customer->email]);
        self::assertSame('assigned', $this->entry('Beide')['status']);

        // Outgoing payments are never booked; unknown transfers can be ignored with a reason.
        $this->post('/admin/bank/entries/' . $debitEntry . '/credit', ['customer' => (string) $this->customer->id]);
        self::assertSame('debit', $this->entry('Ausgang')['status']);
        $this->post('/admin/bank/entries/' . $unknownEntry . '/ignore', ['note' => 'Privat']);
        self::assertSame(['ignored', 'Privat'], [$this->entry('Unbekannt')['status'], $this->entry('Unbekannt')['note']]);

        foreach (['bank.entry_credited', 'bank.entry_assigned', 'bank.entry_ignored', 'bank.profile_saved'] as $action) {
            self::assertGreaterThanOrEqual($action === 'bank.profile_saved' ? 0 : 1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = ? AND `actor_id` = ?', [$action, $this->staff->id]), $action);
        }
        self::assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = 'bank.entry_assigned'"));
    }

    public function testAutomaticBookingCanBeSwitchedOffAndRechecksTheInvoice(): void
    {
        $settings = $this->app->container()->get(SettingsRepository::class);
        $settings->set('bank.auto_book', false);
        [$invoice, $number] = $this->invoice();
        $this->import($this->camt([['amount' => '11.90', 'text' => $number]]));
        $entry = $this->entry($number);
        self::assertSame(['review', 'confirm', $invoice], [$entry['status'], $entry['reason'], (int) $entry['invoice_id']]);
        self::assertSame('unpaid', $this->invoiceStatus($invoice));

        // Paid by card before staff confirm: the transfer is not booked as a second payment.
        $this->app->container()->get(PaymentService::class)->recordPayment('stripe', 'pi_2', $invoice, 1190, 'EUR');
        try {
            $this->bank->book((int) $entry['id'], $invoice, false, BankReconciliation::ASSIGNED, $this->staff->id, null);
            self::fail('A paid invoice was booked again.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The invoice is not open any more.', $exception->getMessage());
        }
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_transactions` WHERE `invoice_id` = ? AND `type` = 'payment'", [$invoice]));

        // Cancelled invoices are not booked either.
        [$cancelled, $cancelledNumber] = $this->invoice();
        $this->app->container()->get(InvoiceService::class)->cancel($cancelled);
        $settings->set('bank.auto_book', true);
        $this->import($this->camt([['amount' => '11.90', 'text' => $cancelledNumber]], '08', 'STMT-9'));
        self::assertSame(['review', 'cancelled'], [$this->entry($cancelledNumber)['status'], $this->entry($cancelledNumber)['reason']]);
    }
}
