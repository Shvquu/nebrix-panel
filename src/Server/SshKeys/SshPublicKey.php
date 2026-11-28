<?php

declare(strict_types=1);

namespace Nebrix\Server\SshKeys;

use InvalidArgumentException;

/**
 * A validated OpenSSH public key ("type base64 [comment]").
 *
 * The key is parsed in the SSH wire format (RFC 4253 / 5656 / 8709), not
 * matched with a pattern: the type inside the blob must equal the declared
 * type, the key material must have the right size and nothing may follow it.
 * Accepted: ssh-ed25519, ecdsa-sha2-nistp256/384/521 (uncompressed points)
 * and ssh-rsa with at least 2048 bits. Options (authorized_keys prefixes such
 * as command="…"), several keys, private keys and other key types are refused.
 */
final class SshPublicKey
{
    public const TYPES = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-rsa'];

    private const MIN_RSA_BITS = 2048;
    private const MAX_RSA_BITS = 16384;
    private const MAX_LENGTH = 16384;

    private function __construct(
        public readonly string $type,
        public readonly int $bits,
        public readonly string $blob,
        public readonly string $comment,
    ) {
    }

    /**
     * @throws InvalidArgumentException with a user-facing message
     */
    public static function parse(string $input): self
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Paste one public key in OpenSSH format (e.g. the content of id_ed25519.pub).');
        }
        if (str_contains($input, 'PRIVATE KEY')) {
            throw new InvalidArgumentException('This is a private key. Never share it – paste the public key (the .pub file) instead.');
        }
        if (preg_match('/[\r\n]/', $input) === 1) {
            throw new InvalidArgumentException('Paste exactly one public key (one line).');
        }
        $parts = preg_split('/[ \t]+/', $input, 3) ?: [];
        $type = $parts[0];
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported key type. Allowed: ssh-ed25519, ecdsa-sha2-nistp256/384/521 and ssh-rsa (at least 2048 bits). Key options are not allowed.');
        }
        $encoded = $parts[1] ?? '';
        $blob = preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $encoded) === 1 ? base64_decode($encoded, true) : false;
        if ($blob === false || $blob === '') {
            throw new InvalidArgumentException('The key is not valid (base64 data expected after the key type).');
        }

        $reader = new SshWireReader($blob);
        try {
            if ($reader->string() !== $type) {
                throw new InvalidArgumentException('The key data does not match the key type.');
            }
            $bits = match ($type) {
                'ssh-ed25519' => self::ed25519($reader),
                'ssh-rsa' => self::rsa($reader),
                default => self::ecdsa($reader, substr($type, strlen('ecdsa-sha2-'))),
            };
            if (!$reader->atEnd()) {
                throw new InvalidArgumentException('The key data is not valid.');
            }
        } catch (\LengthException) {
            throw new InvalidArgumentException('The key data is not valid.');
        }

        // The comment is informational only: printable ASCII, at most 100 characters.
        $comment = mb_substr(trim((string) preg_replace('/[^\x20-\x7E]+/', '', (string) ($parts[2] ?? ''))), 0, 100);

        return new self($type, $bits, $blob, $comment);
    }

    private static function ed25519(SshWireReader $reader): int
    {
        if (strlen($reader->string()) !== 32) {
            throw new InvalidArgumentException('The key data is not valid.');
        }

        return 256;
    }

    private static function ecdsa(SshWireReader $reader, string $curve): int
    {
        $sizes = ['nistp256' => [256, 65], 'nistp384' => [384, 97], 'nistp521' => [521, 133]];
        [$bits, $pointLength] = $sizes[$curve];
        if ($reader->string() !== $curve) {
            throw new InvalidArgumentException('The key data does not match the key type.');
        }
        $point = $reader->string();
        if (strlen($point) !== $pointLength || $point[0] !== "\x04") {
            throw new InvalidArgumentException('The key data is not valid.');
        }

        return $bits;
    }

    private static function rsa(SshWireReader $reader): int
    {
        $exponent = ltrim($reader->string(), "\x00");
        $modulus = ltrim($reader->string(), "\x00");
        if ($exponent === '' || strlen($exponent) > 8 || (ord($exponent[strlen($exponent) - 1]) & 1) === 0 || $modulus === '') {
            throw new InvalidArgumentException('The key data is not valid.');
        }
        $bits = (strlen($modulus) - 1) * 8 + strlen(decbin(ord($modulus[0])));
        if ($bits < self::MIN_RSA_BITS) {
            throw new InvalidArgumentException('RSA keys need at least 2048 bits. Create an Ed25519 key instead: ssh-keygen -t ed25519');
        }
        if ($bits > self::MAX_RSA_BITS) {
            throw new InvalidArgumentException('The key data is not valid.');
        }

        return $bits;
    }

    /** OpenSSH line without options: "type base64 [comment]". */
    public function line(): string
    {
        return $this->type . ' ' . base64_encode($this->blob) . ($this->comment !== '' ? ' ' . $this->comment : '');
    }

    /** SHA256 fingerprint as shown by ssh-keygen -l ("SHA256:…"). */
    public function fingerprint(): string
    {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $this->blob, true)), '=');
    }
}
