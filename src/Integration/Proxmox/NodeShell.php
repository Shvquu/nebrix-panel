<?php

declare(strict_types=1);

namespace Nebrix\Integration\Proxmox;

use Nebrix\Integration\Integration;
use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\IntegrationRepository;
use Nebrix\Integration\Ssh\SecureShell;
use Nebrix\Provisioning\ServiceStateException;
use Nebrix\Security\UnsafeUrlException;
use Nebrix\Security\UrlGuard;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * SSH access to Proxmox nodes for what the API cannot do: setting the root
 * password of a running LXC container. The panel's key may only run the
 * forced command resources/proxmox/nebrix-lxc-passwd on the node, which checks
 * the container's ownership marker itself; host keys are pinned.
 */
class NodeShell
{
    public const COMMAND_PATH = '/usr/local/sbin/nebrix-lxc-passwd';

    public function __construct(
        private readonly SecureShell $ssh,
        private readonly IntegrationRepository $integrations,
        private readonly UrlGuard $guard,
    ) {
    }

    /** True when SSH access is configured for the node. */
    public function available(Integration $integration, string $node): bool
    {
        try {
            return isset(self::parseNodes((string) $integration->config('lxc_ssh_nodes', ''))[$node])
                && $integration->hasSecret('lxc_ssh_key') && $integration->secret('lxc_ssh_key') !== '';
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Creates the panel's key when node access is configured and no key exists.
     */
    public function ensureKey(Integration $integration): void
    {
        if (trim((string) $integration->config('lxc_ssh_nodes', '')) === '') {
            return;
        }
        if ($integration->hasSecret('lxc_ssh_key') && $integration->secret('lxc_ssh_key') !== '') {
            return;
        }
        $key = SecureShell::generateKey('nebrix-lxc');
        $this->integrations->storeGenerated($integration->id, ['lxc_ssh_key' => $key['private']], []);
    }

    /** The line for /root/.ssh/authorized_keys on every configured node, or null without a key. */
    public function authorizedKeysLine(Integration $integration): ?string
    {
        if (!$integration->hasSecret('lxc_ssh_key') || $integration->secret('lxc_ssh_key') === '') {
            return null;
        }
        try {
            $key = PublicKeyLoader::load($integration->secret('lxc_ssh_key'));
        } catch (\Throwable) {
            return null;
        }
        if (!$key instanceof PrivateKey) {
            return null;
        }

        return sprintf('restrict,command="%s" %s', self::COMMAND_PATH, $key->getPublicKey()->toString('OpenSSH', ['comment' => 'nebrix-lxc']));
    }

    /**
     * @throws ServiceStateException when the container is not running
     * @throws IntegrationException  on SSH errors or a refusal by the node
     */
    public function setLxcRootPassword(Integration $integration, string $node, int $vmid, int $serviceId, #[\SensitiveParameter] string $password): void
    {
        $nodes = self::parseNodes((string) $integration->config('lxc_ssh_nodes', ''));
        if (!isset($nodes[$node])) {
            throw new IntegrationException(sprintf('No SSH access is configured for the node "%s".', $node));
        }
        $hostKeys = self::parseHostKeys((string) $integration->config('lxc_ssh_host_keys', ''));
        if ($hostKeys === []) {
            throw new IntegrationException('No SSH host keys are stored for the Proxmox nodes.');
        }
        if (preg_match('/^[A-Za-z0-9]{12,128}$/', $password) !== 1) {
            throw new IntegrationException('The generated password has an unexpected format.');
        }
        try {
            $ip = $this->guard->validateHost($nodes[$node]['host']);
        } catch (UnsafeUrlException $exception) {
            throw new IntegrationException('The SSH address of the node is not allowed: ' . $exception->getMessage());
        }
        $user = (string) $integration->config('lxc_ssh_user', 'root');
        $result = $this->ssh->execute($ip, $nodes[$node]['port'], $user !== '' ? $user : 'root', $integration->secret('lxc_ssh_key'), $hostKeys, sprintf('passwd %d %d', $vmid, $serviceId), $password . "\n");

        match ($result['exit']) {
            0 => null,
            5 => throw new ServiceStateException('The server is not running. Start it and try again.'),
            3 => throw new IntegrationException('The container was not found on its node or does not carry the marker of this service.'),
            6 => throw new IntegrationException('The password could not be changed inside the container (chpasswd failed).'),
            default => throw new IntegrationException(sprintf('The Proxmox node refused the password change (exit status %s). Check the forced command on the node.', $result['exit'] ?? 'unknown')),
        };
    }

    /**
     * "node=address" or "node=address:port" per line ("[v6]:port" for IPv6 with a port).
     *
     * @return array<string, array{host: string, port: int}>
     *
     * @throws \InvalidArgumentException
     */
    public static function parseNodes(string $text): array
    {
        $nodes = [];
        foreach (preg_split('/\R/', $text) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^([A-Za-z0-9][A-Za-z0-9.-]{0,62})\s*=\s*(\S+)$/', $line, $match) !== 1) {
                throw new \InvalidArgumentException(sprintf('Line %d: use "node=address" or "node=address:port".', $number + 1));
            }
            $address = $match[2];
            $port = 22;
            if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::(\d{1,5}))?$/', $address, $bracket) === 1) {
                $host = $bracket[1];
                $port = isset($bracket[2]) ? (int) $bracket[2] : 22;
            } elseif (substr_count($address, ':') > 1) {
                $host = $address; // bare IPv6 address, no port
            } else {
                [$host, $portText] = array_pad(explode(':', $address, 2), 2, null);
                if ($portText !== null) {
                    if (preg_match('/^\d{1,5}$/', $portText) !== 1) {
                        throw new \InvalidArgumentException(sprintf('Line %d: invalid port.', $number + 1));
                    }
                    $port = (int) $portText;
                }
            }
            $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
                || preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $host) === 1;
            if (!$validHost || $port < 1 || $port > 65535) {
                throw new \InvalidArgumentException(sprintf('Line %d: invalid address or port.', $number + 1));
            }
            $nodes[$match[1]] = ['host' => $host, 'port' => $port];
        }

        return $nodes;
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
        return SecureShell::parseHostKeys($text);
    }
}
