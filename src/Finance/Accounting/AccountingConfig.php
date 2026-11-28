<?php

declare(strict_types=1);

namespace Nebrix\Finance\Accounting;

use Nebrix\Billing\Tax\EuVat;
use Nebrix\Settings\SettingsRepository;
use RuntimeException;

/**
 * Account assignment for the accounting export (settings group
 * "accounting"). Accounts left empty in the settings use the defaults of the
 * chosen chart of accounts (DATEV SKR 03 / SKR 04). The defaults are common
 * choices, not advice: the tax advisor decides.
 */
class AccountingConfig
{
    /** Tax cases of an invoice line. */
    public const STANDARD = 'standard';
    public const REDUCED = 'reduced';
    public const REVERSE_CHARGE = 'reverse_charge';
    public const OSS = 'oss';
    public const THIRD_COUNTRY = 'third_country';
    public const SMALL_BUSINESS = 'small_business';
    /** Prepaid credit (top-ups, gift cards): not revenue. */
    public const CUSTOMER_CREDIT = 'customer_credit';

    /** @var array<string, array<string, string>> chart => purpose => account */
    public const DEFAULTS = [
        'skr03' => [
            self::STANDARD => '8400', self::REDUCED => '8300', self::REVERSE_CHARGE => '8336', self::OSS => '8320',
            self::THIRD_COUNTRY => '8338', self::SMALL_BUSINESS => '8195', self::CUSTOMER_CREDIT => '1710',
            'bank' => '1200', 'paypal' => '1360', 'stripe' => '1360',
        ],
        'skr04' => [
            self::STANDARD => '4400', self::REDUCED => '4300', self::REVERSE_CHARGE => '4336', self::OSS => '4320',
            self::THIRD_COUNTRY => '4338', self::SMALL_BUSINESS => '4185', self::CUSTOMER_CREDIT => '3250',
            'bank' => '1800', 'paypal' => '1460', 'stripe' => '1460',
        ],
    ];

    private const SETTING = [
        self::STANDARD => 'accounting.account_revenue_standard',
        self::REDUCED => 'accounting.account_revenue_reduced',
        self::REVERSE_CHARGE => 'accounting.account_revenue_reverse_charge',
        self::OSS => 'accounting.account_revenue_oss',
        self::THIRD_COUNTRY => 'accounting.account_revenue_third_country',
        self::SMALL_BUSINESS => 'accounting.account_revenue_small_business',
        self::CUSTOMER_CREDIT => 'accounting.account_customer_credit',
        'bank' => 'accounting.account_bank',
        'paypal' => 'accounting.account_paypal',
        'stripe' => 'accounting.account_stripe',
    ];

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function chart(): string
    {
        return $this->settings->string('accounting.chart') === 'skr04' ? 'skr04' : 'skr03';
    }

    /** Length of general ledger accounts (DATEV "Sachkontenlänge"). */
    public function accountLength(): int
    {
        return max(4, min(8, $this->settings->int('accounting.account_length') ?: 4));
    }

    /**
     * Account for a purpose (tax case, customer credit, bank, paypal, stripe).
     * Default accounts get trailing zeros for longer account numbers.
     */
    public function account(string $purpose): string
    {
        $setting = self::SETTING[$purpose] ?? throw new RuntimeException('Unknown account purpose ' . $purpose . '.');
        $configured = trim($this->settings->string($setting));
        $length = $this->accountLength();
        if ($configured === '') {
            return str_pad(self::DEFAULTS[$this->chart()][$purpose], $length, '0');
        }
        if (strlen($configured) !== $length) {
            throw new RuntimeException(sprintf('The account %s does not have %d digits (setting "%s").', $configured, $length, $setting));
        }

        return $configured;
    }

    /** BU key for a tax case (only needed for accounts without automatic VAT). */
    public function buKey(string $case): string
    {
        foreach (preg_split('/[\s,;]+/', $this->settings->string('accounting.bu_keys')) ?: [] as $entry) {
            if (preg_match('/^([a-z_]+)=(\d{1,4})$/', trim($entry), $match) === 1 && $match[1] === $case) {
                return $match[2];
            }
        }

        return '';
    }

    /** Debtor account of a customer: collective account or base + customer number. */
    public function debtorAccount(int $userId): string
    {
        $base = (int) $this->settings->string('accounting.debtor_account') ?: 10000;
        $number = $this->settings->string('accounting.debtor_mode') === 'collective' ? $base : $base + $userId;
        // Debtors are personal accounts with one digit more than the ledger accounts: 1…6 followed by digits.
        $length = $this->accountLength() + 1;
        $min = 10 ** ($length - 1);
        $max = 7 * 10 ** ($length - 1) - 1;
        if ($number < $min || $number > $max) {
            throw new RuntimeException(sprintf('The debtor account %d for customer %d is outside the debtor range %d–%d. Change the base number or the account length.', $number, $userId, $min, $max));
        }

        return (string) $number;
    }

    /**
     * Tax case of an invoice line from the stored tax decision of the invoice.
     *
     * OSS cannot be told apart from a domestic rate on the invoice itself when
     * the rates are equal (e.g. Cyprus 19 %); such lines count as OSS while
     * OSS is enabled in the tax settings.
     */
    public function taxCase(?string $category, ?string $country, int $rateBp): string
    {
        $home = strtoupper($this->settings->string('tax.home_country') ?: 'DE');
        $homeRate = EuVat::STANDARD_RATES[$home] ?? 1900;
        $country = $country !== null && $country !== '' ? strtoupper($country) : null;
        $foreignEu = $country !== null && $country !== $home && EuVat::isMember($country);

        return match ($category) {
            'AE' => self::REVERSE_CHARGE,
            'O' => self::THIRD_COUNTRY,
            'E' => self::SMALL_BUSINESS,
            default => $foreignEu && ($this->settings->bool('tax.oss') || ($rateBp !== $homeRate && $rateBp === (EuVat::STANDARD_RATES[(string) $country] ?? -1)))
                ? self::OSS
                : ($rateBp > 0 && $rateBp < $homeRate ? self::REDUCED : self::STANDARD),
        };
    }
}
