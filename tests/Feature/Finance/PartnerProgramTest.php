<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature\Finance;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Billing\InvoiceService;
use Nebrix\Billing\OrderService;
use Nebrix\Finance\Partner\Iban;
use Nebrix\Finance\Partner\PartnerService;
use Nebrix\Payment\PaymentService;
use Nebrix\Referral\ReferralService;
use Nebrix\Repository\ProductRepository;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Partner program: opt-in with consent, commission accrual for paid
 * invoices of referred customers, holding period, reversal on refunds and
 * chargebacks, encrypted payout details, payout authorization, SEPA export
 * and statements.
 */
final class PartnerProgramTest extends FeatureTestCase
{
    private SettingsRepository $settings;
    private PartnerService $partners;
    private ReferralService $referrals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-07-01 10:00:00'));
        $this->settings = $this->app->container()->get(SettingsRepository::class);
        foreach (['referral.enabled' => true, 'referral.reward' => '10.00', 'referral.welcome' => '2.00', 'referral.min_amount' => '5.00', 'referral.holding_days' => 14,
            'partner.enabled' => true, 'partner.commission_percent' => '10', 'partner.commission_months' => 12, 'partner.holding_days' => 30, 'partner.min_payout' => '2.00',
            'partner.terms_version' => '2026-07', 'invoice.bank_iban' => 'DE02120300000000202051', 'invoice.bank_bic' => 'BYLADEM1001', 'invoice.bank_account_holder' => 'Nebrix Hosting GmbH'] as $key => $value) {
            $this->settings->set($key, $value);
        }
        $this->partners = $this->app->container()->get(PartnerService::class);
        $this->referrals = $this->app->container()->get(ReferralService::class);
    }

    private function later(string $modify): void
    {
        $this->clock()->freeze($this->clock()->now()->modify($modify));
    }

    /** Pays a new order of the customer (net price, 19 % VAT) and returns the invoice ID. */
    private function paidOrder(User $customer, int $net, string $gateway = 'stripe'): int
    {
        $product = $this->app->container()->get(ProductRepository::class)->create([
            'category' => 'dedicated', 'name' => 'Root ' . $net, 'slug' => 'root-' . bin2hex(random_bytes(3)), 'price' => $net, 'setup_fee' => 0, 'currency' => 'EUR',
            'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => [],
        ]);
        $invoice = $this->app->container()->get(OrderService::class)->place($customer, $product, ['hostname' => 'srv' . random_int(1, 99999)], '127.0.0.1')['invoice_id'];
        if ($gateway === 'credit') {
            $this->db->update('nx_users', ['credit_balance' => 100000], ['id' => $customer->id]);
            $this->app->container()->get(\Nebrix\Payment\CreditGateway::class)->start((array) $this->db->fetchOne('SELECT * FROM `nx_invoices` WHERE `id` = ?', [$invoice]), $customer, '', '');
        } else {
            $total = (int) $this->db->fetchValue('SELECT `total` FROM `nx_invoices` WHERE `id` = ?', [$invoice]);
            self::assertTrue($this->app->container()->get(PaymentService::class)->recordPayment($gateway, $gateway . '-' . $invoice, $invoice, $total, 'EUR')->isSuccessful());
        }

        return $invoice;
    }

    /** @return array{0: User, 1: User} partner and a customer referred by them */
    private function partnerWithReferral(): array
    {
        $partner = $this->createUser();
        $this->partners->join($partner, '203.0.113.1');
        $customer = $this->createUser();
        $this->referrals->attach($customer->id, $this->referrals->codeFor($partner->id), '198.51.100.44');

        return [$partner, $customer];
    }

    /** @return list<array{0: string, 1: int}> status and amount of the partner's commission entries */
    private function entries(User $partner): array
    {
        return array_map(static fn (array $row): array => [(string) $row['status'], (int) $row['amount']], $this->db->fetchAll('SELECT `status`, `amount` FROM `nx_partner_commissions` WHERE `partner_id` = ? ORDER BY `id`', [$partner->id]));
    }

    private function process(): void
    {
        $this->referrals->process();
        $this->partners->process();
    }

    public function testCustomersJoinWithConsent(): void
    {
        $customer = $this->createUser();
        $this->actingAs($customer);
        $page = $this->get('/account/partner')->body();
        self::assertStringContainsString('Join the partner program', $page);
        $this->post('/account/partner/join', []);
        self::assertNull($this->partners->partner($customer->id), 'Not without consent.');
        $this->post('/account/partner/join', ['accept_terms' => '1']);
        $partner = $this->partners->partner($customer->id);
        self::assertNotNull($partner);
        self::assertSame(['active', '2026-07', '2026-07-01 10:00:00', $this->clientIp], [$partner['status'], $partner['terms_version'], $partner['consented_at'], $partner['consent_ip']]);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = 'partner.joined' AND `actor_id` = ?", [$customer->id]));
        self::assertStringContainsString('/r/' . $this->referrals->codeFor($customer->id), $this->get('/account/partner')->body());

        // Switched off: the pages are not available.
        $this->settings->set('partner.enabled', false);
        self::assertSame(404, $this->get('/account/partner')->status());
        self::assertSame(404, $this->post('/account/partner/payout')->status());
    }

    public function testCommissionsAccrueForRealPaymentsWithinTheWindow(): void
    {
        [$partner, $customer] = $this->partnerWithReferral();
        $first = $this->paidOrder($customer, 1000);          // 10.00 net → 1.00
        $this->paidOrder($customer, 2000, 'credit');         // account credit: no commission
        $this->process();
        self::assertSame([['pending', 100]], $this->entries($partner));
        $row = (array) $this->db->fetchOne('SELECT * FROM `nx_partner_commissions` WHERE `invoice_id` = ?', [$first]);
        self::assertSame([1000, 1000, '2026-07-31 10:00:00'], [(int) $row['basis'], (int) $row['rate_bp'], $row['payable_at']]);

        // The partner receives no credit reward; the referred customer still gets the welcome credit.
        $this->later('+15 days');
        $this->process();
        $referral = (array) $this->db->fetchOne('SELECT * FROM `nx_referrals` WHERE `referred_id` = ?', [$customer->id]);
        self::assertSame(['rewarded', 0], [$referral['status'], (int) $referral['reward']]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT `credit_balance` FROM `nx_users` WHERE `id` = ?', [$partner->id]));
        self::assertSame(200 + 100000 - 2380, (int) $this->db->fetchValue('SELECT `credit_balance` FROM `nx_users` WHERE `id` = ?', [$customer->id]));

        // A bank transfer recorded by staff counts; idempotent across runs.
        $this->paidOrder($customer, 3000, 'manual');
        $this->process();
        $this->process();
        self::assertSame([['pending', 100], ['pending', 300]], $this->entries($partner));

        // After the holding period the commission becomes payable.
        $this->later('+16 days');
        $this->process();
        self::assertSame([['payable', 100], ['pending', 300]], $this->entries($partner));

        // Invoices paid after the commission window earn nothing.
        $this->later('+12 months');
        $this->paidOrder($customer, 5000);
        $this->process();
        self::assertCount(2, $this->entries($partner));

        // Referrals held for review earn nothing.
        $held = $this->createUser();
        $this->referrals->attach($held->id, $this->referrals->codeFor($partner->id), '198.51.100.46');
        $this->db->update('nx_referrals', ['status' => 'review'], ['referred_id' => $held->id]);
        $heldInvoice = $this->paidOrder($held, 1000);
        $this->process();
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_partner_commissions` WHERE `invoice_id` = ?', [$heldInvoice]));

        // Customers referred by somebody who is not a partner earn nothing.
        $referrer = $this->createUser();
        $other = $this->createUser();
        $this->referrals->attach($other->id, $this->referrals->codeFor($referrer->id), '198.51.100.45');
        $this->paidOrder($other, 1000);
        $this->process();
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_partner_commissions` WHERE `partner_id` = ?', [$referrer->id]));
    }

    public function testRefundsCancellationsAndChargebacksReverseCommissions(): void
    {
        [$partner, $customer] = $this->partnerWithReferral();
        $refunded = $this->paidOrder($customer, 1000);
        $partial = $this->paidOrder($customer, 2000);
        $disputed = $this->paidOrder($customer, 4000);
        $this->process();
        self::assertSame([['pending', 100], ['pending', 200], ['pending', 400]], $this->entries($partner));

        $payments = $this->app->container()->get(PaymentService::class);
        $payments->recordRefund('stripe', 're_1', 'stripe-' . $refunded, 1190, 'EUR');
        $payments->recordRefund('stripe', 're_2', 'stripe-' . $partial, 1190, 'EUR');   // half of 23.80
        $payments->recordDispute('stripe', 'dp_1', 'stripe-' . $disputed, null, null);
        $this->process();
        self::assertSame([['reversed', 100], ['pending', 200], ['reversed', 400], ['pending', -100]], $this->entries($partner), 'Full reversals cancel the entry, partial ones add a negative entry.');
        self::assertSame(['pending' => 100, 'payable' => 0, 'requested' => 0, 'paid' => 0], $this->partners->balance($partner->id));

        // A commission already paid out is set off against the next payout.
        $later = $this->paidOrder($customer, 5000);
        $this->later('+31 days');
        $this->process();
        $this->partners->saveDetails($partner->id, 'DE89 3704 0044 0532 0130 00', 'Ada Lovelace', '', '203.0.113.1');
        $payout = $this->partners->requestPayout($partner->id, '203.0.113.1');
        $staff = $this->createUser(Role::FINANCE);
        $this->partners->approve($payout, $staff->id, null);
        $this->partners->markPaid($payout, $staff->id, 'TRX-1', null);
        self::assertSame(600, (int) $this->db->fetchValue('SELECT `amount` FROM `nx_partner_payouts` WHERE `id` = ?', [$payout]), '2.00 + 5.00 minus the 1.00 reversed for the partial refund.');
        $payments->recordRefund('stripe', 're_3', 'stripe-' . $later, 5950, 'EUR');
        $this->paidOrder($customer, 10000);
        $this->process();
        self::assertSame(['paid', -500], [(string) $this->db->fetchValue("SELECT `status` FROM `nx_partner_commissions` WHERE `invoice_id` = ? AND `kind` = 'accrual'", [$later]), (int) $this->db->fetchValue("SELECT `amount` FROM `nx_partner_commissions` WHERE `invoice_id` = ? AND `kind` = 'adjustment'", [$later])]);
        self::assertSame(-500, $this->partners->balance($partner->id)['payable']);
        $this->later('+31 days');
        $this->process();
        self::assertSame(500, $this->partners->balance($partner->id)['payable'], '10.00 commission minus the 5.00 paid out for the refunded invoice.');
        self::assertGreaterThanOrEqual(3, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = 'partner.commission_reversed'"));

        // Cancelled invoices (also unpaid ones) never earn anything.
        $open = $this->app->container()->get(OrderService::class)->place($customer, (int) $this->db->fetchValue('SELECT `id` FROM `nx_products` LIMIT 1'), ['hostname' => 'x1'], '127.0.0.1')['invoice_id'];
        $this->app->container()->get(InvoiceService::class)->cancel($open);
        $this->process();
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_partner_commissions` WHERE `invoice_id` = ?', [$open]));
    }

    public function testPayoutDetailsRequestsAndAuthorization(): void
    {
        [$partner, $customer] = $this->partnerWithReferral();
        $this->paidOrder($customer, 3000);
        $this->later('+31 days');
        $this->process();
        self::assertSame(300, $this->partners->balance($partner->id)['payable']);

        $this->actingAs($partner);
        // No payout without details; invalid IBANs are refused.
        $this->post('/account/partner/payout');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_partner_payouts`'));
        $this->post('/account/partner/details', ['iban' => 'DE89370400440532013001', 'holder' => 'Ada Lovelace']);
        self::assertNull($this->partners->partner($partner->id)['iban_encrypted'] ?? null);
        $this->post('/account/partner/details', ['iban' => 'de89 3704 0044 0532 0130 00', 'holder' => 'Ada Lovelace', 'bic' => 'cobadeffxxx']);
        $row = (array) $this->partners->partner($partner->id);
        self::assertSame(['3000', 'COBADEFFXXX'], [$row['iban_last4'], $row['bic']]);
        self::assertStringNotContainsString('DE89370400440532013000', (string) $row['iban_encrypted'], 'Encrypted at rest.');
        self::assertStringNotContainsString('Ada', (string) $row['holder_encrypted']);
        self::assertStringNotContainsString('DE8937', (string) json_encode($this->db->fetchAll('SELECT * FROM `nx_audit_log`')));
        self::assertStringContainsString('**** 3000', $this->get('/account/partner')->body());

        // Below the minimum: refused.
        $this->settings->set('partner.min_payout', '5.00');
        $this->post('/account/partner/payout');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_partner_payouts`'));
        $this->settings->set('partner.min_payout', '2.00');
        $this->post('/account/partner/payout');
        $payout = (array) $this->db->fetchOne('SELECT * FROM `nx_partner_payouts`');
        self::assertSame([$partner->id, 300, 'requested', '3000'], [(int) $payout['partner_id'], (int) $payout['amount'], $payout['status'], $payout['iban_last4']]);
        self::assertSame([['requested', 300]], $this->entries($partner));
        $this->post('/account/partner/payout');
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_partner_payouts`'), 'One open payout at a time.');
        // Changing the details afterwards does not redirect the requested payout.
        $this->post('/account/partner/details', ['iban' => 'GB82 WEST 1234 5698 7654 32', 'holder' => 'Mallory']);

        // Customers and staff without partners.manage cannot process payouts.
        self::assertSame(403, $this->post('/admin/partners/payouts/' . $payout['id'] . '/approve')->status());
        $this->actingAs($this->createUser(Role::SUPPORT));
        self::assertSame(403, $this->get('/admin/partners')->status());
        self::assertSame(403, $this->post('/admin/partners/payouts/' . $payout['id'] . '/approve')->status());
        self::assertSame('requested', $this->db->fetchValue('SELECT `status` FROM `nx_partner_payouts`'));

        // Staff who are partners themselves cannot approve their own payout.
        $this->db->update('nx_users', ['role_id' => (int) $this->db->fetchValue("SELECT `id` FROM `nx_roles` WHERE `slug` = 'finance'")], ['id' => $partner->id]);
        $this->actingAs($partner);
        $this->post('/admin/partners/payouts/' . $payout['id'] . '/approve');
        self::assertSame('requested', $this->db->fetchValue('SELECT `status` FROM `nx_partner_payouts`'));

        // Reject → payable again; a new request; approve; SEPA file; mark paid.
        $finance = $this->createUser(Role::FINANCE);
        $this->actingAs($finance);
        self::assertStringContainsString((string) $payout['reference'], $this->get('/admin/partners')->body());
        $this->post('/admin/partners/payouts/' . $payout['id'] . '/paid', ['bank_reference' => 'X']);
        self::assertSame('requested', $this->db->fetchValue('SELECT `status` FROM `nx_partner_payouts`'), 'Only approved payouts can be marked as paid.');
        $this->post('/admin/partners/payouts/' . $payout['id'] . '/reject', ['note' => 'Please check the account holder']);
        self::assertSame([['payable', 300]], $this->entries($partner));
        $second = $this->partners->requestPayout($partner->id, '203.0.113.1');
        $this->post('/admin/partners/payouts/' . $second . '/approve');
        self::assertSame('approved', $this->db->fetchValue('SELECT `status` FROM `nx_partner_payouts` WHERE `id` = ?', [$second]));

        $response = $this->post('/admin/partners/payouts/sepa');
        self::assertSame(200, $response->status());
        self::assertStringContainsString('.xml"', (string) $response->header('Content-Disposition'));
        $doc = new DOMDocument();
        self::assertTrue($doc->loadXML($response->body(), LIBXML_NONET));
        $x = new DOMXPath($doc);
        $x->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.09');
        $value = static fn (string $path): string => trim((string) $x->query($path)?->item(0)?->textContent);
        self::assertSame(['1', '3.00', 'TRF', 'SEPA', 'DE02120300000000202051', 'BYLADEM1001', 'SLEV'], [
            $value('//p:GrpHdr/p:NbOfTxs'), $value('//p:GrpHdr/p:CtrlSum'), $value('//p:PmtInf/p:PmtMtd'), $value('//p:PmtTpInf/p:SvcLvl/p:Cd'),
            $value('//p:DbtrAcct/p:Id/p:IBAN'), $value('//p:DbtrAgt/p:FinInstnId/p:BICFI'), $value('//p:ChrgBr'),
        ]);
        self::assertSame('3.00', $value('//p:CdtTrfTxInf/p:Amt/p:InstdAmt'));
        self::assertSame('EUR', $x->query('//p:CdtTrfTxInf/p:Amt/p:InstdAmt')?->item(0)?->attributes?->getNamedItem('Ccy')?->nodeValue);
        self::assertSame('GB82WEST12345698765432', $value('//p:CdtrAcct/p:Id/p:IBAN'), 'The second request uses the details stored at that time.');
        self::assertSame('Mallory', $value('//p:Cdtr/p:Nm'));
        self::assertSame((string) $this->db->fetchValue('SELECT `reference` FROM `nx_partner_payouts` WHERE `id` = ?', [$second]), $value('//p:PmtId/p:EndToEndId'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $value('//p:ReqdExctnDt/p:Dt'));
        // Exported once: the next file is empty.
        $this->post('/admin/partners/payouts/sepa');
        self::assertStringContainsString('There are no payouts to export.', $this->get('/admin/partners')->body());

        $this->post('/admin/partners/payouts/' . $second . '/paid', ['bank_reference' => 'SEPA-2026-07']);
        self::assertSame([['paid', 300]], $this->entries($partner));
        self::assertTrue($this->mailQueued('partner_payout_paid'));
        foreach (['partner.payout_requested', 'partner.payout_rejected', 'partner.payout_approved', 'partner.sepa_exported', 'partner.payout_paid', 'partner.details_changed'] as $action) {
            self::assertGreaterThan(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = ?', [$action]), $action);
        }

        // Statement for the partner (own data only).
        $this->actingAs($partner);
        $statement = $this->get('/account/partner/statement', ['year' => '2026']);
        self::assertSame(200, $statement->status());
        self::assertStringContainsString('commission;30.00;10.00;3.00;EUR;paid', $statement->body());
        self::assertStringContainsString('payout ', $statement->body());
        $this->actingAs($customer);
        self::assertSame(404, $this->get('/account/partner/statement', ['year' => '2026'])->status(), 'Not a partner.');
    }

    public function testIbanValidation(): void
    {
        foreach (['DE89 3704 0044 0532 0130 00', 'GB82WEST12345698765432', 'AT611904300234573201', 'NL91ABNA0417164300', 'FR1420041010050500013M02606'] as $iban) {
            self::assertTrue(Iban::isValid($iban), $iban);
        }
        foreach (['DE89370400440532013001', 'DE8937040044053201300', 'US12345678901234567890', 'DE89 3704 0044 0532 0130 0X', ''] as $iban) {
            self::assertFalse(Iban::isValid($iban), $iban);
        }
    }
}
