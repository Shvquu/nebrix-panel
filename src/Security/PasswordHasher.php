<?php

declare(strict_types=1);

namespace Nebrix\Security;

/**
 * Password hashing with Argon2id (falls back to bcrypt only where PHP was
 * built without Argon2 support). Legacy bcrypt hashes from RED-Host verify
 * normally and are upgraded on the next successful login via needsRehash().
 */
final class PasswordHasher
{

    /**
     * @param array{memory_cost?: int, time_cost?: int, threads?: int} $argonOptions
     */
    public function __construct(private readonly array $argonOptions = [])
    {
    }

    public function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /**
     * @return array<string, int>
     */
    private function options(): array
    {
        if ($this->algorithm() === PASSWORD_BCRYPT) {
            return ['cost' => 12];
        }

        return [
            'memory_cost' => $this->argonOptions['memory_cost'] ?? 65536,
            'time_cost' => $this->argonOptions['time_cost'] ?? 4,
            'threads' => $this->argonOptions['threads'] ?? 1,
        ];
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, $this->algorithm(), $this->options());
    }

    public function verify(#[\SensitiveParameter] string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            // Spend the same time as for an existing account (exactly one verification with the
            // configured cost), so that response times do not reveal whether an account exists.
            password_verify($password, $this->dummyHash());

            return false;
        }

        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm(), $this->options());
    }

    /**
     * A well-formed hash with the configured algorithm and cost that matches
     * no password. It is assembled, not computed, so building it costs
     * nothing and verifying it costs one regular verification.
     */
    private function dummyHash(): string
    {
        if ($this->algorithm() === PASSWORD_BCRYPT) {
            return sprintf('$2y$%02d$', $this->options()['cost']) . str_repeat('N', 22) . str_repeat('e', 31);
        }
        $options = $this->options();

        return sprintf(
            '$argon2id$v=19$m=%d,t=%d,p=%d$%s$%s',
            $options['memory_cost'],
            $options['time_cost'],
            $options['threads'],
            rtrim(base64_encode(str_repeat("\x4e", 16)), '='),
            rtrim(base64_encode(str_repeat("\x65", 32)), '='),
        );
    }
}
