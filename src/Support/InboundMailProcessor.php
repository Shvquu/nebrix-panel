<?php

declare(strict_types=1);

namespace Nebrix\Support;

use Nebrix\Auth\User;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Security\RateLimiter;
use Nebrix\Settings\SettingsRepository;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * Turns e-mail replies to ticket notifications into ticket replies.
 *
 * The mail provider (any provider that can forward inbound mail as JSON)
 * posts {"message_id", "from", "to", "subject", "text", "headers"} to
 * /webhooks/inbound-mail with
 *   X-Nebrix-Timestamp: <unix time>
 *   X-Nebrix-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>" with the shared secret>
 *
 * Checks, in this order: signature and timestamp (±5 minutes), JSON shape,
 * message ID (each mail is processed once), auto-replies and bounces, the
 * signed reply address, the sender (must be the ticket owner), rate limits
 * per ticket and sender, then quotes and signatures are removed.
 */
class InboundMailProcessor
{
    public const MAX_BODY = 262_144;
    public const TOLERANCE = 300;
    private const MAX_TEXT = 20_000;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ReplyAddress $replyAddress,
        private readonly TicketRepository $tickets,
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enabled(): bool
    {
        return $this->replyAddress->enabled();
    }

    /** Verifies the HMAC signature over "<timestamp>.<body>" and the age of the timestamp. */
    public function verifySignature(string $body, ?string $timestamp, ?string $signature): bool
    {
        $secret = $this->settings->string('support.inbound_secret');
        if ($secret === '' || $timestamp === null || $signature === null || preg_match('/^\d{9,11}$/', $timestamp) !== 1) {
            return false;
        }
        if (abs($this->clock->now()->getTimestamp() - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }
        $signature = strtolower(trim($signature));
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        return preg_match('/^[a-f0-9]{64}$/', $signature) === 1 && hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature);
    }

    /**
     * Processes a verified payload.
     *
     * @param array<string, mixed> $mail decoded JSON
     * @return array{status: string, reason?: string, ticket_id?: int}
     *
     * @throws \InvalidArgumentException for malformed payloads
     * @throws TooManyInboundMails when a rate limit is exceeded (the provider should retry later)
     */
    public function process(array $mail): array
    {
        foreach (['message_id', 'from', 'text'] as $field) {
            if (!is_string($mail[$field] ?? null) || trim($mail[$field]) === '') {
                throw new \InvalidArgumentException('Missing field: ' . $field);
            }
        }
        $messageId = trim((string) $mail['message_id']);
        if (strlen($messageId) > 998) {
            throw new \InvalidArgumentException('Invalid message_id.');
        }
        $to = is_array($mail['to'] ?? null) ? $mail['to'] : [is_string($mail['to'] ?? null) ? $mail['to'] : ''];
        $subject = is_string($mail['subject'] ?? null) ? $mail['subject'] : '';
        $headers = is_array($mail['headers'] ?? null) ? $mail['headers'] : [];
        $hash = hash('sha256', mb_strtolower($messageId));

        if ($this->db->fetchValue('SELECT `id` FROM `nx_ticket_inbound_mails` WHERE `message_hash` = ?', [$hash]) !== null) {
            return ['status' => 'duplicate'];
        }

        $from = self::address((string) $mail['from']);
        if (self::isAutomatic($headers, $from, $subject)) {
            return $this->ignore($hash, null, 'auto_reply');
        }

        $ticketId = null;
        foreach (array_slice($to, 0, 20) as $recipient) {
            if (is_string($recipient)) {
                foreach (self::addresses($recipient) as $address) {
                    $ticketId ??= $this->replyAddress->parse($address);
                }
            }
        }
        $ticket = $ticketId !== null ? $this->tickets->find($ticketId) : null;
        if ($ticket === null) {
            return $this->ignore($hash, null, 'invalid_address');
        }
        if ($from === '' || mb_strtolower((string) $ticket['email']) !== $from) {
            $this->logger->notice('Inbound ticket e-mail from a sender other than the ticket owner ignored', ['ticket' => $ticket['id']]);

            return $this->ignore($hash, (int) $ticket['id'], 'sender_mismatch');
        }
        $owner = $this->db->fetchValue('SELECT `status` FROM `nx_users` WHERE `id` = ?', [(int) $ticket['user_id']]);
        if ($owner !== User::STATUS_ACTIVE || !$this->settings->bool('system.tickets_enabled')) {
            return $this->ignore($hash, (int) $ticket['id'], 'not_accepted');
        }

        if (!$this->limiter->attempt('inbound-mail-ticket:' . $ticket['id'], 20, 3600) || !$this->limiter->attempt('inbound-mail-sender:' . $from, 30, 3600)) {
            throw new TooManyInboundMails();
        }

        $text = QuoteStripper::strip((string) $mail['text']);
        if ($text === '') {
            return $this->ignore($hash, (int) $ticket['id'], 'empty');
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            $text = mb_substr($text, 0, self::MAX_TEXT - 2) . ' …';
        }

        try {
            return $this->db->transaction(function () use ($hash, $ticket, $text): array {
                $inboundId = $this->db->insert('nx_ticket_inbound_mails', ['message_hash' => $hash, 'ticket_id' => (int) $ticket['id'], 'status' => 'accepted', 'created_at' => $this->clock->nowString()]);
                $messageId = $this->tickets->addMessage((int) $ticket['id'], (int) $ticket['user_id'], false, $text, 'email');
                $this->db->update('nx_ticket_inbound_mails', ['ticket_message_id' => $messageId], ['id' => $inboundId]);

                return ['status' => 'accepted', 'ticket_id' => (int) $ticket['id']];
            });
        } catch (PDOException $exception) {
            // The same message arrived twice at the same time.
            if (($exception->errorInfo[1] ?? null) === 1062) {
                return ['status' => 'duplicate'];
            }

            throw $exception;
        }
    }

