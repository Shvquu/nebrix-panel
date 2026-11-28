<?php

declare(strict_types=1);

namespace Nebrix\Finance\Bank;

/**
 * One booked entry of a bank statement (CAMT.053 or bank CSV).
 */
final class StatementEntry
{
    public const CREDIT = 'credit';
    public const DEBIT = 'debit';

    /**
     * @param int $amount minor units, always positive
     * @param 'credit'|'debit' $direction
     * @param string|null $reference the bank's unique reference of the entry, if it has one
     */
    public function __construct(
        public readonly string $bookingDate,
        public readonly ?string $valueDate,
        public readonly string $direction,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $counterpartyName,
        public readonly string $counterpartyIban,
        public readonly string $remittance,
        public readonly ?string $reference,
        public readonly bool $reversal = false,
    ) {
    }

    /**
     * Key for duplicate detection across imports: the bank's reference when
     * there is one, otherwise the content of the entry plus how often the
     * same content occurred before in the same file (two identical transfers
     * on one day stay two entries, the same file imported twice does not).
     */
    public function hash(string $account, int $occurrence): string
    {
        if ($this->reference !== null && $this->reference !== '') {
            return hash('sha256', implode('|', ['ref', $account, $this->reference]));
        }

        return hash('sha256', implode('|', [
            'content', $account, $this->bookingDate, $this->direction, (string) $this->amount, $this->currency,
            $this->counterpartyIban, mb_strtolower($this->remittance), $this->counterpartyName, (string) $occurrence,
        ]));
    }

    /** The content key without the occurrence (to count repetitions within a file). */
    public function contentKey(string $account): string
    {
        return $this->hash($account, 0) . ($this->reference ?? '');
    }
}
