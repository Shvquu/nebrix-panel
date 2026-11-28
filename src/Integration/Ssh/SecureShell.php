<?php

declare(strict_types=1);

namespace Nebrix\Integration\Ssh;

use Nebrix\Integration\IntegrationException;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

/**
 * Runs one command over SSH with key authentication. The server's host key
 * must be one of the given keys (pinning); input is passed on stdin so that
 * secrets never appear in the remote command line.
 */
class SecureShell
{
    public const TIMEOUT = 20;

    /**
     * @param list<string> $hostKeys accepted host keys ("type base64 [comment]"); empty = not pinned
     * @return array{output: string, exit: int|null}
     *
     * @throws IntegrationException when the server cannot be reached, is not the pinned one or rejects the key
     */
    public function execute(string $host, int $port, string $user, #[\SensitiveParameter] string $privateKey, array $hostKeys, string $command, #[\SensitiveParameter] ?string $stdin = null): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new IntegrationException('Invalid server address.');
        }
        if ($port < 1 || $port > 65535) {
            throw new IntegrationException('Invalid SSH port.');
        }
        try {
            $ssh = new SSH2(self::socketHost($host), $port, self::TIMEOUT);
            $serverKey = $ssh->getServerPublicHostKey();
            if ($serverKey === false) {
                throw new IntegrationException('The server did not present an SSH host key.');
            }
            if ($hostKeys !== [] && !self::hostKeyMatches((string) $serverKey, $hostKeys)) {
                throw new IntegrationException('The SSH host key of the server does not match the stored key.');
            }
            $key = PublicKeyLoader::load($privateKey);
            if (!$key instanceof PrivateKey) {
                throw new IntegrationException('The stored SSH key is not a private key.');
            }
            if (!$ssh->login($user, $key)) {
                throw new IntegrationException('The server rejected the SSH key.');
            }
            $ssh->setTimeout(self::TIMEOUT);
            if ($stdin === null) {
                $output = $ssh->exec($command);
            } else {
                // false: return right after starting the command so that stdin can be written
                // (documented phpseclib behaviour; the docblock only lists callables).
                $ssh->exec($command, false); // @phpstan-ignore argument.type
                $ssh->write($stdin, SSH2::CHANNEL_EXEC);
                $ssh->sendEOF(SSH2::CHANNEL_EXEC);
                $output = $ssh->read('', SSH2::READ_SIMPLE, SSH2::CHANNEL_EXEC);
            }
            $timedOut = $ssh->isTimeout();
            $exit = $ssh->getExitStatus();
            $ssh->disconnect();
            if ($timedOut) {
                throw new IntegrationException('The SSH command did not finish in time.');
            }

            return ['output' => is_string($output) ? $output : '', 'exit' => is_int($exit) ? $exit : null];
        } catch (IntegrationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new IntegrationException('SSH connection failed: ' . mb_substr($exception->getMessage(), 0, 200));
        }
    }

    /**
     * Host for the socket: IPv6 literals in brackets, otherwise PHP splits
     * "host:port" at the first colon and connects somewhere else.
     */
    public static function socketHost(string $ip): string
    {
        return str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
    }

    /**
     * A new Ed25519 key pair.
     *
     * @return array{private: string, public: string} OpenSSH formats
     */
    public static function generateKey(string $comment): array
    {
        $key = EC::createKey('Ed25519');

        return [
            'private' => $key->toString('OpenSSH'),
            'public' => $key->getPublicKey()->toString('OpenSSH', ['comment' => $comment]),
        ];
    }

    /**
     * Host keys in ssh-keyscan or known_hosts format ("[host] type base64 [comment]").
     *
     * @return list<string> "type base64"
     *
     * @throws \InvalidArgumentException
     */
    public static function parseHostKeys(string $text): array
    {
        $keys = [];
        foreach (preg_split('/\R/', $text) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            $typeIndex = preg_match('/^(ssh-|ecdsa-|sk-)/', $parts[0] ?? '') === 1 ? 0 : 1;
            $type = $parts[$typeIndex] ?? '';
            $blob = $parts[$typeIndex + 1] ?? '';
            if (preg_match('/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521))$/', $type) !== 1 || preg_match('#^[A-Za-z0-9+/]{40,}={0,2}$#', $blob) !== 1) {
                throw new \InvalidArgumentException(sprintf('Line %d is not an SSH host key (use the output of ssh-keyscan).', $number + 1));
            }
            $keys[] = $type . ' ' . $blob;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Whether the server's host key ("type base64") is one of the pins: full
     * keys ("type base64 [comment]"), "MD5:aa:bb:…" or "SHA256:…" fingerprints.
     *
     * @param list<string> $pins
     */
    public static function hostKeyMatches(string $serverKey, array $pins): bool
    {
        $normalized = self::normalize($serverKey);
        $blob = base64_decode(explode(' ', $normalized)[1] ?? '', true);
        if ($blob === false || $blob === '') {
            return false;
        }
        $md5 = 'MD5:' . implode(':', str_split(md5($blob), 2));
        $sha256 = 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
        foreach ($pins as $pin) {
            $pin = trim($pin);
            if (str_starts_with($pin, 'MD5:') ? hash_equals($md5, 'MD5:' . strtolower(substr($pin, 4))) : (str_starts_with($pin, 'SHA256:') ? hash_equals($sha256, rtrim($pin, '=')) : hash_equals($normalized, self::normalize($pin)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Like parseHostKeys(), but invalid input yields no keys (connections are then refused).
     *
     * @return list<string>
     */
    public static function parseHostKeysSafe(string $text): array
    {
        try {
            return self::parseHostKeys($text);
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    /**
     * "type base64" without comment. RSA keys are reported with the signature
     * algorithm (rsa-sha2-256/512) after the key exchange but are the same
     * "ssh-rsa" key.
     */
    public static function normalize(string $key): string
    {
        $parts = preg_split('/\s+/', trim($key)) ?: [];
        $type = $parts[0] ?? '';
        if (in_array($type, ['rsa-sha2-256', 'rsa-sha2-512'], true)) {
            $type = 'ssh-rsa';
        }

        return $type . ' ' . ($parts[1] ?? '');
    }
}
