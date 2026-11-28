<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature\Finance;

use DateTimeImmutable;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Nebrix\Auth\Role;
use Nebrix\Auth\User;
use Nebrix\Billing\InvoiceService;
use Nebrix\Billing\OrderService;
use Nebrix\Billing\Tax\TaxService;
use Nebrix\Finance\Accounting\AccountingSync;
use Nebrix\Repository\ProductRepository;
use Nebrix\Repository\UserRepository;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Tests\FeatureTestCase;
use Nebrix\Tests\Support\FakeHttp;
use Psr\Http\Message\RequestInterface;

/**
 * Transfer of invoices to lexoffice – against a mocked API only: vouchers
 * with PDF, idempotency, interrupted attempts, errors, manual sync.
 */
final class LexofficeSyncTest extends FeatureTestCase
{
    private FakeHttp $http;
    private SettingsRepository $settings;
    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock()->freeze(new DateTimeImmutable('2026-04-02 10:00:00'));
        $this->http = new FakeHttp();
        $this->app->container()->set(ClientInterface::class, fn (): ClientInterface => $this->http->client());
        $this->settings = $this->app->container()->get(SettingsRepository::class);
        $this->settings->set('lexoffice.enabled', true);
        $this->settings->set('lexoffice.api_key', 'test-key-123');
        $this->app->container()->get(AccountingSync::class)->withoutPause();

