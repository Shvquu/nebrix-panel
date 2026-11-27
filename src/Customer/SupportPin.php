<?php

declare(strict_types=1);

namespace Nebrix\Customer;

use Nebrix\Auth\User;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Security\Encrypter;

/**
 * Support PIN: a random 9-digit number the customer tells the support team
 * (phone, chat) to prove who they are. It is stored encrypted, shown only
 * to the customer and only verified – never displayed – for staff. It
 * cannot be used to sign in.
 */
class SupportPin
{
    private const CONTEXT = 'support-pin';

    public function __construct(
        private readonly Connection $db,
        private readonly Encrypter $encrypter,
        private readonly Clock $clock,
    ) {
    }

    /** The customer's PIN (created on first use), formatted 123-456-789. */
    public function forUser(User $user): string
    {
        $stored = $this->db->fetchValue('SELECT `support_pin` FROM `nx_users` WHERE `id` = ?', [$user->id]);
        if (is_string($stored) && $stored !== '') {
            try {
                return self::format($this->encrypter->decrypt($stored, self::CONTEXT));
            } catch (\RuntimeException) {
                // Not decryptable (key rotated without the old key): issue a new PIN.
            }
        }

        return $this->regenerate($user);
    }

    public function regenerate(User $user): string
    {
        $pin = '';
        for ($i = 0; $i < 9; $i++) {
            $pin .= (string) random_int(0, 9);
        }
        $this->db->update('nx_users', ['support_pin' => $this->encrypter->encrypt($pin, self::CONTEXT), 'support_pin_created_at' => $this->clock->nowString()], ['id' => $user->id]);

        return self::format($pin);
    }

    /** Staff check a PIN the customer told them. */
    public function verify(User $user, string $input): bool
    {
        $digits = (string) preg_replace('/\D/', '', $input);
        $stored = $this->db->fetchValue('SELECT `support_pin` FROM `nx_users` WHERE `id` = ?', [$user->id]);
        if (strlen($digits) !== 9 || !is_string($stored) || $stored === '') {
            return false;
        }
        try {
            return hash_equals($this->encrypter->decrypt($stored, self::CONTEXT), $digits);
        } catch (\RuntimeException) {
            return false;
        }
    }

    private static function format(string $pin): string
    {
        return implode('-', str_split($pin, 3));
    }
}
