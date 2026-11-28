<?php

declare(strict_types=1);

namespace Nebrix\Marketing;

/**
 * Shortened IP addresses for proofs of consent: enough to show where a
 * consent came from (network), without storing the full address (data
 * minimisation, Art. 5 (1) c GDPR).
 */
final class IpAddress
{
    /** IPv4: the last octet is zeroed (/24); IPv6: everything after the first 48 bits (/48). */
    public static function truncate(string $ip): string
    {
        $binary = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        if ($binary === false) {
            return '';
        }
        if (strlen($binary) === 4) {
            return (string) inet_ntop(substr($binary, 0, 3) . "\0");
        }

        return (string) inet_ntop(substr($binary, 0, 6) . str_repeat("\0", 10));
    }
}
