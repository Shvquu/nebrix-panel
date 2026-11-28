<?php

declare(strict_types=1);

namespace Nebrix\Integration\Hetzner;

use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\Ssh\SecureShell;

/**
 * Runs commands in the Hetzner rescue system over SSH (key authentication,
 * the panel's own key). When Robot reported the rescue system's host keys,
 * the server's key must be one of them.
 */
class RescueShell extends SecureShell
{
    /** SSH port of the rescue system (overridable for tests). */
    protected int $port = 22;

    /**
     * @param list<string> $hostKeys OpenSSH public keys ("ssh-ed25519 AAAA…"); empty = not pinned
     *
     * @throws IntegrationException when the server cannot be reached or rejects the key
     */
    public function run(string $ip, #[\SensitiveParameter] string $privateKey, array $hostKeys, string $command): string
    {
        return $this->execute($ip, $this->port, 'root', $privateKey, $hostKeys, $command)['output'];
    }
}
