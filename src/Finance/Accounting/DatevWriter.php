<?php

declare(strict_types=1);

namespace Nebrix\Finance\Accounting;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Writes a DATEV "Buchungsstapel" in the DATEV format (EXTF, version 700,
 * data category 21, format version 13): semicolon separated, CRLF line
 * endings, Windows-1252, decimal comma, text fields in double quotes.
 *
 * Line 1 is the header (31 fields), line 2 the column names (125 fields),
 * then one line per booking. Filled columns: 1 Umsatz, 2 Soll/Haben-Kz,
 * 3 WKZ Umsatz, 7 Konto, 8 Gegenkonto, 9 BU-Schlüssel, 10 Belegdatum (TTMM),
 * 11 Belegfeld 1, 14 Buchungstext, 40 EU-Land u. UStID (Bestimmung),
 * 41 EU-Steuersatz (Bestimmung), 114 Festschreibung. All other columns stay
 * empty.
 */
final class DatevWriter
{
    public const VERSION = 700;
    public const CATEGORY = 21;
    public const FORMAT_NAME = 'Buchungsstapel';
    public const FORMAT_VERSION = 13;

    /** Column names of the posting batch, format version 13. */
    public const COLUMNS = [
        'Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz', 'Kurs', 'Basis-Umsatz', 'WKZ Basis-Umsatz',
        'Konto', 'Gegenkonto (ohne BU-Schlüssel)', 'BU-Schlüssel', 'Belegdatum', 'Belegfeld 1', 'Belegfeld 2', 'Skonto',
        'Buchungstext', 'Postensperre', 'Diverse Adressnummer', 'Geschäftspartnerbank', 'Sachverhalt', 'Zinssperre', 'Beleglink',
        'Beleginfo - Art 1', 'Beleginfo - Inhalt 1', 'Beleginfo - Art 2', 'Beleginfo - Inhalt 2', 'Beleginfo - Art 3', 'Beleginfo - Inhalt 3',
        'Beleginfo - Art 4', 'Beleginfo - Inhalt 4', 'Beleginfo - Art 5', 'Beleginfo - Inhalt 5', 'Beleginfo - Art 6', 'Beleginfo - Inhalt 6',
        'Beleginfo - Art 7', 'Beleginfo - Inhalt 7', 'Beleginfo - Art 8', 'Beleginfo - Inhalt 8',
        'KOST1 - Kostenstelle', 'KOST2 - Kostenstelle', 'Kost-Menge', 'EU-Land u. UStID (Bestimmung)', 'EU-Steuersatz (Bestimmung)',
        'Abw. Versteuerungsart', 'Sachverhalt L+L', 'Funktionsergänzung L+L', 'BU 49 Hauptfunktionstyp', 'BU 49 Hauptfunktionsnummer',
        'BU 49 Funktionsergänzung',
        'Zusatzinformation - Art 1', 'Zusatzinformation- Inhalt 1', 'Zusatzinformation - Art 2', 'Zusatzinformation- Inhalt 2',
        'Zusatzinformation - Art 3', 'Zusatzinformation- Inhalt 3', 'Zusatzinformation - Art 4', 'Zusatzinformation- Inhalt 4',
        'Zusatzinformation - Art 5', 'Zusatzinformation- Inhalt 5', 'Zusatzinformation - Art 6', 'Zusatzinformation- Inhalt 6',
        'Zusatzinformation - Art 7', 'Zusatzinformation- Inhalt 7', 'Zusatzinformation - Art 8', 'Zusatzinformation- Inhalt 8',
        'Zusatzinformation - Art 9', 'Zusatzinformation- Inhalt 9', 'Zusatzinformation - Art 10', 'Zusatzinformation- Inhalt 10',
        'Zusatzinformation - Art 11', 'Zusatzinformation- Inhalt 11', 'Zusatzinformation - Art 12', 'Zusatzinformation- Inhalt 12',
        'Zusatzinformation - Art 13', 'Zusatzinformation- Inhalt 13', 'Zusatzinformation - Art 14', 'Zusatzinformation- Inhalt 14',
        'Zusatzinformation - Art 15', 'Zusatzinformation- Inhalt 15', 'Zusatzinformation - Art 16', 'Zusatzinformation- Inhalt 16',
        'Zusatzinformation - Art 17', 'Zusatzinformation- Inhalt 17', 'Zusatzinformation - Art 18', 'Zusatzinformation- Inhalt 18',
        'Zusatzinformation - Art 19', 'Zusatzinformation- Inhalt 19', 'Zusatzinformation - Art 20', 'Zusatzinformation- Inhalt 20',
        'Stück', 'Gewicht', 'Zahlweise', 'Forderungsart', 'Veranlagungsjahr', 'Zugeordnete Fälligkeit', 'Skontotyp', 'Auftragsnummer',
        'Buchungstyp', 'USt-Schlüssel (Anzahlungen)', 'EU-Mitgliedstaat (Anzahlungen)', 'Sachverhalt L+L (Anzahlungen)',
        'EU-Steuersatz (Anzahlungen)', 'Erlöskonto (Anzahlungen)', 'Herkunft-Kz', 'Buchungs GUID', 'KOST-Datum', 'SEPA-Mandatsreferenz',
        'Skontosperre', 'Gesellschaftername', 'Beteiligtennummer', 'Identifikationsnummer', 'Zeichnernummer', 'Postensperre bis',
        'Bezeichnung SoBil-Sachverhalt', 'Kennzeichen SoBil-Buchung', 'Festschreibung', 'Leistungsdatum', 'Datum Zuord. Steuerperiode',
        'Fälligkeit', 'Generalumkehr (GU)', 'Steuersatz', 'Land', 'Abrechnungsreferenz', 'BVV-Position',
        'EU-Mitgliedstaat u. UStID (Ursprung)', 'EU-Steuersatz (Ursprung)', 'Abw. Skontokonto',
    ];