        $this->http
            ->on('POST', '/v1/vouchers', function (RequestInterface $request): Response {
                $id = sprintf('%08x-0000-4000-8000-%012x', count($this->created) + 1, count($this->created) + 1);
                $this->created[] = (string) (FakeHttp::json($request)['voucherNumber'] ?? '');

                return new Response(201, ['Content-Type' => 'application/json'], (string) json_encode(['id' => $id, 'version' => 0]));
            })
            ->on('POST', '/v1/vouchers/(?<id>[0-9a-f-]{36})/files', ['id' => 'file-1'], 202)
            ->on('GET', '/v1/voucherlist', ['content' => []]);
    }

    private function customer(string $country, ?string $vatId = null): User
    {
        $user = $this->createUser(Role::CUSTOMER, ['country' => $country, 'vat_id' => $vatId, 'company' => $vatId !== null ? 'Example Trading GmbH' : null]);
        if ($vatId !== null) {
            $this->app->container()->get(TaxService::class)->verifyUser($user);
        }

        return $this->app->container()->get(UserRepository::class)->find($user->id) ?? $user;
    }

    private function order(User $customer): int
    {
        $product = $this->app->container()->get(ProductRepository::class)->create([
            'category' => 'dedicated', 'name' => 'Root S', 'slug' => 'root-' . bin2hex(random_bytes(3)), 'price' => 1000, 'setup_fee' => 0, 'currency' => 'EUR',
            'billing_cycle' => 'monthly', 'tax_rate_bp' => 1900, 'is_visible' => 1, 'is_active' => 1, 'auto_provision' => 0, 'provisioning_config' => [],
        ]);

        return $this->app->container()->get(OrderService::class)->place($customer, $product, ['hostname' => 'srv' . random_int(1, 9999)], '127.0.0.1')['invoice_id'];
    }

    private function sync(bool $retryAll = false): array
    {
        return $this->app->container()->get(AccountingSync::class)->run(50, $retryAll);
    }

    public function testVouchersArePushedOnceWithThePdf(): void
    {
        $customer = $this->customer('DE');
        $invoice = $this->order($customer);
        $cancelled = $this->order($customer);
        $this->app->container()->get(InvoiceService::class)->cancel($cancelled);
        $this->app->container()->get(InvoiceService::class)->createCreditTopUp($customer, 2000, 'EUR');

        self::assertSame(['synced' => 3, 'failed' => 0, 'skipped' => 0], $this->sync(), 'Invoice, cancelled invoice and its cancellation document; the top-up is no revenue.');
        $vouchers = $this->http->requests('POST', '/v1/vouchers');
        self::assertCount(3, $vouchers);
        self::assertSame('Bearer test-key-123', $vouchers[0]->getHeaderLine('Authorization'));
        $first = FakeHttp::json($vouchers[0]);
        self::assertSame('salesinvoice', $first['type']);
        self::assertSame((string) $this->db->fetchValue('SELECT `number` FROM `nx_invoices` WHERE `id` = ?', [$invoice]), $first['voucherNumber']);
        self::assertSame([11.9, 1.9, 'gross', true], [$first['totalGrossAmount'], $first['totalTaxAmount'], $first['taxType'], $first['useCollectiveContact']]);
        self::assertSame([['amount' => 11.9, 'taxAmount' => 1.9, 'taxRatePercent' => 19, 'categoryId' => '8f8664a8-fd86-11e1-a21f-0800200c9a66']], $first['voucherItems']);
        self::assertSame('2026-04-02T12:00:00.000+02:00', $first['voucherDate'], 'Local time of the installation.');
        self::assertSame('salescreditnote', FakeHttp::json($vouchers[2])['type']);
        self::assertArrayNotHasKey('dueDate', FakeHttp::json($vouchers[2]));

        $files = $this->http->requests('POST', '/v1/vouchers/[0-9a-f-]{36}/files');
        self::assertCount(3, $files);
        self::assertStringContainsString('%PDF', (string) $files[0]->getBody());
        self::assertStringContainsString('name="type"', (string) $files[0]->getBody());

        // Idempotent: nothing is sent again.
        $before = count($this->http->history);
        self::assertSame(['synced' => 0, 'failed' => 0, 'skipped' => 0], $this->sync());
        self::assertCount($before, $this->http->history);
        self::assertSame(3, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_accounting_sync` WHERE `status` = 'synced' AND `file_uploaded` = 1 AND `external_id` IS NOT NULL"));
    }

    public function testInterruptedAttemptsAndErrorsDoNotCreateDuplicates(): void
    {
        $customer = $this->customer('DE');
        $invoice = $this->order($customer);
        $number = (string) $this->db->fetchValue('SELECT `number` FROM `nx_invoices` WHERE `id` = ?', [$invoice]);

        // The voucher is created, but the answer is lost.
        $this->http->on('POST', '/v1/vouchers', function (): Response {
            $this->created[] = 'lost';

            return new Response(502, [], 'Bad gateway');
        });
        self::assertSame(1, $this->sync()['failed']);
        $row = (array) $this->db->fetchOne('SELECT * FROM `nx_accounting_sync` WHERE `invoice_id` = ?', [$invoice]);
        self::assertSame(['creating', 1, null], [$row['status'], (int) $row['attempts'], $row['external_id']]);
        self::assertStringContainsString('lexoffice API error 502', (string) $row['last_error']);

        // Next run: the voucher is found by its number and not created again.
        $this->http->on('GET', '/v1/voucherlist', ['content' => [['id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'voucherNumber' => $number]]]);
        $this->http->on('POST', '/v1/vouchers/(?<id>[0-9a-f-]{36})/files', ['message' => 'storage error'], 500);
        self::assertSame(1, $this->sync()['failed']);
        self::assertCount(1, $this->created);
        $row = (array) $this->db->fetchOne('SELECT * FROM `nx_accounting_sync` WHERE `invoice_id` = ?', [$invoice]);
        self::assertSame(['failed', 'aaaaaaaa-0000-4000-8000-000000000001', 0], [$row['status'], $row['external_id'], (int) $row['file_uploaded']]);
        self::assertStringContainsString('storage error', (string) $row['last_error']);

        // Upload works again: only the file is sent.
        $this->http->on('POST', '/v1/vouchers/(?<id>[0-9a-f-]{36})/files', ['id' => 'f'], 202);
        $vouchers = count($this->http->requests('POST', '/v1/vouchers'));
        self::assertSame(1, $this->sync()['synced']);
        self::assertCount($vouchers, $this->http->requests('POST', '/v1/vouchers'));
        self::assertSame('/v1/vouchers/aaaaaaaa-0000-4000-8000-000000000001/files', $this->http->last()->getUri()->getPath());

        // A tax case without a posting category fails before anything is sent.
        $business = $this->customer('AT', 'ATU12345678');
        $reverse = $this->order($business);
        $sent = count($this->http->history);
        self::assertSame(1, $this->sync()['failed']);
        self::assertCount($sent, $this->http->history);
        self::assertStringContainsString('No lexoffice posting category', (string) $this->db->fetchValue('SELECT `last_error` FROM `nx_accounting_sync` WHERE `invoice_id` = ?', [$reverse]));

        // A rejected key is reported (and never logged in the error text).
        $this->settings->set('lexoffice.category_reverse_charge', '8f8664a8-fd86-11e1-a21f-0800200c9a66');
        $this->http->on('GET', '/v1/voucherlist', ['message' => 'unauthorized'], 401);
        $this->sync();
        $error = (string) $this->db->fetchValue('SELECT `last_error` FROM `nx_accounting_sync` WHERE `invoice_id` = ?', [$reverse]);
        self::assertStringContainsString('the API key was rejected', $error);
        self::assertStringNotContainsString('test-key-123', $error);
    }

    public function testFinanceSettingsPageStoresTheApiKeyEncrypted(): void
    {
        $this->actingAs($this->createUser(Role::FINANCE));
        self::assertSame(403, $this->get('/admin/settings/finance')->status(), 'finance.settings is not part of the finance role.');

        $this->actingAs($this->createUser(Role::ADMIN));
        $page = $this->get('/admin/settings/finance')->body();
        foreach (['DATEV consultant number', 'lexoffice API key', 'Book clear matches automatically', 'Customers can redeem gift cards', 'Enable the partner program', 'Assign customer groups automatically'] as $label) {
            self::assertStringContainsString($label, $page);
        }
        self::assertStringNotContainsString('test-key-123', $page, 'Secrets are never sent back.');
        $this->post('/admin/settings/finance', ['group' => 'lexoffice', 'lexoffice__enabled' => '1', 'lexoffice__api_key' => 'new-secret-key', 'lexoffice__category_standard' => '8f8664a8-fd86-11e1-a21f-0800200c9a66']);
        $stored = (string) $this->db->fetchValue("SELECT `value` FROM `nx_settings` WHERE `key` = 'lexoffice.api_key'");
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString('new-secret-key', $stored);
        $this->settings->flush();
        self::assertSame('new-secret-key', $this->settings->get('lexoffice.api_key'));
        $audit = (string) $this->db->fetchValue("SELECT `details` FROM `nx_audit_log` WHERE `action` = 'settings.updated' ORDER BY `id` DESC LIMIT 1");
        self::assertStringContainsString('lexoffice.api_key', $audit);
        self::assertStringNotContainsString('new-secret-key', $audit);
    }

    public function testFailedTransfersStopAfterTenAttemptsUnlessSyncedManually(): void
    {
        $invoice = $this->order($this->customer('DE'));
        $this->http->on('GET', '/v1/voucherlist', ['message' => 'down'], 503);
        $this->http->on('POST', '/v1/vouchers', ['message' => 'down'], 503);
        for ($i = 0; $i < 10; $i++) {
            $this->sync();
        }
        self::assertSame(10, (int) $this->db->fetchValue('SELECT `attempts` FROM `nx_accounting_sync` WHERE `invoice_id` = ?', [$invoice]));
        self::assertSame(['synced' => 0, 'failed' => 0, 'skipped' => 0], $this->sync(), 'The scheduler gives up after ten attempts.');

        // Staff with accounting.export retry manually.
        $this->http->on('GET', '/v1/voucherlist', ['content' => []]);
        $this->http->on('POST', '/v1/vouchers', ['id' => 'bbbbbbbb-0000-4000-8000-000000000002'], 201);
        $this->actingAs($this->createUser(Role::SUPPORT));
        self::assertSame(403, $this->post('/admin/accounting/lexoffice/sync')->status());
        $admin = $this->createUser(Role::ADMIN);
        $this->actingAs($admin);
        $page = $this->followRedirect($this->post('/admin/accounting/lexoffice/sync'))->body();
        self::assertStringContainsString('The transfer to lexoffice is complete.', $page);
        self::assertStringContainsString('1 documents transferred, 0 open.', $page);
        self::assertSame('synced', $this->db->fetchValue('SELECT `status` FROM `nx_accounting_sync` WHERE `invoice_id` = ?', [$invoice]));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_audit_log` WHERE `action` = 'accounting.lexoffice_sync' AND `actor_id` = ?", [$admin->id]));

        // Switched off: nothing happens.
        $this->settings->set('lexoffice.enabled', false);
        self::assertTrue($this->post('/admin/accounting/lexoffice/sync')->isRedirect());
        self::assertSame(['synced' => 0, 'failed' => 0, 'skipped' => 0], $this->sync());
    }
}
