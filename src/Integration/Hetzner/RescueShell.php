<?php

declare(strict_types=1);

namespace Nebrix\Integration\Hetzner;

use Nebrix\Integration\IntegrationException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

/**
 * Runs commands in the Hetzner rescue system over SSH (key authentication,
 * the panel's own key). When Robot reported the rescue system's host keys,
 * the server's key must be one of them.
 */
class RescueShell
{
    private const TIMEOUT = 20;

    /** SSH port of the rescue system (overridable for tests). */
    protected int $port = 22;

    /**
     * @param list<string> $hostKeys OpenSSH public keys ("ssh-ed25519 AAAA…"); empty = not pinned
     *
     * @throws IntegrationException when the server cannot be reached or rejects the key
     */
    public function run(string $ip, #[\SensitiveParameter] string $privateKey, array $hostKeys, string $command): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new IntegrationException('Invalid server address.');
        }
        try {
            $ssh = new SSH2($ip, $this->port, self::TIMEOUT);
            $serverKey = $ssh->getServerPublicHostKey();
            if ($serverKey === false) {
                throw new IntegrationException('The rescue system did not present a host key.');
            }
            if ($hostKeys !== [] && !in_array(self::normalize((string) $serverKey), array_map(self::normalize(...), $hostKeys), true)) {
                throw new IntegrationException('The host key of the rescue system does not match the key reported by Hetzner.');
            }
            $key = PublicKeyLoader::load($privateKey);
            if (!$key instanceof \phpseclib3\Crypt\Common\PrivateKey) {
                throw new IntegrationException('The stored SSH key is not a private key.');
            }
            if (!$ssh->login('root', $key)) {
                throw new IntegrationException('The rescue system rejected the SSH key.');
            }
            $ssh->setTimeout(self::TIMEOUT);
            $output = $ssh->exec($command);
            $ssh->disconnect();

            return is_string($output) ? $output : '';
        } catch (IntegrationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new IntegrationException('SSH connection to the rescue system failed: ' . mb_substr($exception->getMessage(), 0, 200));
        }
    }

    /** "type base64" without comment. */
    private static function normalize(string $key): string
    {
        $parts = preg_split('/\s+/', trim($key)) ?: [];

        return ($parts[0] ?? '') . ' ' . ($parts[1] ?? '');
    }
}
