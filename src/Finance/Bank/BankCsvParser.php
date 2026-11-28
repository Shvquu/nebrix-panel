<?php

declare(strict_types=1);

namespace Nebrix\Finance\Bank;

use InvalidArgumentException;

/**
 * Reads bank CSV exports with a column mapping (built-in presets for common
 * German formats or a profile saved by staff). The header row is the first
 * row that contains all mapped column names, so preamble lines (account,
 * period) are skipped.
 *
 * Mapping keys: delimiter (";", ",", "tab"), encoding (UTF-8, Windows-1252,
 * ISO-8859-1), date_format (d.m.Y, d.m.y, Y-m-d), decimal ("," or "."),
 * and the column names booking_date, value_date, amount, currency,
 * remittance (several separated by "|"), counterparty_name,
 * counterparty_iban, reference, status + booked_value (only rows with this
 * status are read, e.g. "Gebucht").
 */
final class BankCsvParser
{
    public const MAX_ROWS = 20000;

    /** @var array<string, array{label: string, mapping: array<string, string>}> */
    public const PRESETS = [
        'sparkasse' => ['label' => 'Sparkasse (CSV-CAMT V2)', 'mapping' => [
            'delimiter' => ';', 'encoding' => 'Windows-1252', 'date_format' => 'd.m.y', 'decimal' => ',',
            'booking_date' => 'Buchungstag', 'value_date' => 'Valutadatum', 'amount' => 'Betrag', 'currency' => 'Waehrung',
            'remittance' => 'Verwendungszweck', 'counterparty_name' => 'Beguenstigter/Zahlungspflichtiger', 'counterparty_iban' => 'Kontonummer/IBAN',
            'reference' => '', 'status' => '', 'booked_value' => '',
        ]],
        'dkb' => ['label' => 'DKB (since 2023)', 'mapping' => [
            'delimiter' => ';', 'encoding' => 'UTF-8', 'date_format' => 'd.m.y', 'decimal' => ',',
            'booking_date' => 'Buchungsdatum', 'value_date' => 'Wertstellung', 'amount' => 'Betrag (€)', 'currency' => '',
            'remittance' => 'Verwendungszweck', 'counterparty_name' => 'Zahlungspflichtige*r', 'counterparty_iban' => 'IBAN',
            'reference' => '', 'status' => 'Status', 'booked_value' => 'Gebucht',
        ]],
        'generic' => ['label' => 'Generic (Buchungstag; Wertstellung; Betrag; Waehrung; Verwendungszweck; Name; IBAN)', 'mapping' => [
            'delimiter' => ';', 'encoding' => 'UTF-8', 'date_format' => 'd.m.Y', 'decimal' => ',',
            'booking_date' => 'Buchungstag', 'value_date' => 'Wertstellung', 'amount' => 'Betrag', 'currency' => 'Waehrung',
            'remittance' => 'Verwendungszweck', 'counterparty_name' => 'Name', 'counterparty_iban' => 'IBAN',
            'reference' => '', 'status' => '', 'booked_value' => '',
        ]],
    ];

    public const COLUMNS = ['booking_date', 'value_date', 'amount', 'currency', 'remittance', 'counterparty_name', 'counterparty_iban', 'reference', 'status'];

    /**
     * @param array<string, string> $mapping
     * @return array{entries: list<StatementEntry>, skipped: int}
     */
    public function parse(string $content, array $mapping, string $defaultCurrency): array
    {
        $mapping = self::normalizeMapping($mapping);
        $content = $this->utf8($content, $mapping['encoding']);
        $delimiter = $mapping['delimiter'] === 'tab' ? "\t" : $mapping['delimiter'];

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new InvalidArgumentException('The file could not be read.');
        }
        fwrite($handle, $content);
        rewind($handle);

