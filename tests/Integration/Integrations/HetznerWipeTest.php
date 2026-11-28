<?php

declare(strict_types=1);

namespace Nebrix\Tests\Integration\Integrations;

use DateTimeImmutable;
use Nebrix\Integration\Hetzner\RescueShell;
use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\IntegrationRepository;
use Nebrix\Provisioning\HetznerRobotProvisioner;
use Nebrix\Provisioning\HetznerWipeService;
use Nebrix\Service\ServiceRepository;
use Nebrix\Tests\Support\FakeRobot;

/**
 * Automatic disk wiping of returned Hetzner servers: rescue system with the
 * panel's own key, wipe script over SSH, return to the pool.
 */
final class HetznerWipeTest extends IntegrationTestCase
{
    private FakeRobot $robot;

    /** Simulated rescue system: state file per server IP and the commands received. */
    private object $shell;

    protected function setUp(): void
    {
        parent::setUp();
        $this->robot = new FakeRobot();
        $this->robot->resetBootsRescue = true;
        $this->robot->register($this->http);
        $this->clock()->freeze(new DateTimeImmutable('2026-11-28 10:00:00'));
        $this->shell = new class extends RescueShell {
            /** @var list<array{ip: string, host_keys: list<string>, command: string, key: string}> */
            public array $calls = [];
            /** @var array<string, string> */
            public array $state = [];
            public bool $reachable = true;

            public function run(string $ip, string $privateKey, array $hostKeys, string $command): string
            {
                if (!$this->reachable) {
                    throw new IntegrationException('SSH connection to the rescue system failed: timeout');
                }
                $this->calls[] = ['ip' => $ip, 'host_keys' => $hostKeys, 'command' => $command, 'key' => $privateKey];
                if (str_contains($command, 'nohup sh /root/nebrix-wipe.sh')) {
                    $this->state[$ip] = 'running';

                    return '';
                }

                return ($this->state[$ip] ?? '') . "\n--\n== /dev/nvme0n1\nnvme format ok\n";
            }
        };
        $this->app->container()->set(RescueShell::class, fn () => $this->shell);
    }

    private function integration(bool $autoWipe = true, string $method = 'full'): int
    {
        return $this->createIntegration('hetzner_robot', [
            'base_url' => 'https://203.0.113.50', 'username' => '#ws+AbCdEf', 'pool_prefix' => 'nebrix-free',
            'allow_orders' => false, 'order_test_mode' => true, 'cancel_on_terminate' => false, 'verify_tls' => true,
            'auto_wipe' => $autoWipe, 'wipe_method' => $method,
        ], ['password' => 'robot-pass']);
    }

    /** A dedicated service holding server $number, then terminated. */
    private function terminated(int $integrationId, int $number): int
    {
        $service = $this->createService($this->createUser(), 'dedicated', $integrationId, ['mode' => 'pool', 'image' => 'Debian 12 base']);
        $this->robot->addServer($number, 'nebrix-service-' . $service->id);
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['server_number' => $number, 'step' => 'done']);
        $this->app->container()->get(HetznerRobotProvisioner::class)->terminate($this->reload($service->id));

