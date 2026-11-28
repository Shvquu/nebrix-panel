<?php

declare(strict_types=1);

namespace Nebrix\Domain\Dns;

use Nebrix\Security\ValidationException;

/**
 * Strict validation of DNS records entered by customers.
 *
 * Supported types: A, AAAA, CNAME, MX, TXT, SRV, CAA. Names are relative to
 * the domain ("@" for the domain itself, "*" only as the leftmost label);
 * targets are host names (no IP addresses for CNAME/MX/SRV). A CNAME is not
 * allowed at the zone apex and never together with other records of the same
 * name. TTL 300–86400 seconds where the registrar supports it.
 */
final class DnsRecordValidator
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'];

    public const MIN_TTL = 300;
    public const MAX_TTL = 86400;
    public const DEFAULT_TTL = 3600;
    public const MAX_TXT = 1024;

    private const HOSTNAME = '/^(?=.{1,253}$)([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.[a-z][a-z0-9-]{0,61}[a-z0-9]$/';
    private const LABEL = '/^([a-z0-9_]|[a-z0-9_][a-z0-9_-]{0,61}[a-z0-9_])$/';

    /**
     * @param array<string, mixed> $input type, name, content, prio, ttl
     * @param list<array{id: string, name: string, type: string, content: string, ttl: int|null, prio: int|null}> $existing current records of the zone
     * @param string|null $replacing ID of the record being edited (left out of the conflict checks)
     * @return array{name: string, type: string, content: string, ttl: int|null, prio: int|null}
     *
     * @throws ValidationException
     */
    public static function validate(array $input, string $domain, array $existing, ?string $replacing, bool $ttlSupported): array
    {
        $type = strtoupper(trim(is_string($input['type'] ?? null) ? $input['type'] : ''));
        if (!in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Choose a record type.']);
        }
        $name = self::name(is_string($input['name'] ?? null) ? $input['name'] : '', $domain);
        $rawContent = is_string($input['content'] ?? null) ? trim($input['content']) : '';
        if ($rawContent === '') {
            throw ValidationException::withMessages(['content' => 'Enter the value of the record.']);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $rawContent) === 1) {
            throw ValidationException::withMessages(['content' => 'The value contains characters that are not allowed.']);
        }

        $prio = null;
        if (in_array($type, ['MX', 'SRV'], true)) {
            $prio = self::number($input['prio'] ?? null, 'prio', 'Enter a priority between 0 and 65535.');
        }
        $fqdn = $name === '@' ? $domain : $name . '.' . $domain;

        $content = match ($type) {
            'A' => filter_var($rawContent, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $rawContent : self::fail('content', 'Enter an IPv4 address, e.g. 203.0.113.10.'),
            'AAAA' => self::ipv6($rawContent),
            'CNAME' => self::target($rawContent, 'Enter the host name the alias points to, e.g. www.example.net.'),
            'MX' => self::target($rawContent, 'Enter the host name of the mail server, e.g. mail.example.com.'),
            'TXT' => self::txt($rawContent),
            'SRV' => self::srv($rawContent, $name),
            'CAA' => self::caa($rawContent),
        };
        if ($type === 'CNAME') {
            if ($name === '@') {
                throw ValidationException::withMessages(['name' => 'A CNAME record is not allowed for the domain itself (@).']);
            }
            if ($content === $fqdn) {
                throw ValidationException::withMessages(['content' => 'A CNAME record cannot point to itself.']);
            }
        }

        $ttl = null;
        if ($ttlSupported) {
            $ttl = ($input['ttl'] ?? '') === '' || ($input['ttl'] ?? null) === null
                ? self::DEFAULT_TTL
                : self::number($input['ttl'], 'ttl', 'The TTL must be between 300 and 86400 seconds.', self::MIN_TTL, self::MAX_TTL);
        }

        // Conflicts with the other records of the zone.
        foreach ($existing as $record) {
            if ($replacing !== null && $record['id'] === $replacing) {
                continue;
            }
            if ($record['name'] !== $name) {
                continue;
            }
            if ($type === 'CNAME' || $record['type'] === 'CNAME') {
                throw ValidationException::withMessages(['name' => 'A CNAME record cannot exist together with other records of the same name.']);
            }
            if ($record['type'] === $type && mb_strtolower($record['content']) === mb_strtolower($content) && $record['prio'] === $prio) {
                throw ValidationException::withMessages(['content' => 'This record already exists.']);
            }
        }

        return ['name' => $name, 'type' => $type, 'content' => $content, 'ttl' => $ttl, 'prio' => $prio];
    }

    /**
     * Relative record name: "@" for the domain itself; a fully qualified name
     * of the domain is shortened ("www.example.com." → "www").
     *
     * @throws ValidationException
     */
    public static function name(string $name, string $domain): string
    {
        $name = rtrim(mb_strtolower(trim($name)), '.');
        if ($name === '' || $name === '@' || $name === $domain) {
            return '@';
        }
        if (str_ends_with($name, '.' . $domain)) {
            $name = substr($name, 0, -strlen($domain) - 1);
        }
        $labels = explode('.', $name);
        foreach ($labels as $index => $label) {
            if ($label === '*' && $index === 0) {
                continue;
            }
            if (preg_match(self::LABEL, $label) !== 1) {
                throw ValidationException::withMessages(['name' => 'Use letters, digits, "-" and "_" (labels of at most 63 characters, "*" only at the beginning) or @ for the domain itself.']);
            }
        }
        if (strlen($name) + 1 + strlen($domain) > 253) {
            throw ValidationException::withMessages(['name' => 'The name is too long.']);
        }

        return $name;
    }

    /** @throws ValidationException */
    private static function target(string $value, string $message): string
    {
        $value = rtrim(mb_strtolower($value), '.');
        if (filter_var($value, FILTER_VALIDATE_IP) !== false || preg_match(self::HOSTNAME, $value) !== 1) {
            self::fail('content', $message);
        }

        return $value;
    }

    /** @throws ValidationException */
    private static function ipv6(string $value): string
    {
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            self::fail('content', 'Enter an IPv6 address, e.g. 2001:db8::10.');
        }

        return (string) inet_ntop((string) inet_pton($value));
    }

    /** @throws ValidationException */
    private static function txt(string $value): string
    {
        // One pair of surrounding quotes is accepted and removed.
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }
        if ($value === '' || strlen($value) > self::MAX_TXT || preg_match('/^[\x20-\x7E]+$/', $value) !== 1) {
            self::fail('content', 'TXT values may contain printable ASCII characters only (at most 1024).');
        }

        return $value;
    }

    /**
     * SRV content "weight port target"; the name must start with _service._protocol.
     *
     * @throws ValidationException
     */
    private static function srv(string $value, string $name): string
    {
        if (preg_match('/^_[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\._(tcp|udp|tls|sctp)(\..+)?$/', $name) !== 1) {
            self::fail('name', 'SRV records need a name like _sip._tcp (service and protocol).');
        }
        $parts = preg_split('/\s+/', $value) ?: [];
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || (int) $parts[0] > 65535 || (int) $parts[1] > 65535) {
            self::fail('content', 'Enter weight, port and target, e.g. "5 5060 sip.example.com".');
        }
        $target = $parts[2] === '.' ? '.' : self::target($parts[2], 'Enter weight, port and target, e.g. "5 5060 sip.example.com".');

        return sprintf('%d %d %s', (int) $parts[0], (int) $parts[1], $target);
    }

    /**
     * CAA content 'flags tag "value"', e.g. 0 issue "letsencrypt.org".
     *
     * @throws ValidationException
     */
    private static function caa(string $value): string
    {
        $message = 'Enter flags, tag and value, e.g. 0 issue "letsencrypt.org".';
        if (preg_match('/^(0|128)\s+(issue|issuewild|iodef)\s+"?([^"]*)"?$/i', $value, $match) !== 1) {
            self::fail('content', $message);
        }
        $tag = strtolower($match[2]);
        $tagValue = trim($match[3]);
        $valid = match ($tag) {
            'iodef' => preg_match('#^(mailto:[^\s@]+@[^\s@]+|https://[^\s"]+)$#', $tagValue) === 1,
            // A CA domain (optionally with parameters) or ";" (no CA allowed).
            default => $tagValue === ';' || preg_match('/^(?=.{1,253})([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}(\s*;\s*[a-z0-9-]+=[\x21-\x7E]+)*$/i', $tagValue) === 1,
        };
        if (!$valid || strlen($tagValue) > 255) {
            self::fail('content', $message);
        }

        return sprintf('%d %s "%s"', (int) $match[1], $tag, $tagValue);
    }

    /** @throws ValidationException */
    private static function number(mixed $value, string $field, string $message, int $min = 0, int $max = 65535): int
    {
        $value = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');
        if (preg_match('/^\d{1,6}$/', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
            self::fail($field, $message);
        }

        return (int) $value;
    }

    /** @throws ValidationException */
    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