        $header = null;
        $entries = [];
        $skipped = 0;
        $rows = 0;
        try {
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                if (++$rows > self::MAX_ROWS) {
                    throw new InvalidArgumentException(sprintf('The file has more than %d rows.', self::MAX_ROWS));
                }
                $row = array_map(static fn (?string $cell): string => trim((string) $cell), $row);
                if ($header === null) {
                    $header = $this->header($row, $mapping);
                    continue;
                }
                if (count(array_filter($row, static fn (string $cell): bool => $cell !== '')) === 0) {
                    continue;
                }
                $value = static fn (string $column) => $column !== '' && isset($header[mb_strtolower($column)]) ? ($row[$header[mb_strtolower($column)]] ?? '') : '';
                if ($mapping['status'] !== '' && mb_strtolower($value($mapping['status'])) !== mb_strtolower($mapping['booked_value'])) {
                    $skipped++; // e.g. "Vorgemerkt" (not booked yet)
                    continue;
                }
                $amount = $this->amount($value($mapping['amount']), $mapping['decimal']);
                if ($amount === 0) {
                    $skipped++;
                    continue;
                }
                $currency = strtoupper($value($mapping['currency']) ?: $defaultCurrency);
                if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                    throw new InvalidArgumentException(sprintf('Row %d has an invalid currency.', $rows));
                }
                $remittance = implode(' ', array_filter(array_map(static fn (string $column): string => $value(trim($column)), explode('|', $mapping['remittance']))));
                $valueDate = $value($mapping['value_date']);
                $entries[] = new StatementEntry(
                    $this->date($value($mapping['booking_date']), $mapping['date_format'], $rows),
                    $valueDate !== '' ? $this->date($valueDate, $mapping['date_format'], $rows) : null,
                    $amount > 0 ? StatementEntry::CREDIT : StatementEntry::DEBIT,
                    abs($amount),
                    $currency,
                    Camt053Parser::clean($value($mapping['counterparty_name']), 140),
                    Camt053Parser::iban($value($mapping['counterparty_iban'])),
                    Camt053Parser::clean($remittance, 1000),
                    $value($mapping['reference']) !== '' ? mb_substr($value($mapping['reference']), 0, 100) : null,
                );
            }
        } finally {
            fclose($handle);
        }
        if ($header === null) {
            throw new InvalidArgumentException('The columns of the mapping were not found in the file. Check the profile (column names, delimiter, encoding).');
        }

        return ['entries' => $entries, 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $mapping
     * @return array<string, string>
     */
    public static function normalizeMapping(array $mapping): array
    {
        $normalized = [];
        foreach (['delimiter', 'encoding', 'date_format', 'decimal', ...self::COLUMNS, 'booked_value'] as $key) {
            $normalized[$key] = is_string($mapping[$key] ?? null) ? trim($mapping[$key]) : '';
        }
        if (!in_array($normalized['delimiter'], [';', ',', 'tab'], true)
            || !in_array($normalized['encoding'], ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true)
            || !in_array($normalized['date_format'], ['d.m.Y', 'd.m.y', 'Y-m-d', 'd/m/Y'], true)
            || !in_array($normalized['decimal'], [',', '.'], true)
            || $normalized['booking_date'] === '' || $normalized['amount'] === '' || $normalized['remittance'] === '') {
            throw new InvalidArgumentException('The CSV profile is incomplete.');
        }

        return $normalized;
    }

    /**
     * @param list<string> $row
     * @param array<string, string> $mapping
     * @return array<string, int>|null lower-case column name => index, if this row is the header
     */
    private function header(array $row, array $mapping): ?array
    {
        $columns = [];
        foreach ($row as $index => $cell) {
            $name = mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', $cell)));
            if ($name !== '' && !isset($columns[$name])) {
                $columns[$name] = $index;
            }
        }
        foreach (['booking_date', 'amount'] as $required) {
            if (!isset($columns[mb_strtolower($mapping[$required])])) {
                return null;
            }
        }
        foreach (explode('|', $mapping['remittance']) as $column) {
            if (!isset($columns[mb_strtolower(trim($column))])) {
                return null;
            }
        }

        return $columns;
    }

    private function utf8(string $content, string $encoding): string
    {
        if ($encoding === 'UTF-8') {
            if (!mb_check_encoding($content, 'UTF-8')) {
                throw new InvalidArgumentException('The file is not UTF-8 encoded. Choose the encoding of the bank (often Windows-1252).');
            }

            return $content;
        }
        $converted = mb_convert_encoding($content, 'UTF-8', $encoding);

        return is_string($converted) ? $converted : throw new InvalidArgumentException('The file could not be converted.');
    }

    /** Signed amount in minor units ("-1.234,56" with decimal comma). */
    private function amount(string $value, string $decimal): int
    {
        $value = str_replace([' ', "\u{00A0}", '€', 'EUR'], '', $value);
        $value = $decimal === ',' ? str_replace(['.', ','], ['', '.'], $value) : str_replace(',', '', $value);
        if (preg_match('/^([+-])?(\d{1,12})(?:\.(\d{1,2}))?$/', $value, $match) !== 1) {
            throw new InvalidArgumentException('An amount in the file could not be read: ' . mb_substr($value, 0, 20));
        }
        $minor = (int) $match[2] * 100 + (int) str_pad($match[3] ?? '', 2, '0');

        return $match[1] === '-' ? -$minor : $minor;
    }

    private function date(string $value, string $format, int $row): string
    {
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date === false || $date->format($format) !== $value) {
            throw new InvalidArgumentException(sprintf('Row %d has an invalid date.', $row));
        }

        return $date->format('Y-m-d');
    }
}