    /**
     * @param list<Booking> $bookings
     * @param array{consultant: string, client: string, fiscal_year_start: DateTimeImmutable, account_length: int,
     *              from: DateTimeImmutable, to: DateTimeImmutable, description: string, exported_by: string,
     *              currency: string, chart: string, locked: bool, created: DateTimeImmutable} $meta
     */
    public function write(array $bookings, array $meta): string
    {
        if (preg_match('/^\d{4,7}$/', $meta['consultant']) !== 1 || (int) $meta['consultant'] < 1001) {
            throw new InvalidArgumentException('Enter the DATEV consultant number (1001–9999999) in the finance settings.');
        }
        if (preg_match('/^\d{1,5}$/', $meta['client']) !== 1 || (int) $meta['client'] < 1) {
            throw new InvalidArgumentException('Enter the DATEV client number (1–99999) in the finance settings.');
        }

        $header = [
            self::text('EXTF'), (string) self::VERSION, (string) self::CATEGORY, self::text(self::FORMAT_NAME), (string) self::FORMAT_VERSION,
            $meta['created']->format('YmdHis') . substr($meta['created']->format('u'), 0, 3),
            '', self::text('NX'), self::text(self::clean($meta['exported_by'], 25)), self::text(''),
            $meta['consultant'], $meta['client'], $meta['fiscal_year_start']->format('Ymd'), (string) $meta['account_length'],
            $meta['from']->format('Ymd'), $meta['to']->format('Ymd'), self::text(self::clean($meta['description'], 30)), self::text(''),
            '1', '0', $meta['locked'] ? '1' : '0', self::text($meta['currency']), '', self::text(''), '', '',
            self::text($meta['chart'] === 'skr04' ? '04' : '03'), '', '', self::text(''), self::text(''),
        ];

        $lines = [implode(';', $header), implode(';', self::COLUMNS)];
        foreach ($bookings as $booking) {
            $lines[] = implode(';', $this->row($booking, $meta['locked']));
        }

        return self::encode(implode("\r\n", $lines) . "\r\n");
    }

    /**
     * @return list<string> the 125 fields of a booking line
     */
    private function row(Booking $booking, bool $locked): array
    {
        if ($booking->amount <= 0) {
            throw new InvalidArgumentException('DATEV bookings need a positive amount.');
        }
        $fields = array_fill(0, count(self::COLUMNS), '');
        $fields[0] = self::decimal($booking->amount);
        $fields[1] = self::text($booking->side);
        $fields[2] = self::text($booking->currency);
        $fields[6] = $booking->account;
        $fields[7] = $booking->contraAccount;
        $fields[8] = self::text($booking->buKey);
        $fields[9] = $booking->date->format('dm');
        $fields[10] = self::text(self::document($booking->document));
        $fields[13] = self::text(self::clean($booking->text, 60));
        $fields[39] = self::text(self::clean($booking->euVatIdOrCountry, 15));
        $fields[40] = $booking->euRateBp !== null ? self::decimal($booking->euRateBp) : '';
        $fields[113] = $locked ? '1' : '0';

        return $fields;
    }

    /** Amount in minor units as DATEV decimal ("119,00"). */
    public static function decimal(int $minor): string
    {
        return sprintf('%s%d,%02d', $minor < 0 ? '-' : '', intdiv(abs($minor), 100), abs($minor) % 100);
    }

    /** Text field: quoted, quotes are not allowed inside (replaced). */
    public static function text(string $value): string
    {
        return '"' . str_replace('"', "'", $value) . '"';
    }

    /** Removes line breaks and control characters, shortens to the field length. */
    public static function clean(string $value, int $length): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        return mb_substr((string) preg_replace('/\s{2,}/u', ' ', $value), 0, $length);
    }

    /** Belegfeld 1 allows letters, digits and $ & % * + - / (max. 36). */
    public static function document(string $value): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9$&%*+\-\/]/', '', $value), 0, 36);
    }

    /** UTF-8 → Windows-1252 (characters without an equivalent become "?"). */
    public static function encode(string $utf8): string
    {
        $converted = mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');

        return is_string($converted) ? $converted : throw new InvalidArgumentException('The export could not be converted to Windows-1252.');
    }
}