        return $service->id;
    }

    private function wipe(int $number): array
    {
        return (array) $this->db->fetchOne('SELECT * FROM `nx_hetzner_wipes` WHERE `server_number` = ? ORDER BY `id` DESC LIMIT 1', [$number]);
    }

    private function later(string $modify): void
    {
        $this->clock()->freeze($this->clock()->now()->modify($modify));
    }

    public function testATerminatedServerIsWipedAndReturnedToThePool(): void
    {
        $integrationId = $this->integration();
        $this->terminated($integrationId, 321);
        self::assertSame('nebrix-wipe-321', $this->robot->servers[321]['server_name']);
        self::assertSame('pending', $this->wipe(321)['status']);
        $wipes = $this->app->container()->get(HetznerWipeService::class);

        // Step 1: the panel creates its key, stores it in Robot and boots the rescue system with it.
        self::assertSame(1, $wipes->process()['started']);
        $integration = $this->app->container()->get(IntegrationRepository::class)->find($integrationId);
        self::assertNotNull($integration);
        self::assertStringContainsString('OPENSSH PRIVATE KEY', $integration->secret('wipe_ssh_key'));
        self::assertCount(1, $this->robot->keys);
        $fingerprint = array_key_first($this->robot->keys);
        self::assertStringStartsWith('ssh-ed25519 ', $this->robot->keys[$fingerprint]['data']);
        self::assertSame([$fingerprint], $this->robot->rescueKeys[321], 'Rescue system only with the panel key (no password).');
        self::assertNull($this->robot->rescue[321]['password']);
        self::assertSame(['server' => 321, 'type' => 'hw'], end($this->robot->resets));
        self::assertSame('booting', $this->wipe(321)['status']);

        // Step 2: SSH is tried only after the boot time; then the script starts in the background.
        $wipes->process();
        self::assertSame([], $this->shell->calls);
        $this->later('+3 minutes');
        $wipes->process();
        self::assertCount(1, $this->shell->calls);
        $call = $this->shell->calls[0];
        self::assertSame([FakeRobot::ip(321), ['ssh-ed25519 HOSTKEY321 root@rescue']], [$call['ip'], $call['host_keys']], 'The host key reported by Robot is pinned.');
        self::assertStringContainsString('nvme format', $call['command']);
        self::assertStringContainsString('blkdiscard', $call['command']);
        self::assertStringContainsString('METHOD=full', $call['command']);
        self::assertSame('running', $this->wipe(321)['status']);

        // Step 3: polled until the script reports "done"; then back into the pool.
        $wipes->process();
        self::assertSame('running', $this->wipe(321)['status']);
        self::assertStringContainsString('nvme format ok', (string) $this->wipe(321)['log']);
        $this->shell->state[FakeRobot::ip(321)] = 'done';
        self::assertSame(1, $wipes->process()['done']);
        self::assertSame(['done', 'nebrix-free-321'], [$this->wipe(321)['status'], $this->robot->servers[321]['server_name']]);

        // The key is created once and reused.
        $this->terminated($integrationId, 322);
        $wipes->process();
        self::assertCount(1, $this->robot->keys);
    }

    public function testTheFirewallOfASuspendedServerIsClearedBeforeTheWipe(): void
    {
        $integrationId = $this->integration();
        $service = $this->createService($this->createUser(), 'dedicated', $integrationId, ['mode' => 'pool', 'image' => 'Debian 12 base']);
        $this->robot->addServer(610, 'nebrix-service-' . $service->id);
        $this->robot->firewall[610] = ['status' => 'active', 'whitelist_hos' => true, 'rules' => ['input' => [['name' => 'ssh', 'dst_port' => '22', 'action' => 'accept']]]];
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['server_number' => 610, 'step' => 'done']);
        $provisioner = $this->app->container()->get(HetznerRobotProvisioner::class);
        $provisioner->suspend($this->reload($service->id));
        self::assertSame(['active', []], [$this->robot->firewall[610]['status'], $this->robot->firewall[610]['rules']['input']], 'Suspended: everything is dropped.');
        $provisioner->terminate($this->reload($service->id));

        // The firewall stays until the rescue system has demonstrably booted.
        $wipes = $this->app->container()->get(HetznerWipeService::class);
        self::assertSame(1, $wipes->process()['started']);
        self::assertSame('active', $this->robot->firewall[610]['status']);
        self::assertSame([], $this->http->requests('DELETE', '/firewall/610'));

        // Booted: a busy firewall is awaited, then cleared before SSH.
        $this->later('+3 minutes');
        $this->robot->firewall[610]['status'] = 'in process';
        $wipes->process();
        self::assertSame([], $this->shell->calls);
        $this->robot->firewall[610]['status'] = 'active';
        $wipes->process();
        self::assertSame(['disabled', [], []], [$this->robot->firewall[610]['status'], $this->robot->firewall[610]['rules']['input'], $this->robot->firewall[610]['rules']['output']]);
        self::assertCount(1, $this->http->requests('DELETE', '/firewall/610'));
        self::assertCount(1, $this->shell->calls);

        // A server without firewall configuration is left alone.
        $this->terminated($integrationId, 611);
        $wipes->process();
        $this->later('+3 minutes');
        $wipes->process();
        self::assertCount(0, $this->http->requests('DELETE', '/firewall/611'));
    }

    public function testNothingOnTheIpIsTrustedUnlessTheRescueSystemBooted(): void
    {
        $integrationId = $this->integration();
        // The former customer made the server boot its own disk: the rescue activation is never consumed.
        $this->robot->resetBootsRescue = false;
        $this->robot->firewall[700] = ['status' => 'active', 'whitelist_hos' => true, 'rules' => ['input' => []]];
        $this->terminated($integrationId, 700);
        $wipes = $this->app->container()->get(HetznerWipeService::class);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $wipes->process();
            $this->later('+25 minutes');
            $wipes->process();
        }
        $wipes->process();
        self::assertSame('failed', $this->wipe(700)['status']);
        self::assertSame([], $this->shell->calls, 'No SSH to whatever answers on the IP.');
        self::assertSame('active', $this->robot->firewall[700]['status'], 'The firewall of a suspended server stays.');
        self::assertSame('nebrix-wipe-700', $this->robot->servers[700]['server_name']);
    }

    public function testRescueHostKeysArePinnedByKeyOrFingerprintOrTheWipeStops(): void
    {
        $integrationId = $this->integration();
        $this->robot->rescueHostKeys = 'fingerprint';
        $this->terminated($integrationId, 710);
        $wipes = $this->app->container()->get(HetznerWipeService::class);
        $wipes->process();
        self::assertSame(['MD5:' . implode(':', str_split(md5('rescue-host-key-710'), 2))], json_decode((string) $this->wipe(710)['host_keys'], true));
        $this->later('+3 minutes');
        $wipes->process();
        self::assertSame(['MD5:' . implode(':', str_split(md5('rescue-host-key-710'), 2))], $this->shell->calls[0]['host_keys']);

        // No host key reported: the rescue system is not used at all.
        $this->robot->rescueHostKeys = 'none';
        $this->terminated($integrationId, 711);
        $resets = count($this->robot->resets);
        $wipes->process();
        self::assertSame('failed', $this->wipe(711)['status']);
        self::assertStringContainsString('no host key', (string) $this->wipe(711)['error']);
        self::assertFalse($this->robot->rescue[711]['active'], 'The activation is withdrawn.');
        self::assertCount($resets, $this->robot->resets, 'Not reset.');
    }

    public function testTheSshLayerAcceptsFingerprintPins(): void
    {
        $key = \phpseclib3\Crypt\EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH');
        $blob = (string) base64_decode(explode(' ', $key)[1], true);
        $md5 = 'MD5:' . implode(':', str_split(md5($blob), 2));
        $sha = 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
        foreach ([[$key], [$md5], [strtoupper($md5)], [$sha], ['ssh-ed25519 AAAAother', $sha . '=']] as $pins) {
            self::assertTrue(\Nebrix\Integration\Ssh\SecureShell::hostKeyMatches($key, $pins), json_encode($pins) ?: '');
        }
        self::assertFalse(\Nebrix\Integration\Ssh\SecureShell::hostKeyMatches($key, ['MD5:00:11:22:33:44:55:66:77:88:99:aa:bb:cc:dd:ee:ff']));
        self::assertFalse(\Nebrix\Integration\Ssh\SecureShell::hostKeyMatches($key, ['SHA256:' . str_repeat('A', 43)]));
        // RSA host keys are reported with their signature algorithm.
        $rsa = \phpseclib3\Crypt\RSA::createKey(2048)->getPublicKey()->toString('OpenSSH');
        self::assertTrue(\Nebrix\Integration\Ssh\SecureShell::hostKeyMatches('rsa-sha2-512 ' . explode(' ', $rsa)[1], [$rsa]));
    }

    public function testRetryStartsAfreshAndNeverDuplicatesAWipe(): void
    {
        $integrationId = $this->integration();
        $this->terminated($integrationId, 720);
        $wipes = $this->app->container()->get(HetznerWipeService::class);
        $wipes->process();
        $this->later('+3 minutes');
        $wipes->process(); // running
        $this->later('+73 hours');
        $this->shell->reachable = false;
        $wipes->process();
        self::assertSame('failed', $this->wipe(720)['status']);
        $failed = (int) $this->wipe(720)['id'];

        // Meanwhile queued again (e.g. "Queue waiting servers"): the retry is refused.
        $integration = $this->app->container()->get(IntegrationRepository::class)->find($integrationId);
        self::assertNotNull($integration);
        $second = $wipes->enqueue($integration, 720, null);
        self::assertNotNull($second);
        $admin = $this->createUser(\Nebrix\Auth\Role::ADMIN);
        self::assertFalse($wipes->retry($failed, $admin->id, null));
        $this->db->update('nx_hetzner_wipes', ['status' => 'failed'], ['id' => $second]);

        // Retried: new window, current method.
        $this->app->container()->get(IntegrationRepository::class)->storeGenerated($integrationId, [], ['wipe_method' => 'quick']);
        self::assertTrue($wipes->retry($failed, $admin->id, null));
        $row = (array) $this->db->fetchOne('SELECT * FROM nx_hetzner_wipes WHERE id = ?', [$failed]);
        self::assertSame(['pending', null, null, null, 'quick'], [$row['status'], $row['started_at'], $row['host_keys'], $row['reset_at'], $row['method']]);

        // Another integration of the same Robot account cannot open a second wipe of the server.
        $other = $this->app->container()->get(IntegrationRepository::class)->find($this->integration());
        self::assertNotNull($other);
        self::assertNull($wipes->enqueue($other, 720, null));
    }

    public function testServersThatLeftTheAccountFailInsteadOfBlockingTheQueue(): void
    {
        $integrationId = $this->integration();
        $this->terminated($integrationId, 730);
        unset($this->robot->servers[730]);
        $this->app->container()->get(HetznerWipeService::class)->process();
        self::assertSame('failed', $this->wipe(730)['status']);
        self::assertStringContainsString('no longer knows this server', (string) $this->wipe(730)['error']);
    }

    public function testUnreachableServersAreResetAgainThenFail(): void
    {
        $integrationId = $this->integration();
        $this->terminated($integrationId, 400);
        $wipes = $this->app->container()->get(HetznerWipeService::class);
        $this->shell->reachable = false;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $wipes->process(); // reset
            self::assertSame(['booting', $attempt], [$this->wipe(400)['status'], (int) $this->wipe(400)['attempts']]);
            $this->later('+25 minutes');
            $wipes->process(); // not reachable within 20 minutes: back to pending
            self::assertSame('pending', $this->wipe(400)['status']);
        }
        $wipes->process();
        self::assertSame('failed', $this->wipe(400)['status']);
        self::assertSame('nebrix-wipe-400', $this->robot->servers[400]['server_name'], 'Never returned to the pool without a wipe.');

        // Staff retry: it starts from the beginning.
        $admin = $this->createUser(\Nebrix\Auth\Role::ADMIN);
        self::assertTrue($wipes->retry((int) $this->wipe(400)['id'], $admin->id, null));
        self::assertSame(['pending', 0], [$this->wipe(400)['status'], (int) $this->wipe(400)['attempts']]);

        // A server staff renamed by hand is never wiped.
        $this->robot->servers[400]['server_name'] = 'customer-special';
        $wipes->process();
        self::assertSame('failed', $this->wipe(400)['status']);
        self::assertStringContainsString('taken over by hand', (string) $this->wipe(400)['error']);
    }

    public function testWithoutAutoWipeNothingIsQueuedAndWaitingServersCanBeQueued(): void
    {
        $integrationId = $this->integration(false);
        $this->terminated($integrationId, 500);
        self::assertSame([], $this->wipe(500), 'Manual wiping: no queue entry.');
        self::assertSame('nebrix-wipe-500', $this->robot->servers[500]['server_name']);

        // Switched on later: servers that wait for a wipe can be queued, others are left alone.
        $repository = $this->app->container()->get(IntegrationRepository::class);
        $repository->storeGenerated($integrationId, [], ['auto_wipe' => true, 'wipe_method' => 'quick']);
        $this->robot->addServer(501, 'nebrix-free-501');
        $integration = $repository->find($integrationId);
        self::assertNotNull($integration);
        $wipes = $this->app->container()->get(HetznerWipeService::class);
        self::assertSame(1, $wipes->enqueueWaiting($integration));
        self::assertSame(0, $wipes->enqueueWaiting($integration), 'No second entry for the same server.');
        self::assertSame('quick', $this->wipe(500)['method']);
        self::assertSame([], $this->wipe(501));

        $quick = HetznerWipeService::startCommand('quick');
        self::assertStringContainsString('METHOD=quick', $quick);
        $full = HetznerWipeService::script('full');
        self::assertStringContainsString('ERRORS=$((ERRORS + 1))', $full, 'Every failed step is counted.');
        self::assertStringContainsString('iflag=count_bytes', $full, 'The overwrite must cover exactly the disk size.');
        self::assertStringContainsString('zeroed "$DISK"', $full, 'A discard is accepted only if the disk reads back zeros.');
        self::assertStringContainsString('if [ "$ERRORS" -eq 0 ]; then echo done', $full);
        self::assertStringContainsString("<<'NEBRIX_WIPE_EOF'", $quick, 'The script is passed without shell expansion.');
    }
}
