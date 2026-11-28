<?php

declare(strict_types=1);

namespace Nebrix\Tests\Unit\Support;

use Nebrix\Support\InboundMailProcessor;
use Nebrix\Support\QuoteStripper;
use PHPUnit\Framework\TestCase;

final class QuoteStripperTest extends TestCase
{
    public function testQuotesAndSignaturesAreRemoved(): void
    {
        self::assertSame("Thanks, it works again.\n\nAda", QuoteStripper::strip("Thanks, it works again.\n\nAda\n\nOn Mon, 30 Nov 2026 at 10:00, Support <support+1.abc@example.test> wrote:\n> Please try again.\n"));
        self::assertSame('Danke!', QuoteStripper::strip("Danke!\n\nAm 30.11.2026 um 10:00 schrieb Support <support@example.test>:\n> Bitte erneut versuchen."));
        self::assertSame('Two line header', QuoteStripper::strip("Two line header\n\nOn Mon, 30 Nov 2026 at 10:00, A very long sender name <support@example.test>\nwrote:\n> quoted"));
        self::assertSame('Outlook reply', QuoteStripper::strip("Outlook reply\r\n\r\nFrom: Support <support@example.test>\r\nSent: Monday, 30 November 2026 10:00\r\nTo: Ada\r\nSubject: Re: ticket\r\n\r\nold text"));
        self::assertSame('Mit Grüßen', QuoteStripper::strip("Mit Grüßen\n-----Ursprüngliche Nachricht-----\nVon: Support"));
        self::assertSame('Short', QuoteStripper::strip("Short\n-- \nAda Lovelace\nCEO"));
        self::assertSame('From the phone', QuoteStripper::strip("From the phone\n\nSent from my iPhone"));
        self::assertSame("Line one\nOn the server I see errors.", QuoteStripper::strip("Line one\nOn the server I see errors."), 'Normal sentences starting with "On" stay.');
        self::assertSame('', QuoteStripper::strip("> only a quote\n> more"));
    }

    public function testAddressesAndAutomaticMails(): void
    {
        self::assertSame('ada@example.test', InboundMailProcessor::address('"Ada L." <Ada@Example.TEST>'));
        self::assertSame('ada@example.test', InboundMailProcessor::address('ada@example.test'));
        self::assertSame('', InboundMailProcessor::address('not an address'));
        self::assertTrue(InboundMailProcessor::isAutomatic(['auto-submitted' => 'auto-generated'], 'a@b.test', 'Hi'));
        self::assertTrue(InboundMailProcessor::isAutomatic(['Content-Type' => 'multipart/report; report-type=delivery-status'], 'a@b.test', 'Hi'));
        self::assertTrue(InboundMailProcessor::isAutomatic(['Return-Path' => '<>'], 'a@b.test', 'Hi'));
        self::assertTrue(InboundMailProcessor::isAutomatic([], 'noreply@b.test', 'Hi'));
        self::assertTrue(InboundMailProcessor::isAutomatic([], 'a@b.test', 'Undeliverable: Re: ticket'));
        self::assertFalse(InboundMailProcessor::isAutomatic(['Auto-Submitted' => 'no'], 'a@b.test', 'Re: ticket'));
    }
}
