<?php

declare(strict_types=1);

namespace Nebrix\Tests\Unit\Server;

use InvalidArgumentException;
use Nebrix\Security\ValidationException;
use Nebrix\Security\Validator;
use Nebrix\Server\SshKeys\SshPublicKey;
use Nebrix\Tests\Support\SshKeyFixtures as Keys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SshPublicKeyTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int, string}>
     */
    public static function validKeys(): array
    {
        return [
            'ed25519' => [Keys::ED25519, 'ssh-ed25519', 256, Keys::ED25519_FP],
            'ecdsa p256' => [Keys::ECDSA256, 'ecdsa-sha2-nistp256', 256, Keys::ECDSA256_FP],
            'ecdsa p384' => [Keys::ECDSA384, 'ecdsa-sha2-nistp384', 384, Keys::ECDSA384_FP],
            'ecdsa p521' => [Keys::ECDSA521, 'ecdsa-sha2-nistp521', 521, Keys::ECDSA521_FP],
            'rsa 2048' => [Keys::RSA2048, 'ssh-rsa', 2048, Keys::RSA2048_FP],
        ];
    }

    #[DataProvider('validKeys')]
    public function testValidKeysAreParsedWithTheirSha256Fingerprint(string $line, string $type, int $bits, string $fingerprint): void
    {
        $key = SshPublicKey::parse("  " . $line . "\n");

        self::assertSame($type, $key->type);
        self::assertSame($bits, $key->bits);
        self::assertSame($fingerprint, $key->fingerprint(), 'Same fingerprint as ssh-keygen -l -E sha256.');
        self::assertSame($line, $key->line());
    }

    public function testCommentIsReducedToPrintableCharacters(): void
    {
        [$type, $blob] = explode(' ', Keys::ED25519);
        $key = SshPublicKey::parse($type . ' ' . $blob . " eve\x07<b>\u{00e4}" . str_repeat('x', 200));

        self::assertSame(100, strlen($key->comment));
        self::assertStringStartsWith('eve<b>', $key->comment);
        self::assertSame($type . ' ' . $blob, SshPublicKey::parse($type . ' ' . $blob)->line());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidKeys(): array
    {
        [, $edBlob] = explode(' ', Keys::ED25519);
        [, $rsaBlob] = explode(' ', Keys::RSA2048);
        $raw = (string) base64_decode($edBlob, true);

        return [
            'empty' => ['', 'OpenSSH format'],
            'rsa 1024' => [Keys::RSA1024, 'at least 2048 bits'],
            'private key' => ["-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\n-----END OPENSSH PRIVATE KEY-----", 'private key'],
            'two keys' => [Keys::ED25519 . "\n" . Keys::ED25519_B, 'exactly one'],
            'authorized_keys options' => ['command="/bin/sh" ' . Keys::ED25519, 'Unsupported key type'],
            'dss' => ['ssh-dss AAAAB3NzaC1kc3MAAACBAP', 'Unsupported key type'],
            'security key' => ['sk-ssh-ed25519@openssh.com AAAAGnNrLXNzaC1lZDI1NTE5QG9wZW5zc2guY29t', 'Unsupported key type'],
            'no base64' => ['ssh-ed25519 not*base64', 'not valid'],
            'declared type differs' => ['ssh-ed25519 ' . $rsaBlob, 'does not match'],
            'ecdsa curve differs' => ['ecdsa-sha2-nistp256 ' . explode(' ', Keys::ECDSA384)[1], 'does not match'],
            'truncated' => ['ssh-ed25519 ' . base64_encode(substr($raw, 0, 30)), 'not valid'],
            'trailing data' => ['ssh-ed25519 ' . base64_encode($raw . "\x00\x00\x00\x01x"), 'not valid'],
            'short ed25519 key' => ['ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 16) . str_repeat('k', 16)), 'not valid'],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeysAreRefused(string $input, string $message): void
    {
        try {
            SshPublicKey::parse($input);
            self::fail('Key accepted: ' . $input);
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function testIdsRuleNormalizesPositiveIntegers(): void
    {
        self::assertSame(['keys' => [3, 12]], Validator::validate(['keys' => ['3', '12', '3']], ['keys' => 'nullable|array|max:10|ids']));
        self::assertSame(['keys' => null], Validator::validate(['keys' => []], ['keys' => 'nullable|array|max:10|ids']));

        foreach ([['0'], ['1', 'x'], ['-4'], [['nested']], ['1e3']] as $invalid) {
            try {
                Validator::validate(['keys' => $invalid], ['keys' => 'nullable|array|max:10|ids']);
                self::fail('Accepted ' . json_encode($invalid));
            } catch (ValidationException) {
            }
        }
        $this->expectException(ValidationException::class);
        Validator::validate(['keys' => array_map('strval', range(1, 11))], ['keys' => 'nullable|array|max:10|ids']);
    }
}
