<?php

declare(strict_types=1);

namespace Nebrix\Finance\Partner;

/**
 * IBAN validation (ISO 13616): country-specific length and the mod-97
 * check digits. Covers the SEPA countries, which are the only ones a SEPA
 * credit transfer can be sent to.
 */
final class Iban
{
    /** @var array<string, int> country => IBAN length (SEPA area) */
    private const LENGTHS = [
        'AD' => 24, 'AT' => 20, 'BE' => 16, 'BG' => 22, 'CH' => 21, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18,
        'EE' => 20, 'ES' => 24, 'FI' => 18, 'FR' => 27, 'GB' => 22, 'GI' => 23, 'GR' => 27, 'HR' => 21, 'HU' => 28,
        'IE' => 22, 'IS' => 26, 'IT' => 27, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21, 'MC' => 27, 'MT' => 31,
        'NL' => 18, 'NO' => 15, 'PL' => 28, 'PT' => 25, 'RO' => 24, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27,
        'VA' => 22,
    ];

    public static function normalize(string $iban): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $iban));
    }

    public static function isValid(string $iban): bool
    {
        $iban = self::normalize($iban);
        if (preg_match('/^([A-Z]{2})(\d{2})([A-Z0-9]+)$/', $iban, $match) !== 1) {
            return false;
        }
        if ((self::LENGTHS[$match[1]] ?? 0) !== strlen($iban)) {
            return false;
        }

        return self::mod97(substr($iban, 4) . substr($iban, 0, 4)) === 1;
    }

    /** Display form of a stored IBAN of which only the last four characters are shown. */
    public static function mask(string $last4): string
    {
        return '**** ' . $last4;
    }

    /** Remainder of the number formed by the characters (A = 10 … Z = 35) modulo 97, computed piecewise. */
    private static function mod97(string $value): int
    {
        $digits = '';
        foreach (str_split($value) as $char) {
            $digits .= ctype_digit($char) ? $char : (string) (ord($char) - 55);
        }
        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }

        return $remainder;
    }
}
