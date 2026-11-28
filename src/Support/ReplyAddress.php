<?php

declare(strict_types=1);

namespace Nebrix\Support;

use Nebrix\Settings\SettingsRepository;

/**
 * Personal reply addresses for ticket notifications:
 * <local part>+<ticket ID>.<signature>@<domain>.
 *
 * The signature is an HMAC of the ticket ID with a key derived from
 * APP_KEY (96 bits, lower-case hex), so nobody can construct the address
 * of another ticket. The sender of an inbound reply must additionally be
 * the ticket owner.
 */
class ReplyAddress
{
    private const PURPOSE = 'ticket-reply';
    private const SIGNATURE_LENGTH = 24;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Signer $signer,
    ) {
    }

    /** E-mail replies are on and the address and webhook secret are configured. */
    public function enabled(): bool
    {
        return $this->settings->bool('support.email_replies_enabled')
            && $this->domain() !== ''
            && $this->settings->string('support.inbound_secret') !== '';
    }

    public function forTicket(int $ticketId): ?string
    {
        if (!$this->enabled()) {
            return null;
        }

        return sprintf('%s+%d.%s@%s', $this->localPart(), $ticketId, $this->signer->sign(self::PURPOSE, (string) $ticketId, self::SIGNATURE_LENGTH), $this->domain());
    }

    /**
     * The ticket ID of a valid reply address, null for anything else.
     */
    public function parse(string $address): ?int
    {
        $address = mb_strtolower(trim($address));
        if (preg_match('/^([a-z0-9._-]{1,30})\+(\d{1,18})\.([a-f0-9]{' . self::SIGNATURE_LENGTH . '})@([a-z0-9.-]{1,150})$/', $address, $m) !== 1) {
            return null;
        }
        if ($m[1] !== $this->localPart() || $m[4] !== $this->domain()) {
            return null;
        }

        return $this->signer->verify(self::PURPOSE, $m[2], $m[3], self::SIGNATURE_LENGTH) ? (int) $m[2] : null;
    }

    private function localPart(): string
    {
        return mb_strtolower($this->settings->string('support.reply_local_part') ?: 'support');
    }

    private function domain(): string
    {
        return mb_strtolower(trim($this->settings->string('support.reply_domain')));
    }
}
