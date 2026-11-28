<?php

declare(strict_types=1);

namespace Nebrix\Finance\Bank;

use Nebrix\Settings\SettingsRepository;

/**
 * Finds invoice numbers ("NX-2026-000123") in a remittance text. Banks and
 * payers change the spelling: spaces, dashes, dots or slashes between the
 * parts, missing separators or leading zeros ("NX 2026 123",
 * "nx2026000123") are all recognised.
 */
class InvoiceReferenceFinder
{
    private const SEPARATOR = '[\s\-_.\/]*';

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    /**
     * @return list<string> invoice numbers in the stored format, without duplicates
     */
    public function find(string $text): array
    {
        $prefix = $this->settings->string('billing.invoice_prefix');
        $letters = str_split(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $prefix)));
        $prefixPattern = $letters === [] || $letters === [''] ? '' : implode(self::SEPARATOR, array_map(static fn (string $char): string => preg_quote($char, '/'), $letters)) . self::SEPARATOR;
        // Year and a number of up to six digits that is not followed by another digit. Without a
        // prefix the year must not follow a digit either (e.g. inside an IBAN).
        $pattern = '/' . ($prefixPattern === '' ? '(?<!\d)' : '') . $prefixPattern . '(20\d{2})' . self::SEPARATOR . '(\d{1,6})(?!\d)/i';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === false) {
            return [];
        }
        $numbers = [];
        foreach ($matches as $match) {
            $numbers[] = sprintf('%s%s-%06d', $prefix, $match[1], (int) $match[2]);
        }

        return array_values(array_unique($numbers));
    }
}
