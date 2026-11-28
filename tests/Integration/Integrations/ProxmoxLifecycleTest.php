<?php

declare(strict_types=1);

namespace Nebrix\Tests\Integration\Integrations;

use GuzzleHttp\Psr7\Response;
use Nebrix\Integration\IntegrationException;
use Nebrix\Network\IpPoolService;
use Nebrix\Network\PoolExhaustedException;
use Nebrix\Provisioning\ProvisionResult;
use Nebrix\Provisioning\ProxmoxProvisioner;
use Nebrix\Provisioning\RetryLaterException;
use Nebrix\Provisioning\ServiceActionRunner;
use Nebrix\Security\ValidationException;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;
use Nebrix\Tests\Support\FakeHttp;

/**
 * Static addressing, reinstall and password reset on Proxmox (fake API with
 * guests, tasks, status, deletion and the QEMU guest agent).
 */
final class ProxmoxLifecycleTest extends IntegrationTestCase
{
    private int $integrationId;

    /** @var array<int, array{name: string, status: string, description: string, type: string, config?: array<string, string>}> */
    private array $guests = [];

    /** @var array<string, string> */
    private array $tasks = [];

    private bool $agentRunning = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->integrationId = $this->createIntegration('proxmox', ['base_url' => 'https://203.0.113.10:8006', 'token_id' => 'nebrix@pve!panel', 'verify_tls' => true], ['token_secret' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0']);
        $json = static fn (mixed $data, int $status = 200): Response => new Response($status, [], json_encode(['data' => $data], JSON_THROW_ON_ERROR));
        $task = function (string $kind, int $vmid): string {
            $upid = 'UPID:pve1:00000001:00000002:6553F00D:' . $kind . ':' . $vmid . ':nebrix@pve!panel:';
            $this->tasks[$upid] = 'running';

            return $upid;
        };

        $this->http
            ->on('GET', '/api2/json/nodes', ['data' => [['node' => 'pve1', 'status' => 'online', 'mem' => 1, 'maxmem' => 10]]])
            ->on('GET', '/api2/json/cluster/resources', fn (): Response => $json(array_map(static fn (int $vmid, array $g): array => ['vmid' => $vmid, 'name' => $g['name'], 'status' => $g['status'], 'node' => 'pve1', 'type' => $g['type']], array_keys($this->guests), $this->guests)))
            ->on('POST', '/api2/json/nodes/pve1/lxc', function ($request) use ($json, $task): Response {
                $form = FakeHttp::form($request);
                $this->guests[(int) $form['vmid']] = ['name' => $form['hostname'], 'status' => 'running', 'description' => $form['description'], 'type' => 'lxc'];

                return $json($task('vzcreate', (int) $form['vmid']));
            })
            ->on('GET', '/api2/json/nodes/pve1/(?<type>lxc|qemu)/(?<vmid>\d+)/config', fn ($r, array $m): Response => $json(['description' => $this->guests[(int) $m['vmid']]['description'] ?? ''] + ($this->guests[(int) $m['vmid']]['config'] ?? [])))
            ->on('GET', '/api2/json/nodes/pve1/(?<type>lxc|qemu)/(?<vmid>\d+)/status/current', fn ($r, array $m): Response => $json(['status' => $this->guests[(int) $m['vmid']]['status'] ?? 'stopped']))
            ->on('POST', '/api2/json/nodes/pve1/(?<type>lxc|qemu)/(?<vmid>\d+)/status/(?<action>\w+)', function ($r, array $m) use ($json, $task): Response {
                $this->guests[(int) $m['vmid']]['status'] = in_array($m['action'], ['stop', 'shutdown'], true) ? 'stopped' : 'running';

                return $json($task('vz' . $m['action'], (int) $m['vmid']));
            })
            ->on('DELETE', '/api2/json/nodes/pve1/(?<type>lxc|qemu)/(?<vmid>\d+)', function ($r, array $m) use ($json, $task): Response {
                return $json($task('vzdestroy', (int) $m['vmid']));
            })
            ->on('GET', '/api2/json/nodes/pve1/tasks/(?<upid>[^/]+)/status', function ($r, array $m) use ($json): Response {
                $upid = rawurldecode($m['upid']);
                $done = ($this->tasks[$upid] ?? '') === 'done';

                return $json(['status' => $done ? 'stopped' : 'running', 'exitstatus' => $done ? 'OK' : null]);
            })
            ->on('POST', '/api2/json/nodes/pve1/qemu/(?<vmid>\d+)/agent/set-user-password', fn (): Response => $this->agentRunning ? $json(null) : new Response(500, [], json_encode(['data' => null, 'message' => 'QEMU guest agent is not running'], JSON_THROW_ON_ERROR)))
            ->on('POST', '/api2/json/nodes/pve1/qemu/(?<vmid>\d+)/config', fn (): Response => $json(null))
            ->on('POST', '/api2/json/nodes/pve1/(?<type>lxc|qemu)/(?<vmid>\d+)/vncproxy', fn (): Response => $json(['port' => '5901', 'ticket' => 'PVEVNC:6553F00D::secret-ticket', 'user' => 'nebrix@pve!panel', 'upid' => 'UPID:x']));
    }

    /** Completes running tasks; deletion tasks remove their guest. */
    private function finishTasks(): void
    {
        foreach ($this->tasks as $upid => $state) {
            if ($state === 'running') {
                $this->tasks[$upid] = 'done';
                if (str_contains($upid, ':vzdestroy:')) {
                    unset($this->guests[(int) explode(':', $upid)[6]]);
                }
            }
        }
    }

    private function provisionToEnd(Service $service): ProvisionResult
    {
        $owner = $this->app->container()->get(\Nebrix\Repository\UserRepository::class)->find($service->userId);
        self::assertNotNull($owner);
        for ($i = 0; $i < 10; $i++) {
            try {
                return $this->app->container()->get(ProxmoxProvisioner::class)->provision($this->reload($service->id), $owner);
            } catch (RetryLaterException) {
                $this->finishTasks();
            }
        }
        self::fail('Provisioning did not finish.');
    }

    private function container(?int $poolId, string $hostname, ?int $pool6Id = null): Service
    {
        return $this->createService($this->createUser(), 'lxc', $this->integrationId, [
            'ostemplate' => 'local:vztmpl/debian-12.tar.zst', 'storage' => 'local-lvm', 'cores' => 1, 'memory_mb' => 512, 'swap_mb' => 0,
            'disk_gb' => 8, 'bridge' => 'vmbr0', 'rate_mbps' => 0, 'unprivileged' => true, 'vmid_start' => 20000, 'node' => 'pve1',
            'ip_pool_id' => $poolId, 'ip6_pool_id' => $pool6Id, 'reinstall_templates' => "local:vztmpl/ubuntu-24.04.tar.zst|Ubuntu 24.04\ninvalid template|Nope",
            '_options' => ['hostname' => $hostname],
        ]);
    }

    public function testStaticAddressesComeFromThePoolAndAreReleased(): void
    {
        $pools = $this->app->container()->get(IpPoolService::class);
        $poolId = $pools->createPool('Public FSN', '203.0.113.0/29', '203.0.113.1', '1.1.1.1, 9.9.9.9', $this->integrationId);
        self::assertCount(5, $pools->addresses($poolId), '/29 minus network, broadcast and gateway.');

        $first = $this->container($poolId, 'web1');
        $this->provisionToEnd($first);
        $second = $this->container($poolId, 'web2');
        $this->provisionToEnd($second);

        $creates = $this->http->requests('POST', '/api2/json/nodes/pve1/lxc');
        self::assertSame('name=eth0,bridge=vmbr0,ip=203.0.113.2/29,gw=203.0.113.1,ip6=auto', FakeHttp::form($creates[0])['net0']);
        self::assertSame('1.1.1.1 9.9.9.9', FakeHttp::form($creates[0])['nameserver']);
        self::assertStringContainsString('ip=203.0.113.3/29', FakeHttp::form($creates[1])['net0']);
        self::assertSame(['203.0.113.2'], $pools->addressesOf($first->id));

        // A retried run keeps the address.
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($first->id, ['step' => 'done']);
        $this->provisionToEnd($first);
        self::assertSame(['203.0.113.2'], $pools->addressesOf($first->id));

        // Termination deletes the guest and returns the address.
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->app->container()->get(ProxmoxProvisioner::class)->terminate($this->reload($first->id));
                break;
            } catch (RetryLaterException) {
                $this->finishTasks();
            }
        }
        self::assertSame([], $pools->addressesOf($first->id));
        self::assertArrayNotHasKey(20000 + $first->id, $this->guests);
        self::assertSame('free', $this->db->fetchValue("SELECT status FROM nx_ip_addresses WHERE address = '203.0.113.2'"));
    }

    public function testStaticIpv6AddressesComeFromAnIpv6Pool(): void
    {
        $pools = $this->app->container()->get(IpPoolService::class);
        $v4 = $pools->createPool('Public FSN', '203.0.113.0/29', '203.0.113.1', '1.1.1.1', $this->integrationId);
        $v6 = $pools->createPool('IPv6 FSN', '2001:db8:10:20::/64', 'fe80::1', '2606:4700:4700::1111, 1.1.1.1', $this->integrationId, '2001:db8:10:20::100', '2001:0db8:10:20::102');
        self::assertSame(['2001:db8:10:20::100', '2001:db8:10:20::101', '2001:db8:10:20::102'], array_column($pools->addresses($v6), 'address'), 'Stored in canonical form.');
        self::assertSame([$v4], array_keys($pools->options(4)));
        self::assertSame([$v6], array_keys($pools->options(6)));

        $service = $this->container($v4, 'dual', $v6);
        $this->provisionToEnd($service);
        $create = FakeHttp::form($this->http->requests('POST', '/api2/json/nodes/pve1/lxc')[0]);
        self::assertSame('name=eth0,bridge=vmbr0,ip=203.0.113.2/29,gw=203.0.113.1,ip6=2001:db8:10:20::100/64,gw6=fe80::1', $create['net0']);
        self::assertSame('1.1.1.1 2606:4700:4700::1111', $create['nameserver'], 'Resolvers of both pools, without duplicates.');
        self::assertSame(['203.0.113.2', '2001:db8:10:20::100'], $pools->addressesOf($service->id));
        self::assertSame('2001:db8:10:20::100', $this->app->container()->get(ProxmoxProvisioner::class)->status($this->reload($service->id))['connection']['IPv6']);

        // A pool of the wrong family is refused instead of being written into the wrong field.
        $wrong = $this->container($v6, 'wrong');
        try {
            $this->provisionToEnd($wrong);
            self::fail('An IPv6 pool cannot serve IPv4.');
        } catch (PoolExhaustedException $exception) {
            self::assertStringContainsString('not an IPv4 pool', $exception->getMessage());
        }
    }

    public function testIpv6PoolsAreValidatedAndLimited(): void
    {
        $pools = $this->app->container()->get(IpPoolService::class);
        foreach ([
            ['2001:db8::/32', 'fe80::1', null, null, 'network'],           // prefix too short
            ['2001:db8:1::/64', '2001:db8:2::1', null, null, 'gateway'],  // gateway outside, not link-local
            ['2001:db8:1::/64', 'fe80::1', '2001:db8:2::5', null, 'first'],
            ['2001:db8:1::/64', 'fe80::1', '2001:db8:1::1', '2001:db8:1::2000', 'network'], // more than 4096
            ['2001:db8:1::5/128', 'fe80::1', null, null, 'network'],     // no assignable address
            ['ffff:ffff:ffff:ffff:ffff:ffff:ffff:ff00/120', 'fe80::1', null, null, 'network'], // multicast
            ['fe80::/64', 'fe80::1', null, null, 'network'],              // link-local
            ['::ffff:10.0.0.0/120', 'fe80::1', null, null, 'network'],    // IPv4-mapped
        ] as $i => [$network, $gateway, $first, $last, $field]) {
            try {
                $pools->createPool('v6-' . $i, $network, $gateway, null, null, $first, $last);
                self::fail('Accepted: ' . $network);
            } catch (\Nebrix\Security\ValidationException $exception) {
                self::assertArrayHasKey($field, $exception->errors(), $network);
            }
        }
        // Default range: from ::100 (below are router and node) up to the maximum.
        $id = $pools->createPool('v6-default', '2001:db8:1::/64', '2001:db8:1::1', null, null);
        $addresses = array_column($pools->addresses($id), 'address');
        self::assertCount(IpPoolService::MAX_POOL_SIZE, $addresses);
        self::assertSame(['2001:db8:1::100', '2001:db8:1::10ff'], [$addresses[0], end($addresses)]);
        // A second pool overlapping it is refused (the same address would go to two customers); a disjoint range is fine.
        try {
            $pools->createPool('v6-overlap', '2001:db8:1::/64', 'fe80::1', null, null, '2001:db8:1::1000', '2001:db8:1::1100');
            self::fail('Overlapping pool accepted.');
        } catch (\Nebrix\Security\ValidationException $exception) {
            self::assertStringContainsString('overlaps the pool "v6-default"', $exception->errors()['network'][0] ?? '');
        }
        self::assertGreaterThan(0, $pools->createPool('v6-disjoint', '2001:db8:1::/64', 'fe80::1', null, null, '2001:db8:1::2000', '2001:db8:1::20ff'));
        $v4 = $pools->createPool('v4-a', '198.51.100.0/28', '198.51.100.1', null, null);
        self::assertGreaterThan(0, $v4);
        try {
            $pools->createPool('v4-b', '198.51.100.0/29', '198.51.100.1', null, null);
            self::fail('Overlapping IPv4 pool accepted.');
        } catch (\Nebrix\Security\ValidationException $exception) {
            self::assertArrayHasKey('network', $exception->errors());
        }
        // A /126 holds the three addresses after the anycast address.
        $small = $pools->createPool('v6-small', '2001:db8:2::/126', 'fe80::1', null, null);
        self::assertSame(['2001:db8:2::1', '2001:db8:2::2', '2001:db8:2::3'], array_column($pools->addresses($small), 'address'));
    }

    public function testExhaustedPoolStopsProvisioning(): void
    {
        $pools = $this->app->container()->get(IpPoolService::class);
        $poolId = $pools->createPool('Tiny', '203.0.113.8/30', '203.0.113.9', null, null);
        $this->provisionToEnd($this->container($poolId, 'a1'));

        $this->expectException(PoolExhaustedException::class);
        $this->provisionToEnd($this->container($poolId, 'a2'));
    }

    public function testPoolValidation(): void
    {
        $pools = $this->app->container()->get(IpPoolService::class);
        foreach ([['10.0.0.0/24', '10.0.1.1'], ['10.0.0.0/8', '10.0.0.1'], ['not-a-network', '10.0.0.1']] as [$network, $gateway]) {
            try {
                $pools->createPool('X' . bin2hex(random_bytes(2)), $network, $gateway, null, null);
                self::fail('Invalid pool accepted: ' . $network);
            } catch (ValidationException) {
            }
        }

        $poolId = $pools->createPool('Reserve test', '198.51.100.0/30', '198.51.100.1', null, null);
        $address = (int) $this->db->fetchValue('SELECT id FROM nx_ip_addresses WHERE pool_id = ?', [$poolId]);
        $pools->setReserved($poolId, $address, true, 'router');
        $this->expectException(PoolExhaustedException::class);
        $pools->allocate($poolId, $this->container(null, 'r1')->id);
    }

    public function testReinstallRecreatesTheContainerWithTheChosenTemplate(): void
    {
        $poolId = $this->app->container()->get(IpPoolService::class)->createPool('Reinstall', '203.0.113.16/29', '203.0.113.17', null, null);
        $service = $this->container($poolId, 'app1');
        $first = $this->provisionToEnd($service);
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        $runner = $this->app->container()->get(ServiceActionRunner::class);

        $templates = $runner->available($this->reload($service->id))['reinstall']->fields['template']['options'] ?? [];
        self::assertSame(['local:vztmpl/debian-12.tar.zst' => 'Default image', 'local:vztmpl/ubuntu-24.04.tar.zst' => 'Ubuntu 24.04'], $templates);
        self::assertArrayNotHasKey('reset_password', $runner->available($this->reload($service->id)), 'LXC passwords need SSH access to the node (not configured).');

        $runner->run($this->reload($service->id), 'reinstall', ['template' => 'local:vztmpl/ubuntu-24.04.tar.zst'], null, null);
        self::assertSame('provisioning', $this->reload($service->id)->status);
        $second = $this->provisionToEnd($service);

        $creates = $this->http->requests('POST', '/api2/json/nodes/pve1/lxc');
        self::assertCount(2, $creates);
        self::assertSame('local:vztmpl/ubuntu-24.04.tar.zst', FakeHttp::form($creates[1])['ostemplate']);
        self::assertSame(FakeHttp::form($creates[0])['vmid'], FakeHttp::form($creates[1])['vmid'], 'Same VMID after reinstall.');
        self::assertStringContainsString('ip=203.0.113.18/29', FakeHttp::form($creates[1])['net0'], 'Same address after reinstall.');
        self::assertNotSame($first->credentials['password'], $second->credentials['password']);
        self::assertCount(1, $this->http->requests('DELETE', '/api2/json/nodes/pve1/lxc/\d+'));
    }

    public function testConsoleSessionsAreSingleUseAndStoredEncrypted(): void
    {
        $service = $this->createService($this->createUser(), 'vps', $this->integrationId, ['template_vmid' => 9000]);
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['node' => 'pve1', 'vmid' => 10600, 'type' => 'qemu', 'step' => 'done']);
        $this->guests[10600] = ['name' => 'vm10600', 'status' => 'running', 'description' => 'nebrix-service-' . $service->id, 'type' => 'qemu'];
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        $sessions = $this->app->container()->get(\Nebrix\RemoteConsole\ConsoleSessions::class);
        $user = $this->app->container()->get(\Nebrix\Repository\UserRepository::class)->find($service->userId);
        self::assertNotNull($user);

        $session = $sessions->open($this->reload($service->id), $user, '198.51.100.9');
        self::assertSame('PVEVNC:6553F00D::secret-ticket', $session['password']);
        self::assertSame(1, preg_match('#/console/ws\?token=([a-f0-9]{64})$#', $session['url'], $match));

        $stored = (string) $this->db->fetchValue('SELECT target FROM nx_console_sessions WHERE service_id = ?', [$service->id]);
        self::assertStringNotContainsString('0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0', $stored, 'The API token is stored encrypted.');
        self::assertStringNotContainsString($match[1], (string) $this->db->fetchValue('SELECT token_hash FROM nx_console_sessions WHERE service_id = ?', [$service->id]));

        $target = $sessions->redeem($match[1]);
        self::assertNotNull($target);
        self::assertSame('wss', $target['scheme']);
        self::assertSame('203.0.113.10', $target['ip']);
        self::assertSame('/api2/json/nodes/pve1/qemu/10600/vncwebsocket?port=5901&vncticket=PVEVNC%3A6553F00D%3A%3Asecret-ticket', $target['path']);
        self::assertSame('PVEAPIToken=nebrix@pve!panel=0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0', $target['headers']['Authorization']);
        self::assertNull($sessions->redeem($match[1]), 'Tokens are single use.');

        // Expired tokens and tokens of suspended services are rejected.
        $second = $sessions->open($this->reload($service->id), $user, null);
        preg_match('#token=([a-f0-9]{64})#', $second['url'], $token2);
        $this->db->update('nx_services', ['status' => 'suspended'], ['id' => $service->id]);
        self::assertNull($sessions->redeem($token2[1]));
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        $third = $sessions->open($this->reload($service->id), $user, null);
        preg_match('#token=([a-f0-9]{64})#', $third['url'], $token3);
        $this->clock()->freeze($this->clock()->now()->modify('+2 minutes'));
        self::assertNull($sessions->redeem($token3[1]));
        self::assertNull($sessions->redeem('not-a-token'));
    }

    public function testPasswordResetUsesTheGuestAgentOrCloudInit(): void
    {
        $service = $this->createService($this->createUser(), 'vps', $this->integrationId, ['template_vmid' => 9000]);
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['node' => 'pve1', 'vmid' => 10500, 'type' => 'qemu', 'step' => 'done']);
        $this->guests[10500] = ['name' => 'vm10500', 'status' => 'running', 'description' => 'nebrix-service-' . $service->id, 'type' => 'qemu'];
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        $runner = $this->app->container()->get(ServiceActionRunner::class);
        $repository = $this->app->container()->get(ServiceRepository::class);

        $message = $runner->run($this->reload($service->id), 'reset_password', [], null, null)['message'];
        self::assertStringContainsString('has been set', $message);
        $password = (string) $repository->credentials($this->reload($service->id))['password'];
        self::assertSame($password, FakeHttp::form($this->http->requests('POST', '/api2/json/nodes/pve1/qemu/10500/agent/set-user-password')[0])['password']);

        $this->agentRunning = false;
        try {
            $runner->run($this->reload($service->id), 'reset_password', [], null, null);
            self::fail('Password reset accepted without guest agent and cloud-init drive.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('no cloud-init drive', $exception->getMessage());
        }
        self::assertSame([], $this->http->requests('POST', '/api2/json/nodes/pve1/qemu/10500/config'), 'No cipassword is set on a VM without cloud-init.');
        self::assertSame($password, (string) $repository->credentials($this->reload($service->id))['password'], 'Stored credentials are unchanged.');

        $this->guests[10500] = ['name' => 'vm10500', 'status' => 'running', 'description' => 'nebrix-service-' . $service->id, 'type' => 'qemu', 'config' => ['ide2' => 'local-lvm:vm-10500-cloudinit,media=cdrom']];
        $message = $runner->run($this->reload($service->id), 'reset_password', [], null, null)['message'];
        self::assertStringContainsString('next restart', $message);
        $fallback = FakeHttp::form($this->http->requests('POST', '/api2/json/nodes/pve1/qemu/10500/config')[0]);
        self::assertSame((string) $repository->credentials($this->reload($service->id))['password'], $fallback['cipassword']);
        self::assertNotSame($password, $fallback['cipassword']);
    }

    public function testLxcRootPasswordIsSetThroughTheForcedCommandOnTheNode(): void
    {
        $shell = new class extends \Nebrix\Integration\Ssh\SecureShell {
            /** @var list<array{host: string, port: int, user: string, host_keys: list<string>, command: string, stdin: ?string}> */
            public array $calls = [];
            public int $exit = 0;

            public function execute(string $host, int $port, string $user, string $privateKey, array $hostKeys, string $command, ?string $stdin = null): array
            {
                $this->calls[] = ['host' => $host, 'port' => $port, 'user' => $user, 'host_keys' => $hostKeys, 'command' => $command, 'stdin' => $stdin];

                return ['output' => $this->exit === 0 ? 'ok' : 'nebrix: refused', 'exit' => $this->exit];
            }
        };
        $this->app->container()->set(\Nebrix\Integration\Ssh\SecureShell::class, fn () => $shell);
        $service = $this->createService($this->createUser(), 'lxc', $this->integrationId, ['ostemplate' => 'local:vztmpl/debian-12.tar.zst']);
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['node' => 'pve1', 'vmid' => 20700, 'type' => 'lxc', 'step' => 'done']);
        $this->guests[20700] = ['name' => 'ct20700', 'status' => 'running', 'description' => 'nebrix-service-' . $service->id, 'type' => 'lxc'];
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        $runner = $this->app->container()->get(ServiceActionRunner::class);
        $repository = $this->app->container()->get(ServiceRepository::class);

        // Without SSH access to the node: not offered and refused.
        self::assertArrayNotHasKey('reset_password', $runner->available($this->reload($service->id)));

        // Node access configured; the panel creates its key.
        $integrations = $this->app->container()->get(\Nebrix\Integration\IntegrationRepository::class);
        $hostKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBxKkqsQwyM4Ln1Fxocek342noZ8nQglRlAwXVIL1+qP';
        $integrations->storeGenerated($this->integrationId, [], ['lxc_ssh_nodes' => "pve1=203.0.113.11\npve2=203.0.113.12:2222", 'lxc_ssh_host_keys' => "# 203.0.113.11:22 SSH-2.0-OpenSSH_9.2\n203.0.113.11 " . $hostKey . "\n", 'lxc_ssh_user' => 'root']);
        $nodeShell = $this->app->container()->get(\Nebrix\Integration\Proxmox\NodeShell::class);
        $integration = $integrations->find($this->integrationId);
        self::assertNotNull($integration);
        self::assertNull($nodeShell->authorizedKeysLine($integration));
        $nodeShell->ensureKey($integration);
        $integration = $integrations->find($this->integrationId);
        self::assertNotNull($integration);
        self::assertMatchesRegularExpression('#^restrict,command="/usr/local/sbin/nebrix-lxc-passwd" ssh-ed25519 [A-Za-z0-9+/]+=* nebrix-lxc$#', (string) $nodeShell->authorizedKeysLine($integration));
        self::assertArrayHasKey('reset_password', $runner->available($this->reload($service->id)));

        $message = $runner->run($this->reload($service->id), 'reset_password', [], null, null)['message'];
        self::assertStringContainsString('has been set', $message);
        $password = (string) $repository->credentials($this->reload($service->id))['password'];
        self::assertSame([
            'host' => '203.0.113.11', 'port' => 22, 'user' => 'root', 'host_keys' => [$hostKey],
            'command' => 'passwd 20700 ' . $service->id, 'stdin' => $password . "\n",
        ], $shell->calls[0], 'Only IDs on the command line; the password on stdin; the host key pinned.');

        // A stopped container or a refusal keeps the stored password.
        $shell->exit = 5;
        try {
            $runner->run($this->reload($service->id), 'reset_password', [], null, null);
            self::fail('A stopped container cannot get a password.');
        } catch (\Nebrix\Provisioning\ServiceStateException $exception) {
            self::assertStringContainsString('not running', $exception->getMessage());
        }
        $shell->exit = 3;
        try {
            $runner->run($this->reload($service->id), 'reset_password', [], null, null);
            self::fail('A refusal by the node is an error.');
        } catch (IntegrationException $exception) {
            self::assertStringContainsString('marker of this service', $exception->getMessage());
        }
        self::assertSame($password, (string) $repository->credentials($this->reload($service->id))['password']);

        // A guest without this service's marker is refused before any SSH connection.
        $this->guests[20700]['description'] = 'nebrix-service-999999';
        $calls = count($shell->calls);
        try {
            $runner->run($this->reload($service->id), 'reset_password', [], null, null);
            self::fail('Foreign guest accepted.');
        } catch (\Throwable) {
        }
        self::assertCount($calls, $shell->calls);

        // A container on a node without SSH access gets no action.
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['node' => 'pve3']);
        self::assertArrayNotHasKey('reset_password', $runner->available($this->reload($service->id)));

        // Imported legacy containers carry no marker for the node script: no action either.
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['node' => 'pve1', 'legacy' => true]);
        self::assertArrayNotHasKey('reset_password', $runner->available($this->reload($service->id)));
    }

    public function testNodeAccessSettingsAreParsedStrictly(): void
    {
        $keys = \Nebrix\Integration\Proxmox\NodeShell::parseHostKeys("pve1 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBxKkqsQwyM4Ln1Fxocek342noZ8nQglRlAwXVIL1+qP\n[pve1]:2222 ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBPyQ=\nssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQDfA9t5xYz0123456789abc comment\n");
        self::assertSame(['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBxKkqsQwyM4Ln1Fxocek342noZ8nQglRlAwXVIL1+qP', 'ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBPyQ=', 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQDfA9t5xYz0123456789abc'], $keys);
        foreach (['pve1 ssh-dss AAAAB3NzaC1kc3MAAACBAP' . str_repeat('A', 40), 'not a key', 'pve1 ssh-ed25519 short'] as $line) {
            try {
                \Nebrix\Integration\Proxmox\NodeShell::parseHostKeys($line);
                self::fail('Accepted: ' . $line);
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame(['pve1' => ['host' => '10.0.0.11', 'port' => 22], 'pve2' => ['host' => '2001:db8::2', 'port' => 2222]], \Nebrix\Integration\Proxmox\NodeShell::parseNodes("pve1=10.0.0.11\n# comment\npve2=[2001:db8::2]:2222"));
        $this->expectException(\InvalidArgumentException::class);
        \Nebrix\Integration\Proxmox\NodeShell::parseNodes('pve1 10.0.0.11');
    }

    public function testLegacyGuestIsOnlyDeletedWhileItKeepsItsRecordedHostname(): void
    {
        $service = $this->createService($this->createUser(), 'lxc', $this->integrationId, ['_options' => ['hostname' => 'legacy01']]);
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['node' => 'pve1', 'vmid' => 333, 'type' => 'lxc', 'legacy' => true, 'step' => 'done']);
        $provisioner = $this->app->container()->get(ProxmoxProvisioner::class);

        // The legacy VMID was reused for another guest (or claimed by a Nebrix service).
        foreach ([['reused-by-admin', ''], ['legacy01', "nebrix-service-999"]] as [$name, $description]) {
            $this->guests[333] = ['name' => $name, 'status' => 'stopped', 'description' => $description, 'type' => 'lxc'];
            try {
                $provisioner->terminate($this->reload($service->id));
                self::fail('Foreign guest deleted: ' . $name);
            } catch (IntegrationException $exception) {
                self::assertStringContainsString('was not deleted', $exception->getMessage());
            }
        }
        self::assertSame([], $this->http->requests('DELETE', '/api2/json/nodes/pve1/lxc/333'));

        $this->guests[333] = ['name' => 'legacy01', 'status' => 'stopped', 'description' => '', 'type' => 'lxc'];
        try {
            $provisioner->terminate($this->reload($service->id));
            self::fail('Expected RetryLater while Proxmox deletes the guest.');
        } catch (RetryLaterException) {
        }
        self::assertCount(1, $this->http->requests('DELETE', '/api2/json/nodes/pve1/lxc/333'));
    }
}
