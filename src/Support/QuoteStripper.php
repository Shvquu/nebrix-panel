<?php

declare(strict_types=1);

namespace Nebrix\Support;

/**
 * Reduces an e-mail reply to the new text: removes the quoted previous
 * message ("On … wrote:", "> …", "-----Original Message-----", Outlook
 * headers) and the signature ("-- ", "Sent from my …"), in English and
 * German.
 */
final class QuoteStripper
{
    public static function strip(string $text): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $kept = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = rtrim($lines[$i]);
            $trimmed = trim($line);
            $next = trim($lines[$i + 1] ?? '');

            if ($line === '--' || $line === '-- ' || str_starts_with($trimmed, '>')
                || preg_match('/^-{2,}\s*(original message|ursprüngliche nachricht|forwarded message|weitergeleitete nachricht|reply above this line|oberhalb dieser zeile antworten)\s*-{2,}/iu', $trimmed) === 1
                || preg_match('/^_{10,}$/', $trimmed) === 1
                || preg_match('/^(sent from my|von meinem|gesendet von meinem|get outlook for)\b/iu', $trimmed) === 1
                || self::isQuoteHeader($trimmed, $next)
                || self::isOutlookHeader($lines, $i)) {
                break;
            }
            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }

    /** "On Mon, 1 Dec 2026, Support <…> wrote:" / "Am 01.12.2026 um 10:00 schrieb …:" (also over two lines). */
    private static function isQuoteHeader(string $line, string $next): bool
    {
        if (preg_match('/^(on|am)\s/iu', $line) !== 1) {
            return false;
        }
        $pattern = '/(wrote|schrieb)(\s[^:]{0,100})?:$/iu';

        return preg_match($pattern, $line) === 1 || ($next !== '' && mb_strlen($line) < 200 && preg_match($pattern, $next) === 1 && mb_strlen($next) < 120);
    }

    /**
     * "From: …" followed by "Sent:"/"Date:" within the next lines (Outlook, Apple Mail forwards).
     *
     * @param list<string> $lines
     */
    private static function isOutlookHeader(array $lines, int $index): bool
    {
        if (preg_match('/^\*?(from|von):\*?\s/iu', trim($lines[$index])) !== 1) {
            return false;
        }
        for ($j = $index + 1; $j <= $index + 3 && $j < count($lines); $j++) {
            if (preg_match('/^\*?(sent|gesendet|date|datum|to|an):\*?\s/iu', trim($lines[$j])) === 1) {
                return true;
            }
        }

        return false;
    }
}
