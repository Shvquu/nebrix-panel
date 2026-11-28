<?php

declare(strict_types=1);

namespace Nebrix\Finance;

/**
 * CSV files for spreadsheets: UTF-8 with BOM, semicolon, CRLF. Text that a
 * spreadsheet would run as a formula (=, +, -, @, tab, CR at the start) is
 * prefixed with an apostrophe; plain numbers such as "-12.50" stay numbers.
 */
final class Csv
{
    /**
     * @param list<list<string>> $rows
     */
    public static function build(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('The CSV file could not be created.');
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::guard(...), $row), ';', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF" . str_replace("\n", "\r\n", str_replace("\r\n", "\n", $csv));
    }

    public static function guard(string $value): string
    {
        if ($value !== '' && preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1 && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
