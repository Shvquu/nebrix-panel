<?php

declare(strict_types=1);

namespace Nebrix\Integration\TeamSpeak;

use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\Ssh\SecureShell;
use phpseclib3\Net\SSH2;

/**
 * Encrypted ServerQuery over SSH (TeamSpeak 3 server 3.3+, default port
 * 10022; TeamSpeak 6). The SSH login uses the ServerQuery credentials and
 * the server's host key must match one of the pinned keys – an unpinned
 * connection is refused. Lines and responses are the same as with raw query.
 */
final class SshTransport implements Transport
{
    private ?SSH2 $ssh = null;

    /** A PTY may echo the command line; the first line after a write is skipped if it is that echo. */
    private ?string $pendingEcho = null;

    /**
     * @param list<string> $hostKeys accepted host keys ("type base64")
     */
    public function __construct(
        private readonly string $ip,
        private readonly int $port,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
        private readonly array $hostKeys,
        private readonly int $timeout = 10,
    ) {
    }

    public function connect(): void
    {
        if ($this->hostKeys === []) {
            throw new IntegrationException('ServerQuery over SSH needs the host key of the TeamSpeak server (integration settings).');
        }
        try {
            $ssh = new SSH2(SecureShell::socketHost($this->ip), $this->port, $this->timeout);
            $serverKey = $ssh->getServerPublicHostKey();
            if ($serverKey === false) {
                throw new IntegrationException('The TeamSpeak server did not present an SSH host key.');
            }
            if (!SecureShell::hostKeyMatches((string) $serverKey, $this->hostKeys)) {
                throw new IntegrationException('The SSH host key of the TeamSpeak server does not match the stored key.');
            }
            if (!$ssh->login($this->user, $this->password)) {
                throw new IntegrationException('The TeamSpeak server rejected the ServerQuery login over SSH.');
            }
            $ssh->setTimeout($this->timeout);
            $this->ssh = $ssh;
        } catch (IntegrationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new IntegrationException('Could not connect to ServerQuery over SSH: ' . mb_substr($exception->getMessage(), 0, 200));
        }

        // Greeting ("TS3" and/or "Welcome to the TeamSpeak … ServerQuery interface …").
        for ($i = 0; $i < 5; $i++) {
            $line = $this->readLine();
            if (preg_match('/^Welcome to the TeamSpeak\b.*ServerQuery/i', $line) === 1) {
                return;
            }
        }
        $this->close();
        throw new IntegrationException('The remote service is not a TeamSpeak ServerQuery interface.');
    }

    public function writeLine(string $line): void
    {
        if ($this->ssh === null) {
            throw new IntegrationException('ServerQuery is not connected.');
        }
        try {
            $this->ssh->write($line . "\n");
        } catch (\Throwable) {
            throw new IntegrationException('Writing to ServerQuery failed.');
        }
        $this->pendingEcho = $line;
    }

    public function readLine(): string
    {
        if ($this->ssh === null) {
            throw new IntegrationException('ServerQuery is not connected.');
        }
        while (true) {
            try {
                $data = $this->ssh->read("\n");
            } catch (\Throwable) {
                throw new IntegrationException('ServerQuery connection closed.');
            }
            if ($this->ssh->isTimeout()) {
                throw new IntegrationException('ServerQuery timed out.');
            }
            if (!is_string($data) || ($data === '' && !$this->ssh->isConnected())) {
                throw new IntegrationException('ServerQuery connection closed.');
            }
            // ServerQuery terminates lines with "\n\r"; a PTY may add "\r".
            $line = trim($data, "\r\n");
            if ($line === '') {
                continue;
            }
            if ($this->pendingEcho !== null) {
                $echo = $this->pendingEcho;
                $this->pendingEcho = null;
                if ($line === $echo) {
                    continue;
                }
            }

            return $line;
        }
    }

    public function close(): void
    {
        if ($this->ssh !== null) {
            try {
                $this->ssh->write("quit\n");
                $this->ssh->disconnect();
            } catch (\Throwable) {
                // already gone
            }
            $this->ssh = null;
        }
    }
}
