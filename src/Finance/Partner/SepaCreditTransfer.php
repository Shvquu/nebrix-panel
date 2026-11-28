<?php

declare(strict_types=1);

namespace Nebrix\Finance\Partner;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * SEPA credit transfer initiation (ISO 20022 pain.001.001.09) for the
 * online banking upload: one payment information block, one transaction per
 * payout. Texts are reduced to the SEPA character set; amounts in EUR.
 */
final class SepaCreditTransfer
{
    public const NAMESPACE = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.09';

    /**
     * @param array{name: string, iban: string, bic: string} $debtor
     * @param list<array{end_to_end: string, amount: int, name: string, iban: string, bic: string, remittance: string}> $transfers amounts in cents
     */
    public function build(string $messageId, DateTimeImmutable $created, DateTimeImmutable $execution, array $debtor, array $transfers): string
    {
        if ($transfers === []) {
            throw new InvalidArgumentException('There are no payouts to export.');
        }
        if (!Iban::isValid($debtor['iban'])) {
            throw new InvalidArgumentException('Enter a valid IBAN of your account in the invoice details (settings).');
        }
        $total = array_sum(array_column($transfers, 'amount'));

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElementNS(self::NAMESPACE, 'Document');
        $doc->appendChild($root);
        $initiation = $this->add($doc, $root, 'CstmrCdtTrfInitn');

        $header = $this->add($doc, $initiation, 'GrpHdr');
        $this->add($doc, $header, 'MsgId', self::id($messageId));
        $this->add($doc, $header, 'CreDtTm', $created->format('Y-m-d\TH:i:s'));
        $this->add($doc, $header, 'NbOfTxs', (string) count($transfers));
        $this->add($doc, $header, 'CtrlSum', self::amount($total));
        $this->add($doc, $this->add($doc, $header, 'InitgPty'), 'Nm', self::text($debtor['name'], 70));

        $info = $this->add($doc, $initiation, 'PmtInf');
        $this->add($doc, $info, 'PmtInfId', self::id($messageId . '-1'));
        $this->add($doc, $info, 'PmtMtd', 'TRF');
        $this->add($doc, $info, 'BtchBookg', 'true');
        $this->add($doc, $info, 'NbOfTxs', (string) count($transfers));
        $this->add($doc, $info, 'CtrlSum', self::amount($total));
        $this->add($doc, $this->add($doc, $this->add($doc, $info, 'PmtTpInf'), 'SvcLvl'), 'Cd', 'SEPA');
        $this->add($doc, $this->add($doc, $info, 'ReqdExctnDt'), 'Dt', $execution->format('Y-m-d'));
        $this->add($doc, $this->add($doc, $info, 'Dbtr'), 'Nm', self::text($debtor['name'], 70));
        $this->add($doc, $this->add($doc, $this->add($doc, $info, 'DbtrAcct'), 'Id'), 'IBAN', Iban::normalize($debtor['iban']));
        $this->agent($doc, $this->add($doc, $info, 'DbtrAgt'), $debtor['bic']);
        $this->add($doc, $info, 'ChrgBr', 'SLEV');

        foreach ($transfers as $transfer) {
            if (!Iban::isValid($transfer['iban']) || $transfer['amount'] <= 0) {
                throw new InvalidArgumentException('A payout has no valid IBAN or amount.');
            }
            $tx = $this->add($doc, $info, 'CdtTrfTxInf');
            $this->add($doc, $this->add($doc, $tx, 'PmtId'), 'EndToEndId', self::id($transfer['end_to_end']));
            $this->add($doc, $this->add($doc, $tx, 'Amt'), 'InstdAmt', self::amount($transfer['amount']))->setAttribute('Ccy', 'EUR');
            if ($transfer['bic'] !== '') {
                $this->agent($doc, $this->add($doc, $tx, 'CdtrAgt'), $transfer['bic']);
            }
            $this->add($doc, $this->add($doc, $tx, 'Cdtr'), 'Nm', self::text($transfer['name'], 70));
            $this->add($doc, $this->add($doc, $this->add($doc, $tx, 'CdtrAcct'), 'Id'), 'IBAN', Iban::normalize($transfer['iban']));
            $this->add($doc, $this->add($doc, $tx, 'RmtInf'), 'Ustrd', self::text($transfer['remittance'], 140));
        }

        return (string) $doc->saveXML();
    }

    private function agent(DOMDocument $doc, DOMElement $parent, string $bic): void
    {
        $institution = $this->add($doc, $parent, 'FinInstnId');
        if (preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $bic) === 1) {
            $this->add($doc, $institution, 'BICFI', $bic);
        } else {
            // Without a BIC (allowed for SEPA transfers within the EEA).
            $this->add($doc, $this->add($doc, $institution, 'Othr'), 'Id', 'NOTPROVIDED');
        }
    }

    private function add(DOMDocument $doc, DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $doc->createElementNS(self::NAMESPACE, $name);
        if ($value !== null) {
            $element->appendChild($doc->createTextNode($value));
        }
        $parent->appendChild($element);

        return $element;
    }

    private static function amount(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /** SEPA character set (Latin letters, digits, / - ? : ( ) . , ' + space); umlauts are transliterated. */
    public static function text(string $value, int $length): string
    {
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss', '&' => '+']);
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
        $value = is_string($ascii) ? $ascii : $value;
        $value = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace("/[^A-Za-z0-9\\/\\-?:().,'+ ]/", '', $value)));

        return substr($value !== '' ? $value : 'NOTPROVIDED', 0, $length);
    }

    private static function id(string $value): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9\-]/', '', $value), 0, 35);
    }
}
