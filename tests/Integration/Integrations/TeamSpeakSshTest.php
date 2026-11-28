<?php

declare(strict_types=1);

namespace Nebrix\Tests\Integration\Integrations;

use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\IntegrationLogger;
use Nebrix\Integration\IntegrationManager;
use Nebrix\Integration\IntegrationRepository;
use Nebrix\Integration\TeamSpeak\ServerQueryClient;
use Nebrix\Integration\TeamSpeak\SocketTransport;
use Nebrix\Integration\TeamSpeak\SshTransport;

/**
 * ServerQuery over SSH.
 *
 * The transport tests need a real SSH server whose login runs
 * tests/Support/ts3_stdio_server.php as forced command; they run when
 * TEST_TS3_SSH="host:port:user:password" and TEST_TS3_SSH_HOSTKEY (ssh-keyscan
 * line) are set. The wiring tests always run.
 */
final class TeamSpeakSshTest extends IntegrationTestCase
{
    /** @return array{0: string, 1: int, 2: string, 3: string, 4: string} */
    private function sshTarget(): array
    {
        $target = (string) getenv('TEST_TS3_SSH');
        $hostKey = (string) getenv('TEST_TS3_SSH_HOSTKEY');
        if ($target === '' || $hostKey === '') {
            self::markTestSkipped('TEST_TS3_SSH / TEST_TS3_SSH_HOSTKEY are not set (no SSH ServerQuery test server).');
        }
        [$host, $port, $user, $password] = explode(':', $target, 4);

        return [$host, (int) $port, $user, $password, $hostKey];
    }

    public function testCommandsRunOverARealSshConnection(): void
    {
        [$host, $port, $user, $password, $hostKey] = $this->sshTarget();
        $transport = new SshTransport($host, $port, $user, $password, \Nebrix\Integration\Ssh\SecureShell::parseHostKeys($hostKey), 10);
        $client = new ServerQueryClient($transport, 'serveradmin', 's3cr/et x', $this->app->container()->get(IntegrationLogger::class), 0);

        self::assertTrue($client->healthCheck()->isHealthy());
        $servers = $client->listServers();
        self::assertSame([1, 2], array_map(static fn (array $s): int => (int) $s['virtualserver_id'], $servers));
        $client->disconnect();

        // A wrong ServerQuery password is reported as a ServerQuery error.
        $wrong = new ServerQueryClient(new SshTransport($host, $port, $user, $password, \Nebrix\Integration\Ssh\SecureShell::parseHostKeys($hostKey), 10), 'serveradmin', 'wrong', $this->app->container()->get(IntegrationLogger::class), 0);
        $this->assertThrows(static fn () => $wrong->listServers(), 'invalid loginname or password');
    }

    public function testTheSshLoginAndTheHostKeyAreEnforced(): void
    {
        [$host, $port, $user, $password, $hostKey] = $this->sshTarget();
        $other = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQDdifferentkeydifferentkeydifferentkeydifferentkey';
        $this->assertThrows(static fn () => (new SshTransport($host, $port, $user, $password, [$other], 5))->connect(), 'does not match the stored key');
        $this->assertThrows(static fn () => (new SshTransport($host, $port, $user, 'not-the-password', \Nebrix\Integration\Ssh\SecureShell::parseHostKeys($hostKey), 5))->connect(), 'rejected the ServerQuery login');
        $this->assertThrows(static fn () => (new SshTransport($host, $port, $user, $password, [], 5))->connect(), 'needs the host key');
    }

    public function testTheIntegrationPicksTheTransport(): void
    {
        $repository = $this->app->container()->get(IntegrationRepository::class);
        $manager = new IntegrationManager($repository, $this->app->container()->get(\GuzzleHttp\ClientInterface::class), new \Nebrix\Security\UrlGuard(false, true), $this->app->container()->get(IntegrationLogger::class), $this->app->container()->get(\Nebrix\Core\Config::class));
        $base = ['host' => '127.0.0.1', 'query_user' => 'serveradmin', 'voice_host' => 'ts.example.com', 'port_min' => 9987, 'port_max' => 9999];
        $raw = $repository->find($this->createIntegration('teamspeak', $base + ['query_protocol' => 'raw', 'query_port' => 10011], ['query_password' => 'x']));
        $ssh = $repository->find($this->createIntegration('teamspeak', $base + ['query_protocol' => 'ssh', 'query_port' => 10022, 'query_host_keys' => '[127.0.0.1]:10022 ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQDexampleexampleexampleexampleexample'], ['query_password' => 'x']));
        self::assertNotNull($raw);
        self::assertNotNull($ssh);
        $transport = static fn (ServerQueryClient $c): object => (new \ReflectionProperty(ServerQueryClient::class, 'transport'))->getValue($c);
        self::assertInstanceOf(SocketTransport::class, $transport($manager->teamspeak($raw)));
        $sshTransport = $transport($manager->teamspeak($ssh));
        self::assertInstanceOf(SshTransport::class, $sshTransport);
        self::assertSame(['ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQDexampleexampleexampleexampleexample'], (new \ReflectionProperty(SshTransport::class, 'hostKeys'))->getValue($sshTransport));
        self::assertSame(10022, (new \ReflectionProperty(SshTransport::class, 'port'))->getValue($sshTransport));
    }

    private function assertThrows(\Closure $call, string $message): void
    {
        try {
            $call();
            self::fail('Expected an exception: ' . $message);
        } catch (IntegrationException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}
