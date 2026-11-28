<?php

declare(strict_types=1);

namespace Nebrix\Tests\Feature\Support;

use Nebrix\Auth\User;
use Nebrix\Http\Response;
use Nebrix\Security\RateLimiter;
use Nebrix\Settings\SettingsRepository;
use Nebrix\Support\ReplyAddress;
use Nebrix\Support\TicketRepository;
use Nebrix\Tests\FeatureTestCase;

/**
 * Inbound e-mail webhook: HMAC signature with timestamp, replay protection
 * by message ID, signed reply address, sender check, auto-replies, size and
 * rate limits, quote stripping.
 */
final class InboundMailTest extends FeatureTestCase
{
    private const SECRET = 'inbound-secret-for-tests-0123456789abcdef';

    private User $owner;

    private int $ticketId;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = $this->app->container()->get(SettingsRepository::class);
        $settings->set('support.email_replies_enabled', true);
        $settings->set('support.reply_domain', 'support.example.test');
        $settings->set('support.inbound_secret', self::SECRET);
        $this->owner = $this->createUser(\Nebrix\Auth\Role::CUSTOMER, ['email' => 'ada@example.test']);
        $tickets = $this->app->container()->get(TicketRepository::class);
        $this->ticketId = $tickets->create($this->owner->id, 'Backup failed', 'The nightly backup failed.', null, 'normal');
        $tickets->addMessage($this->ticketId, $this->createUser(\Nebrix\Auth\Role::SUPPORT)->id, true, 'Can you send the error message?');
    }

    private function replyAddress(): string
    {
        return (string) $this->app->container()->get(ReplyAddress::class)->forTicket($this->ticketId);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function mail(array $overrides = []): array
    {
        return $overrides + [
            'message_id' => '<' . bin2hex(random_bytes(8)) . '@mail.example.test>',
            'from' => 'Ada Lovelace <Ada@Example.test>',
            'to' => 'Support <' . $this->replyAddress() . '>',
            'subject' => 'Re: New reply to your ticket "Backup failed"',
            'text' => "The error is: disk quota exceeded.\n\nOn Mon, 30 Nov 2026 at 10:00, Support <" . $this->replyAddress() . "> wrote:\n> Can you send the error message?\n",
            'headers' => [],
        ];
    }

    /**
     * @param array<string, mixed>|string $payload
     */
    private function deliver(array|string $payload, ?int $timestamp = null, ?string $secret = null, ?string $signedBody = null): Response
    {
        $body = is_string($payload) ? $payload : (string) json_encode($payload);
        $timestamp ??= $this->clock()->now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp . '.' . ($signedBody ?? $body), $secret ?? self::SECRET);

        return $this->postRaw('/webhooks/inbound-mail', $body, [
            'Content-Type' => 'application/json',
            'X-Nebrix-Timestamp' => (string) $timestamp,
            'X-Nebrix-Signature' => 'sha256=' . $signature,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function customerMessages(): array
    {
        return $this->db->fetchAll('SELECT `message`, `source`, `user_id` FROM `nx_ticket_messages` WHERE `ticket_id` = ? AND `is_staff` = 0 ORDER BY `id`', [$this->ticketId]);
    }

    public function testASignedReplyFromTheOwnerBecomesATicketReplyOnce(): void
    {
        $mail = $this->mail();
        $response = $this->deliver($mail);
        self::assertSame(200, $response->status());
        self::assertSame(['status' => 'accepted', 'ticket_id' => $this->ticketId], json_decode($response->body(), true));
        $messages = $this->customerMessages();
        self::assertCount(2, $messages);
        self::assertSame(['The error is: disk quota exceeded.', 'email', $this->owner->id], [$messages[1]['message'], $messages[1]['source'], (int) $messages[1]['user_id']], 'Quoted text is removed.');
        self::assertSame('customer_reply', $this->db->fetchValue('SELECT `status` FROM `nx_tickets` WHERE `id` = ?', [$this->ticketId]));
        self::assertNull($response->cookie('nebrix_session'), 'No CSRF token or session needed.');

        // The same message (provider retry, replay) is processed once.
        $again = $this->deliver($mail);
        self::assertSame(['status' => 'duplicate'], json_decode($again->body(), true));
        $this->clock()->freeze($this->clock()->now()->modify('+2 minutes'));
        self::assertSame(['status' => 'duplicate'], json_decode($this->deliver($mail)->body(), true));
        self::assertCount(2, $this->customerMessages());

        // Staff see it marked as e-mail.
        $this->actingAs($this->createUser(\Nebrix\Auth\Role::SUPPORT));
        self::assertStringContainsString('via e-mail', $this->get('/admin/tickets/' . $this->ticketId)->body());
    }

    public function testSignatureAndTimestampAreRequired(): void
    {
        $mail = $this->mail();
        $body = (string) json_encode($mail);
        self::assertSame(401, $this->postRaw('/webhooks/inbound-mail', $body, ['Content-Type' => 'application/json'])->status(), 'No signature.');
        self::assertSame(401, $this->deliver($mail, null, 'wrong-secret')->status());
        self::assertSame(401, $this->deliver($mail, $this->clock()->now()->getTimestamp() - 301)->status(), 'Too old (replay).');
        self::assertSame(401, $this->deliver($mail, $this->clock()->now()->getTimestamp() + 301)->status(), 'From the future.');
        self::assertSame(401, $this->deliver($mail, null, null, (string) json_encode($this->mail(['text' => 'other'])))->status(), 'Signed a different body.');
        $response = $this->postRaw('/webhooks/inbound-mail', $body, ['X-Nebrix-Timestamp' => 'abc', 'X-Nebrix-Signature' => str_repeat('a', 64)]);
        self::assertSame(401, $response->status());
        self::assertCount(1, $this->customerMessages());

        // Malformed but signed payloads.
        self::assertSame(422, $this->deliver('{not json')->status());
        self::assertSame(422, $this->deliver(['from' => 'ada@example.test', 'to' => $this->replyAddress(), 'text' => 'hi'])->status(), 'A message ID is required.');

        // Size limit before anything else.
        self::assertSame(413, $this->deliver($this->mail(['text' => str_repeat('a', 300000)]))->status());

        // Switched off: the endpoint does not exist.
        $this->app->container()->get(SettingsRepository::class)->set('support.email_replies_enabled', false);
        self::assertSame(404, $this->deliver($this->mail())->status());
        self::assertCount(1, $this->customerMessages());
    }

    public function testAddressSenderAutoRepliesAndLimits(): void
    {
        $ignored = fn (array $mail): ?string => json_decode($this->deliver($mail)->body(), true)['reason'] ?? null;

        // The sender must be the ticket owner.
        self::assertSame('sender_mismatch', $ignored($this->mail(['from' => 'mallory@example.test'])));
        $other = $this->createUser();
        self::assertSame('sender_mismatch', $ignored($this->mail(['from' => $other->email])));

        // The reply address must be signed for this ticket.
        $forged = (string) preg_replace('/\.[a-f0-9]{24}@/', '.' . str_repeat('0', 24) . '@', $this->replyAddress());
        self::assertSame('invalid_address', $ignored($this->mail(['to' => $forged])));
        self::assertSame('invalid_address', $ignored($this->mail(['to' => 'support@support.example.test'])));
        $foreignTicket = $this->app->container()->get(TicketRepository::class)->create($other->id, 'Other', 'Other ticket text.', null, 'normal');
        self::assertSame('invalid_address', $ignored($this->mail(['to' => str_replace('+' . $this->ticketId . '.', '+' . $foreignTicket . '.', $this->replyAddress())])));

        // Auto-replies and bounces never become replies.
        self::assertSame('auto_reply', $ignored($this->mail(['headers' => ['Auto-Submitted' => 'auto-replied']])));
        self::assertSame('auto_reply', $ignored($this->mail(['headers' => ['Precedence' => 'bulk']])));
        self::assertSame('auto_reply', $ignored($this->mail(['headers' => ['X-Autoreply' => 'yes']])));
        self::assertSame('auto_reply', $ignored($this->mail(['from' => 'MAILER-DAEMON@mail.example.test'])));
        self::assertSame('auto_reply', $ignored($this->mail(['subject' => 'Out of Office: Re: ticket'])));
        self::assertSame('auto_reply', $ignored($this->mail(['subject' => 'Automatische Antwort: Ihr Ticket'])));
        self::assertNotSame('auto_reply', $ignored($this->mail(['headers' => ['Auto-Submitted' => 'no']])), '"no" means sent by a person.');

        // Only quotes and a signature: nothing to add.
        self::assertSame('empty', $ignored($this->mail(['text' => "> quoted only\n-- \nAda"])));

        // Rate limit per ticket (the provider retries later).
        $limiter = $this->app->container()->get(RateLimiter::class);
        for ($i = 0; $i < 20; $i++) {
            $limiter->hit('inbound-mail-ticket:' . $this->ticketId, 3600);
        }
        $limited = $this->deliver($this->mail());
        self::assertSame(429, $limited->status());
        self::assertNotNull($limited->header('Retry-After'));
        self::assertCount(2, $this->customerMessages(), 'Only the "Auto-Submitted: no" reply was added.');

        // A closed account cannot reply.
        $limiter->clear('inbound-mail-ticket:' . $this->ticketId);
        $this->db->update('nx_users', ['status' => User::STATUS_SUSPENDED], ['id' => $this->owner->id]);
        self::assertSame('not_accepted', $ignored($this->mail()));
    }
}