    /**
     * @return array{status: string, reason: string}
     */
    private function ignore(string $hash, ?int $ticketId, string $reason): array
    {
        try {
            $this->db->insert('nx_ticket_inbound_mails', ['message_hash' => $hash, 'ticket_id' => $ticketId, 'status' => $reason, 'created_at' => $this->clock->nowString()]);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }
        }

        return ['status' => 'ignored', 'reason' => $reason];
    }

    /**
     * Auto-replies, out-of-office notes and bounces never become ticket replies (and never loop).
     *
     * @param array<mixed> $headers
     */
    public static function isAutomatic(array $headers, string $from, string $subject): bool
    {
        $values = [];
        foreach ($headers as $name => $value) {
            if (is_string($name)) {
                $values[strtolower($name)] = strtolower(trim(is_array($value) ? (string) (array_values(array_filter($value, 'is_string'))[0] ?? '') : (is_scalar($value) ? (string) $value : '')));
            }
        }
        $autoSubmitted = $values['auto-submitted'] ?? 'no';
        if ($autoSubmitted !== 'no' && $autoSubmitted !== '') {
            return true;
        }
        foreach (['x-autoreply', 'x-autorespond', 'x-autoreply-from', 'x-mail-autoreply', 'x-failed-recipients'] as $name) {
            if (isset($values[$name])) {
                return true;
            }
        }
        if (in_array($values['precedence'] ?? '', ['bulk', 'junk', 'list', 'auto_reply'], true)
            || str_contains($values['content-type'] ?? '', 'multipart/report')
            || ($values['return-path'] ?? null) === '<>') {
            return true;
        }
        if (preg_match('/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply|bounces?)([+.-].*)?@/i', $from) === 1) {
            return true;
        }

        return preg_match('/^\s*(auto(matic)?[ -]?reply|automatische antwort|out of (the )?office|abwesenheit|undeliverable|undelivered mail|unzustellbar|delivery status notification|mail delivery (failed|system)|returned mail|failure notice)/iu', $subject) === 1;
    }

    /** The bare, lower-cased address of "Name <address>" or "address". */
    public static function address(string $value): string
    {
        $value = trim($value);
        if (preg_match('/<([^<>\s]+@[^<>\s]+)>\s*$/', $value, $m) === 1) {
            $value = $m[1];
        }
        $value = mb_strtolower(trim($value));

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : '';
    }

    /**
     * @return list<string>
     */
    private static function addresses(string $list): array
    {
        $found = [];
        foreach (array_slice(explode(',', $list), 0, 20) as $part) {
            $address = self::address($part);
            if ($address !== '') {
                $found[] = $address;
            }
        }

        return $found;
    }
}
