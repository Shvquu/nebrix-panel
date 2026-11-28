<?php

declare(strict_types=1);

namespace Nebrix\Finance\Bank;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use Nebrix\Billing\Money;

/**
 * Reads booked entries from an ISO 20022 CAMT.053 bank statement
 * (camt.053.001.02 … .13).
 *
 * Parsing is safe for untrusted files: documents with a DTD (entities) are
 * refused, no network access (LIBXML_NONET), no entity substitution, the
 * size is limited by the caller. Only entries with status BOOK are read;
 * batch entries with several transaction details become one entry per
 * transaction.
 */
final class Camt053Parser
{
    private const NAMESPACE_PREFIX = 'urn:iso:std:iso:20022:tech:xsd:camt.053.';

    /**
     * @return array{account: string, statement: string, entries: list<StatementEntry>, skipped: int}
     */
    public function parse(string $xml): array
    {
        // Refused before the parser sees it: a DTD is the vehicle for entity expansion and external entities.
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw new InvalidArgumentException('The file contains a document type declaration and is not accepted.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }
        if ($loaded !== true || $dom->documentElement === null) {
            throw new InvalidArgumentException('The file is not valid XML.');
        }
        if ($dom->doctype !== null) {
            throw new InvalidArgumentException('The file contains a document type declaration and is not accepted.');
        }
        $namespace = (string) $dom->documentElement->namespaceURI;
        if ($dom->documentElement->localName !== 'Document' || !str_starts_with($namespace, self::NAMESPACE_PREFIX)) {
            throw new InvalidArgumentException('The file is not a CAMT.053 bank statement.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('c', $namespace);
        $entries = [];
        $skipped = 0;
        $account = '';
        $statementIds = [];
        foreach ($this->nodes($xpath, 'c:BkToCstmrStmt/c:Stmt', $dom->documentElement) as $statement) {
            $account = $account !== '' ? $account : self::iban($this->text($xpath, 'c:Acct/c:Id/c:IBAN', $statement));
            $statementIds[] = $this->text($xpath, 'c:Id', $statement);
            foreach ($this->nodes($xpath, 'c:Ntry', $statement) as $entry) {
                $status = strtoupper(trim($this->text($xpath, 'c:Sts', $entry)));
                if ($status !== 'BOOK') {
                    $skipped++; // pending or information only
                    continue;
                }
                foreach ($this->entry($xpath, $entry) as $parsed) {
                    $entries[] = $parsed;
                }
            }
        }

        return ['account' => $account, 'statement' => mb_substr(implode(',', array_filter($statementIds)), 0, 100), 'entries' => $entries, 'skipped' => $skipped];
    }

    /**
     * @return list<StatementEntry>
     */
    private function entry(DOMXPath $xpath, DOMElement $entry): array
    {
        $currency = strtoupper($this->attribute($xpath, 'c:Amt', 'Ccy', $entry));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('An entry has no valid currency.');
        }
        $entryAmount = self::amount($this->text($xpath, 'c:Amt', $entry), $currency);
        $direction = match (strtoupper($this->text($xpath, 'c:CdtDbtInd', $entry))) {
            'CRDT' => StatementEntry::CREDIT,
            'DBIT' => StatementEntry::DEBIT,
            default => throw new InvalidArgumentException('An entry has no credit/debit indicator.'),
        };
        $reversal = strtolower($this->text($xpath, 'c:RvslInd', $entry)) === 'true';
        $bookingDate = self::date($this->text($xpath, 'c:BookgDt/c:Dt', $entry) ?: $this->text($xpath, 'c:BookgDt/c:DtTm', $entry));
        $valueDate = $this->text($xpath, 'c:ValDt/c:Dt', $entry) ?: $this->text($xpath, 'c:ValDt/c:DtTm', $entry);
        $reference = $this->text($xpath, 'c:AcctSvcrRef', $entry);
        $additional = $this->text($xpath, 'c:AddtlNtryInf', $entry);

        $details = $this->nodes($xpath, 'c:NtryDtls/c:TxDtls', $entry);
        if ($details === []) {
            return [new StatementEntry($bookingDate, $valueDate !== '' ? self::date($valueDate) : null, $direction, $entryAmount, $currency, '', '', self::clean($additional, 1000), $reference !== '' ? $reference : null, $reversal)];
        }

        $result = [];
        $party = $direction === StatementEntry::CREDIT ? 'Dbtr' : 'Cdtr';
        foreach ($details as $index => $detail) {
            // camt.053.001.02: AmtDtls/TxAmt/Amt, later versions: Amt; a single transaction may carry neither.
            $amountText = $this->text($xpath, 'c:Amt', $detail) ?: $this->text($xpath, 'c:AmtDtls/c:TxAmt/c:Amt', $detail);
            $amount = $amountText !== '' && count($details) > 1 ? self::amount($amountText, $currency) : $entryAmount;
            $remittance = array_filter([
                implode(' ', array_map(fn (DOMElement $node): string => trim($node->textContent), $this->nodes($xpath, 'c:RmtInf/c:Ustrd', $detail))),
                implode(' ', array_map(fn (DOMElement $node): string => trim($node->textContent), $this->nodes($xpath, 'c:RmtInf/c:Strd/c:CdtrRefInf/c:Ref', $detail))),
                $this->text($xpath, 'c:Refs/c:EndToEndId', $detail) !== 'NOTPROVIDED' ? $this->text($xpath, 'c:Refs/c:EndToEndId', $detail) : '',
                count($details) === 1 ? $additional : '',
            ]);
            $name = $this->text($xpath, 'c:RltdPties/c:' . $party . '/c:Nm', $detail) ?: $this->text($xpath, 'c:RltdPties/c:' . $party . '/c:Pty/c:Nm', $detail);
            $txReference = $this->text($xpath, 'c:Refs/c:AcctSvcrRef', $detail);
            $entryReference = $reference !== '' ? $reference . (count($details) > 1 ? '/' . ($index + 1) : '') : ($txReference !== '' ? $txReference : null);
            $result[] = new StatementEntry(
                $bookingDate,
                $valueDate !== '' ? self::date($valueDate) : null,
                $direction,
                $amount,
                $currency,
                self::clean($name, 140),
                self::iban($this->text($xpath, 'c:RltdPties/c:' . $party . 'Acct/c:Id/c:IBAN', $detail)),
                self::clean(implode(' ', $remittance), 1000),
                $entryReference,
                $reversal,
            );
        }

        return $result;
    }

    /**
     * @return list<DOMElement>
     */
    private function nodes(DOMXPath $xpath, string $query, DOMElement $context): array
    {
        $nodes = [];
        foreach ($xpath->query($query, $context) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private function text(DOMXPath $xpath, string $query, DOMElement $context): string
    {
        $node = $this->nodes($xpath, $query, $context)[0] ?? null;

        return $node !== null ? trim($node->textContent) : '';
    }

    private function attribute(DOMXPath $xpath, string $query, string $attribute, DOMElement $context): string
    {
        $node = $this->nodes($xpath, $query, $context)[0] ?? null;

        return $node !== null ? trim($node->getAttribute($attribute)) : '';
    }

    public static function amount(string $value, string $currency): int
    {
        $value = trim($value);
        if (preg_match('/^\d{1,12}(\.\d{1,5})?$/', $value) !== 1) {
            throw new InvalidArgumentException('An entry has an invalid amount.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if (rtrim(substr($fraction, 2), '0') !== '') {
            throw new InvalidArgumentException('An entry has an invalid amount.');
        }

        return Money::parse($whole . ($fraction !== '' ? '.' . substr($fraction, 0, 2) : ''), $currency);
    }

    public static function date(string $value): string
    {
        $date = substr(trim($value), 0, 10);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('An entry has an invalid booking date.');
        }

        return $date;
    }

    public static function iban(string $value): string
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', $value));

        return preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban) === 1 ? $iban : '';
    }

    public static function clean(string $value, int $length): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value))), 0, $length);
    }
}
