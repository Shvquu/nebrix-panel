<?php

declare(strict_types=1);

namespace Nebrix\Finance\GiftCards;

use Nebrix\Auth\User;
use Nebrix\Billing\InvoiceRepository;
use Nebrix\Billing\InvoiceService;
use Nebrix\Billing\Money;
use Nebrix\Billing\Tax\TaxDecision;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Settings\SettingsRepository;
use Nebrix\View\Translator;

/**
 * Sale of gift cards in the customer area.
 *
 * A gift card for account credit that can be used for every product is a
 * multi-purpose voucher (§ 3 Abs. 15 UStG): its sale is not a taxable
 * supply, VAT arises when the credit pays an invoice (those invoices carry
 * it as usual). The purchase document is therefore issued without VAT with
 * category O ("not subject to VAT") and an explanatory note, like a credit
 * top-up. The code is created and e-mailed only when the payment has been
 * confirmed (GiftCardService::issueForInvoice from InvoiceService::markPaid);
 * paying it with account credit is not possible.
 */
class GiftCardSales
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly InvoiceRepository $invoices,
        private readonly SettingsRepository $settings,
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Translator $translator,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('giftcard.sale_enabled') && $this->settings->bool('giftcard.enabled');
    }

    /**
     * @return array{0: int, 1: int} minimum and maximum value (minor units)
     */
    public function limits(): array
    {
        $currency = $this->currency();
        $parse = static function (string $value, int $fallback) use ($currency): int {
            try {
                return Money::parse($value !== '' ? $value : '0', $currency) ?: $fallback;
            } catch (\InvalidArgumentException) {
                return $fallback;
            }
        };

        return [$parse($this->settings->string('giftcard.sale_min'), 1000), $parse($this->settings->string('giftcard.sale_max'), 50000)];
    }

    public function currency(): string
    {
        return strtoupper($this->settings->string('general.default_currency') ?: 'EUR');
    }

    /**
     * Purchase invoice for a gift card of the given value.
     *
     * @throws GiftCardException
     */
    public function createInvoice(User $user, int $value): int
    {
        if (!$this->enabled()) {
            throw new GiftCardException('Gift cards cannot be bought at the moment.');
        }
        [$min, $max] = $this->limits();
        if ($value < $min || $value > $max) {
            throw new GiftCardException('Choose a value within the limits shown.');
        }
        $currency = $this->currency();
        $language = (string) ($user->attribute('language') ?? 'en') ?: 'en';

        return $this->db->transaction(fn (): int => $this->invoices->create([
            'number' => $this->invoiceService->nextNumber('invoice'),
            'user_id' => $user->id,
            'type' => InvoiceRepository::TYPE_GIFT_CARD,
            'status' => InvoiceRepository::STATUS_UNPAID,
            'currency' => $currency,
            'subtotal' => $value,
            'tax' => 0,
            'total' => $value,
            'tax_category' => TaxDecision::OUTSIDE_SCOPE,
            'tax_note' => mb_substr($this->settings->string('giftcard.tax_note'), 0, 255) ?: null,
            'tax_country' => null,
            'billing_details' => json_encode($this->invoiceService->billingSnapshot($user), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'due_at' => $this->clock->now()->modify('+7 days')->format('Y-m-d H:i:s'),
        ], [[
            'description' => $this->translator->translateTo($language, 'Gift card worth :value (multi-purpose voucher)', ['value' => Money::format($value, $currency, $language)]),
            'quantity' => 1,
            'unit_amount' => $value,
            'tax_rate_bp' => 0,
            'total' => $value,
        ]]));
    }
}
