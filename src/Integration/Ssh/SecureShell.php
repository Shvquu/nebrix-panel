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
            $ssh = new SSH2($host, $port, self::TIMEOUT);
            $serverKey = $ssh->getServerPublicHostKey();
            if ($serverKey === false) {
                throw new IntegrationException('The server did not present an SSH host key.');
            }
            if ($hostKeys !== [] && !in_array(self::normalize((string) $serverKey), array_map(self::normalize(...), $hostKeys), true)) {
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

    /** "type base64" without comment. */
    public static function normalize(string $key): string
    {
        $parts = preg_split('/\s+/', trim($key)) ?: [];

        return ($parts[0] ?? '') . ' ' . ($parts[1] ?? '');
    }
}
