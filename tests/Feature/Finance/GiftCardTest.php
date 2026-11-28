<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature\Finance;

use DateTimeImmutable;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Billing\Documents\InvoiceDocuments;
use Nebrix\Billing\InvoiceRepository;
use Nebrix\Finance\GiftCards\GiftCardException;
use Nebrix\Finance\GiftCards\GiftCardService;
use Nebrix\Payment\GatewayRegistry;
use Nebrix\Payment\PaymentService;
use Nebrix\Repository\UserRepository;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\FakeStripeHttp;
use Stripe\ApiRequestor;

/**
 * Gift cards: creation (codes shown once, stored hashed), CSV download,
 * redemption (single use, row lock, brute-force limit), purchase as a
 * non-taxable multi-purpose voucher with the code sent after a confirmed
 * payment only, refunds and chargebacks.
 */
final class GiftCardTest extends FeatureTestCase
{
    private SettingsRepository $settings;
    private GiftCardService $giftCards;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-06-01 12:00:00'));
        $this->settings = $this->app->container()->get(SettingsRepository::class);
        $this->settings->set('giftcard.enabled', true);
        $this->settings->set('payments.credit.enabled', true);
        $this->giftCards = $this->app->container()->get(GiftCardService::class);
    }

    private function credit(User $user): int
    {
        return (int) $this->db->fetchValue('SELECT `credit_balance` FROM `nx_users` WHERE `id` = ?', [$user->id]);
    }

    /** @return list<string> */
    private function codes(int $quantity, int $value = 2500, ?DateTimeImmutable $expires = null): array
    {
        return array_column($this->giftCards->create($value, 'EUR', $expires, $quantity, 'Test', null)['codes'], 'code');
    }

    public function testStaffCreateCodesThatAreShownOnceAndStoredHashed(): void
    {
        $this->actingAs($this->createUser(Role::SUPPORT));
        self::assertSame(403, $this->get('/admin/gift-cards')->status());
        self::assertSame(403, $this->post('/admin/gift-cards', ['value' => '25', 'quantity' => '1'])->status());
        $this->actingAs($this->createUser(Role::CUSTOMER));
        self::assertSame(403, $this->post('/admin/gift-cards', ['value' => '25', 'quantity' => '1'])->status());
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_gift_cards`'));

        $admin = $this->createUser(Role::FINANCE);
        $this->actingAs($admin);
        $response = $this->post('/admin/gift-cards', ['value' => '25,00', 'quantity' => '3', 'expires_at' => '2027-12-31', 'note' => 'Messe']);
        $location = (string) $response->header('Location');
        self::assertMatchesRegularExpression('#^/admin/gift-cards\?batch=[a-f0-9]{16}$#', $location);
        $batch = substr($location, -16);
        $page = $this->get($location)->body();
        preg_match_all('/\b([A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4})\b/', $page, $matches);
        $codes = array_values(array_unique($matches[1]));
        self::assertCount(3, $codes);
        self::assertStringContainsString('they are not shown again', $page);

        // Only hashes and the last four characters are stored; the audit log has no codes.
        $rows = $this->db->fetchAll('SELECT * FROM `nx_gift_cards` ORDER BY `id`');
        self::assertCount(3, $rows);
        foreach ($rows as $index => $row) {
            $normalized = str_replace('-', '', $codes[$index]);
            self::assertSame(GiftCardService::hash($normalized), $row['code_hash']);
            self::assertSame(substr($normalized, -4), $row['last4']);
            self::assertSame([2500, 'EUR', 'active', 'Messe', $batch], [(int) $row['value'], $row['currency'], $row['status'], $row['note'], $row['batch']]);
            self::assertSame('2027-12-31', substr((string) $row['expires_at'], 0, 10));
        }
        $dump = json_encode($this->db->fetchAll('SELECT * FROM `nx_gift_cards`')) . json_encode($this->db->fetchAll('SELECT * FROM `nx_audit_log`')) . json_encode($this->db->fetchAll('SELECT * FROM `nx_sessions`'));
        foreach ($codes as $code) {
            self::assertStringNotContainsString($code, (string) $dump);
            self::assertStringNotContainsString(str_replace('-', '', $code), (string) $dump);
        }
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = 'gift_card.created' AND `actor_id` = ?", [$admin->id]));

        // CSV right after the creation, once, only for the creating staff member.
        $other = $this->cookies;
        $this->actingAs($this->createUser(Role::ADMIN));
        self::assertSame(410, $this->post('/admin/gift-cards/batch/' . $batch . '/csv')->status());
        $this->cookies = $other;
        $csv = $this->post('/admin/gift-cards/batch/' . $batch . '/csv');
        self::assertSame(200, $csv->status());
        self::assertStringContainsString('attachment; filename="gift-cards-' . $batch . '.csv"', (string) $csv->header('Content-Disposition'));
        foreach ($codes as $code) {
            self::assertStringContainsString($code . ';25.00;EUR;2027-12-31;' . $batch . ';Messe', $csv->body());
        }
        self::assertSame(410, $this->post('/admin/gift-cards/batch/' . $batch . '/csv')->status());
        self::assertStringNotContainsString($codes[0], $this->get($location)->body(), 'Not shown again.');
        self::assertStringContainsString('****-' . substr(str_replace('-', '', $codes[0]), -4), $this->get('/admin/gift-cards')->body());

        // Invalid input.
        $this->post('/admin/gift-cards', ['value' => '25', 'quantity' => '501']);
        $this->post('/admin/gift-cards', ['value' => '25', 'quantity' => '1', 'expires_at' => '2026-05-01']);
        self::assertSame(3, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_gift_cards`'));

        // Voiding an unused card.
        $this->post('/admin/gift-cards/' . (int) $rows[2]['id'] . '/void', ['reason' => 'Lost']);
        self::assertSame(['void', 'Lost'], array_values((array) $this->db->fetchOne('SELECT `status`, `void_reason` FROM `nx_gift_cards` WHERE `id` = ?', [(int) $rows[2]['id']])));
        $customer = $this->createUser();
        try {
            $this->giftCards->redeem($customer, $codes[2], '203.0.113.5');
            self::fail('A voided card was redeemed.');
        } catch (GiftCardException $exception) {
            self::assertSame('This gift card code is not valid or has already been redeemed.', $exception->getMessage());
        }
    }

    public function testRedemptionIsSingleUseAndBookedAsCredit(): void
    {
        [$code, $expired] = [$this->codes(1)[0], $this->codes(1, 1000, new DateTimeImmutable('2026-06-10'))[0]];
        $customer = $this->createUser();
        $other = $this->createUser();

        $this->settings->set('giftcard.enabled', false);
        $this->actingAs($customer);
        self::assertSame(404, $this->get('/account/gift-cards')->status());
        self::assertSame(404, $this->post('/account/gift-cards/redeem', ['code' => $code])->status());
        $this->settings->set('giftcard.enabled', true);

        self::assertStringContainsString('Redeem a gift card', $this->get('/account/gift-cards')->body());
        // Lower case and spaces are accepted.
        $this->post('/account/gift-cards/redeem', ['code' => ' ' . strtolower(str_replace('-', ' ', $code)) . ' ']);
        self::assertSame(2500, $this->credit($customer));
        $ledger = (array) $this->db->fetchOne('SELECT * FROM `nx_credit_ledger` WHERE `user_id` = ?', [$customer->id]);
        self::assertSame([2500, 'gift_card'], [(int) $ledger['amount'], $ledger['reference_type']]);
        self::assertStringStartsWith('Gift card ****', (string) $ledger['reason']);
        $card = (array) $this->db->fetchOne('SELECT * FROM `nx_gift_cards` WHERE `id` = ?', [(int) $ledger['reference_id']]);
        self::assertSame(['redeemed', $customer->id], [$card['status'], (int) $card['redeemed_by']]);
        self::assertStringContainsString('****-' . $card['last4'], $this->get('/account/gift-cards')->body());

        // Single use, also for another customer.
        $this->post('/account/gift-cards/redeem', ['code' => $code]);
        self::assertSame(2500, $this->credit($customer));
        $this->actingAs($other);
        $this->post('/account/gift-cards/redeem', ['code' => $code]);
        $page = $this->get('/account/gift-cards')->body();
        self::assertStringContainsString('This gift card code is not valid or has already been redeemed.', $page);
        self::assertSame(0, $this->credit($other));

        // Expired.
        $this->clock()->freeze(new DateTimeImmutable('2026-06-11 00:30:00'));
        try {
            $this->giftCards->redeem($other, $expired, '203.0.113.6');
            self::fail('An expired card was redeemed.');
        } catch (GiftCardException $exception) {
            self::assertSame('This gift card has expired.', $exception->getMessage());
        }
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = 'gift_card.redeemed'"));
        self::assertGreaterThanOrEqual(3, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = 'gift_card.redeem_failed'"));
    }

    public function testBruteForceIsLimitedPerCustomerAndNetwork(): void
    {
        $code = $this->codes(1)[0];
        $customer = $this->createUser();
        for ($i = 0; $i < 10; $i++) {
            try {
                $this->giftCards->redeem($customer, 'AAAA-AAAA-AAAA-AAA' . 'BCDEFGHJKL'[$i], '198.51.100.7');
            } catch (GiftCardException) {
            }
        }
        try {
            $this->giftCards->redeem($customer, $code, '198.51.100.7');
            self::fail('The eleventh attempt was evaluated.');
        } catch (GiftCardException $exception) {
            self::assertSame('Too many attempts. Please try again in an hour.', $exception->getMessage());
        }
        self::assertSame('active', $this->db->fetchValue('SELECT `status` FROM `nx_gift_cards`'), 'Even a correct code is not checked above the limit.');
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = 'gift_card.redeem_throttled'"));

        // Other accounts from the same network share a limit of 30 attempts per hour.
        for ($account = 0; $account < 2; $account++) {
            $user = $this->createUser();
            for ($i = 0; $i < 10; $i++) {
                try {
                    $this->giftCards->redeem($user, 'BBBB-BBBB-BBBB-BBBB', '198.51.100.7');
                } catch (GiftCardException) {
                }
            }
        }
        try {
            $this->giftCards->redeem($this->createUser(), $code, '198.51.100.7');
            self::fail('The network limit was not applied.');
        } catch (GiftCardException $exception) {
            self::assertSame('Too many attempts. Please try again in an hour.', $exception->getMessage());
        }

        // An hour later the customer can redeem the code.
        $this->clock()->freeze(new DateTimeImmutable('2026-06-01 13:01:00'));
        self::assertSame(2500, $this->giftCards->redeem($customer, $code, '198.51.100.9'));
    }

    public function testBoughtGiftCardsAreNotTaxedAndSentAfterTheConfirmedPaymentOnly(): void
    {
        $this->settings->set('giftcard.sale_enabled', true);
        $buyer = $this->createUser(Role::CUSTOMER, ['language' => 'de', 'country' => 'DE']);
        $this->db->update('nx_users', ['credit_balance' => 100000], ['id' => $buyer->id]);
        $buyer = $this->app->container()->get(UserRepository::class)->find($buyer->id) ?? $buyer;
        $this->actingAs($buyer);
        self::assertStringContainsString('Gutschein kaufen', $this->get('/account/gift-cards')->body(), 'In the language of the customer.');
        $this->post('/account/gift-cards/buy', ['value' => '5.00', 'accept_terms' => '1']);
        $this->post('/account/gift-cards/buy', ['value' => '50.00']);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_invoices` WHERE `type` = 'gift_card'"), 'Below the minimum or without confirmation.');
        $response = $this->post('/account/gift-cards/buy', ['value' => '50,00', 'accept_terms' => '1']);
        $invoice = (array) $this->db->fetchOne("SELECT * FROM `nx_invoices` WHERE `type` = 'gift_card' AND `user_id` = ?", [$buyer->id]);
        $this->assertRedirect($response, '/invoices/' . $invoice['id']);

        // Not taxable: no VAT, category O with the note (§ 3 Abs. 15 UStG).
        self::assertSame([5000, 0, 5000, 'O', 'unpaid'], [(int) $invoice['subtotal'], (int) $invoice['tax'], (int) $invoice['total'], $invoice['tax_category'], $invoice['status']]);
        self::assertStringContainsString('§ 3 Abs. 15 UStG', (string) $invoice['tax_note']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT `tax_rate_bp` FROM `nx_invoice_items` WHERE `invoice_id` = ?', [$invoice['id']]));
        self::assertStringContainsString('Gutschein', (string) $this->db->fetchValue('SELECT `description` FROM `nx_invoice_items` WHERE `invoice_id` = ?', [$invoice['id']]));
        $xml = $this->app->container()->get(InvoiceDocuments::class)->xml((int) $invoice['id']);
        self::assertStringContainsString('<ram:CategoryCode>O</ram:CategoryCode>', $xml);
        self::assertStringContainsString('<ram:TaxTotalAmount currencyID="EUR">0.00</ram:TaxTotalAmount>', $xml);

        // Not with account credit, and nothing is issued before the payment.
        self::assertArrayNotHasKey('credit', $this->app->container()->get(GatewayRegistry::class)->availableFor($invoice, $buyer));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_gift_cards`'));
        self::assertFalse($this->mailQueued('gift_card_purchased'));
        $this->get('/invoices/' . $invoice['id'] . '/return/stripe');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_gift_cards`'), 'A return URL does not issue a code.');

        // Confirmed payment (here: recorded bank transfer) → code by e-mail in the buyer's language.
        $payments = $this->app->container()->get(PaymentService::class);
        self::assertTrue($payments->recordPayment('manual', 'manual-gc-1', (int) $invoice['id'], 5000, 'EUR')->isSuccessful());
        $payments->recordPayment('manual', 'manual-gc-1', (int) $invoice['id'], 5000, 'EUR');
        $card = (array) $this->db->fetchOne('SELECT * FROM `nx_gift_cards`');
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM `nx_gift_cards`'), 'Issued once.');
        self::assertSame(['purchase', (int) $invoice['id'], $buyer->id, 5000, 'active'], [$card['source'], (int) $card['invoice_id'], (int) $card['purchased_by'], (int) $card['value'], $card['status']]);
        self::assertSame('2029-06-01', substr((string) $card['expires_at'], 0, 10), 'Valid for 36 months by default.');
        $mail = $this->lastMail('gift_card_purchased');
        self::assertNotNull($mail);
        self::assertSame($buyer->email, $mail['to']);
        self::assertStringContainsString('Ihr Gutschein über 50,00 €', $mail['subject']);
        self::assertSame(1, preg_match('/([A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4})/', $mail['text'], $match));
        self::assertSame($card['code_hash'], GiftCardService::hash(str_replace('-', '', $match[1])));
        self::assertSame(100000, $this->credit($buyer), 'Buying a gift card does not change the buyer\'s credit.');

        // The recipient redeems it.
        $friend = $this->createUser();
        self::assertSame(5000, $this->giftCards->redeem($friend, $match[1], '203.0.113.20'));
        self::assertSame(5000, $this->credit($friend));
    }

    public function testRefundsAndChargebacksReverseBoughtGiftCards(): void
    {
        $this->settings->set('giftcard.sale_enabled', true);
        $buyer = $this->createUser();
        $sales = $this->app->container()->get(\Nebrix\Finance\GiftCards\GiftCardSales::class);
        $payments = $this->app->container()->get(PaymentService::class);

        // Refund of an unused card: the card is voided.
        $first = $sales->createInvoice($buyer, 2000);
        $payments->recordPayment('stripe', 'pi_gc_1', $first, 2000, 'EUR');
        self::assertTrue($payments->recordRefund('stripe', 're_gc_1', 'pi_gc_1', 2000, 'EUR')->isSuccessful());
        self::assertSame('void', $this->db->fetchValue('SELECT `status` FROM `nx_gift_cards` WHERE `invoice_id` = ?', [$first]));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_payment_events` WHERE `event_type` = 'gift_card.reversed' AND `invoice_id` = ?", [$first]));

        // Chargeback after the card was redeemed: the credit is taken back from the redeeming account.
        $second = $sales->createInvoice($buyer, 3000);
        $payments->recordPayment('stripe', 'pi_gc_2', $second, 3000, 'EUR');
        $mail = $this->lastMail('gift_card_purchased');
        self::assertNotNull($mail);
        preg_match('/([A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4})/', $mail['text'], $match);
        $code = $match[1];
        $friend = $this->createUser();
        $this->giftCards->redeem($friend, $code, '203.0.113.30');

        // Staff cannot refund a redeemed gift card.
        $this->settings->set('payments.stripe.enabled', true);
        $this->settings->set('payments.stripe.secret_key', 'sk_test_abc123');
        $stripe = new FakeStripeHttp();
        ApiRequestor::setHttpClient($stripe);
        try {
            $this->actingAs($this->createUser(Role::FINANCE));
            $transaction = (int) $this->db->fetchValue("SELECT `id` FROM `nx_transactions` WHERE `reference` = 'pi_gc_2'");
            $this->get('/admin/transactions');
            self::assertTrue($this->post('/admin/transactions/' . $transaction . '/refund', ['amount' => '30.00', 'reason' => 'Customer request', 'refund_key' => str_repeat('ab', 16)])->isRedirect());
            self::assertStringContainsString('The gift card has already been redeemed and cannot be refunded.', $this->get('/admin/transactions')->body());
            self::assertSame([], $stripe->callsTo('post', '/v1/refunds'));
        } finally {
            ApiRequestor::setHttpClient(null);
        }

        $payments->recordDispute('stripe', 'dp_gc_2', 'pi_gc_2', 3000, 'EUR');
        self::assertSame(0, $this->credit($friend), 'The credit is taken back.');
        $card = (array) $this->db->fetchOne('SELECT * FROM `nx_gift_cards` WHERE `invoice_id` = ?', [$second]);
        self::assertSame('void', $card['status']);
        self::assertStringContainsString('after redemption', (string) $card['void_reason']);
        self::assertSame(-3000, (int) $this->db->fetchValue("SELECT `amount` FROM `nx_credit_ledger` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1", [$friend->id]));
        self::assertSame(InvoiceRepository::TYPE_GIFT_CARD, $this->db->fetchValue('SELECT `type` FROM `nx_invoices` WHERE `id` = ?', [$second]));
    }
}
