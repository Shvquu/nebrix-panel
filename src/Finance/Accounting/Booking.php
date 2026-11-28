<?php

declare(strict_types=1);

namespace Nebrix\Finance\Accounting;

use DateTimeImmutable;

/**
 * One booking line of the accounting export (DATEV "Buchungssatz").
 * "S" debits the account and credits the contra account, "H" the reverse.
 */
final class Booking
{
    /**
     * @param int $amount gross amount in minor units, always positive
     * @param 'S'|'H' $side
     * @param string $euVatIdOrCountry DATEV field 40 (customer VAT ID for reverse charge, country for OSS)
     * @param int|null $euRateBp DATEV field 41 (OSS rate of the country of destination)
     */
    public function __construct(
        public readonly int $amount,
        public readonly string $side,
        public readonly string $currency,
        public readonly string $account,
        public readonly string $contraAccount,
        public readonly string $buKey,
        public readonly DateTimeImmutable $date,
        public readonly string $document,
        public readonly string $text,
        public readonly string $euVatIdOrCountry = '',
        public readonly ?int $euRateBp = null,
        public readonly string $caseLabel = '',
    ) {
    }
}
