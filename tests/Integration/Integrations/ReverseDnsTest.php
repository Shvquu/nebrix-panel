<?php

declare(strict_types=1);

namespace Nebrix\Tests\Integration\Integrations;

use GuzzleHttp\Psr7\Response;
use Nebrix\Integration\IntegrationException;
use Nebrix\Network\IpPoolService;
use Nebrix\Provisioning\HetznerRobotProvisioner;
use Nebrix\Provisioning\ServiceStateException;
use Nebrix\Server\ReverseDns\ReverseDnsService;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;
use Nebrix\Tests\Support\FakeHttp;
use Nebrix\Tests\Support\FakeRobot;
use Psr\Http\Message\RequestInterface;

/**
 * Reverse DNS self-service through the Hetzner Robot API (fake): dedicated
 * servers and Proxmox addresses of pools linked to Robot.
 */
final class ReverseDnsTest extends IntegrationTestCase
{
    private FakeRobot $robot;

    /** @var array<string, string> ip => ptr */
    private array $ptr = [];

    private int $robotId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->robot = new FakeRobot();
        $this->robot->register($this->http);
        $json = static fn (mixed $data, int $status = 200): Response => new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
        $notFound = $json(['error' => ['status' => 404, 'code' => 'RDNS_NOT_FOUND', 'message' => 'rdns not found']], 404);
        $this->http
            // Server data with additional addresses and subnets, as the Robot API reports them.
            ->on('GET', '/server/(?<n>\d+)', fn ($r, array $m): Response => isset($this->robot->servers[(int) $m['n']]) ? $json(['server' => [
                'server_number' => (int) $m['n'], 'server_ip' => $this->robot->servers[(int) $m['n']]['server_ip'], 'server_ipv6_net' => '2a01:4f8:10a:1f2::',
                'server_name' => $this->robot->servers[(int) $m['n']]['server_name'], 'product' => 'AX41', 'dc' => 'FSN1', 'status' => 'ready', 'cancelled' => false,
                'ip' => [$this->robot->servers[(int) $m['n']]['server_ip'], '198.51.100.77'],
                'subnet' => [['ip' => '2a01:4f8:10a:1f2::', 'mask' => '64'], ['ip' => '203.0.113.64', 'mask' => '29']],
            ]]) : $json(['error' => ['status' => 404, 'code' => 'SERVER_NOT_FOUND', 'message' => 'x']], 404))
            ->on('GET', '/rdns/(?<ip>[0-9a-f.:]+)', fn ($r, array $m): Response => isset($this->ptr[rawurldecode($m['ip'])]) ? $json(['rdns' => ['ip' => rawurldecode($m['ip']), 'ptr' => $this->ptr[rawurldecode($m['ip'])]]]) : $notFound)
            ->on('POST', '/rdns/(?<ip>[0-9a-f.:]+)', function (RequestInterface $r, array $m) use ($json): Response {
                $this->ptr[rawurldecode($m['ip'])] = FakeHttp::form($r)['ptr'];

                return $json(['rdns' => ['ip' => rawurldecode($m['ip']), 'ptr' => FakeHttp::form($r)['ptr']]]);
            })
            ->on('DELETE', '/rdns/(?<ip>[0-9a-f.:]+)', function ($r, array $m) use ($json, $notFound): Response {
                if (!isset($this->ptr[rawurldecode($m['ip'])])) {
                    return $notFound;
                }
                unset($this->ptr[rawurldecode($m['ip'])]);

                return $json(null);
            });
        $this->robotId = $this->createIntegration('hetzner_robot', [
            'base_url' => 'https://203.0.113.50', 'username' => '#ws+AbCdEf', 'pool_prefix' => 'nebrix-free',
            'allow_orders' => false, 'order_test_mode' => true, 'cancel_on_terminate' => false, 'verify_tls' => true,
        ], ['password' => 'robot-pass']);
    }

    private function dedicated(int $number = 600): Service
    {
        $service = $this->createService($this->createUser(), 'dedicated', $this->robotId, ['mode' => 'pool', 'image' => 'Debian 12 base']);
        $this->robot->addServer($number, HetznerRobotProvisioner::marker($service));
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['server_number' => $number, 'server_ip' => FakeRobot::ip($number), 'step' => 'done']);
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);

        return $this->reload($service->id);
    }

    private function rdns(): ReverseDnsService
    {
        return $this->app->container()->get(ReverseDnsService::class);
    }

    public function testDedicatedServerAddressesAndSubnets(): void
    {
        $service = $this->dedicated();
        $main = FakeRobot::ip(600);
        $this->ptr[$main] = 'old.example.com';
        self::assertTrue($this->rdns()->available($service));

        $overview = $this->rdns()->overview($service);
        self::assertSame([[ 'ip' => $main, 'family' => 4, 'ptr' => 'old.example.com', 'custom' => false], ['ip' => '198.51.100.77', 'family' => 4, 'ptr' => null, 'custom' => false]], $overview['addresses']);
        self::assertSame(['2a01:4f8:10a:1f2::/64'], $overview['subnets'], 'IPv6 subnets only.');

        $this->rdns()->set($service, '198.51.100.77', 'Mail.Example.COM.', $service->userId, '198.51.100.7');
        $post = $this->http->requests('POST', '/rdns/198.51.100.77');
        self::assertCount(1, $post);
        self::assertSame(['ptr' => 'mail.example.com'], FakeHttp::form($post[0]));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'service.rdns_updated' AND target_id = ?", [$service->id]));

        // A single IPv6 address of the subnet; it is listed afterwards.
        $this->rdns()->set($service, '2a01:4f8:10a:1f2:0:0:0:25', 'v6.example.com', null, null);
        self::assertSame('v6.example.com', $this->ptr['2a01:4f8:10a:1f2::25']);
        $listed = array_column($this->rdns()->overview($this->reload($service->id))['addresses'], 'ptr', 'ip');
        self::assertSame('v6.example.com', $listed['2a01:4f8:10a:1f2::25']);

        $this->rdns()->delete($this->reload($service->id), '2a01:4f8:10a:1f2::25', null, null);
        self::assertArrayNotHasKey('2a01:4f8:10a:1f2::25', $this->ptr);
        self::assertArrayNotHasKey('2a01:4f8:10a:1f2::25', array_column($this->rdns()->overview($this->reload($service->id))['addresses'], 'ptr', 'ip'));
    }

    public function testOnlyOwnAddressesAndValidHostNamesAreAccepted(): void
    {
        $service = $this->dedicated();
        $other = $this->dedicated(601);
        $requests = count($this->http->history);

        $refused = [
            [FakeRobot::ip(601), 'mail.example.com', 'does not belong'],      // address of another customer's server
            ['2a01:4f8:ffff::1', 'mail.example.com', 'does not belong'],      // outside the subnet
            ['203.0.113.65', 'mail.example.com', 'does not belong'],          // IPv4 subnet entries are not offered
            ['not-an-ip', 'mail.example.com', 'does not belong'],
            [FakeRobot::ip(600), 'no_dots', 'fully qualified'],
            [FakeRobot::ip(600), 'bad host.example.com', 'fully qualified'],
            [FakeRobot::ip(600), str_repeat('a', 64) . '.example.com', 'fully qualified'],
            [FakeRobot::ip(600), "mail.example.com\nptr", 'fully qualified'],
        ];
        foreach ($refused as [$ip, $ptr, $message]) {
            try {
                $this->rdns()->set($service, $ip, $ptr, null, null);
                self::fail(sprintf('Accepted %s → %s', $ip, $ptr));
            } catch (ServiceStateException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
        self::assertSame([], array_filter(array_slice($this->http->history, $requests), static fn (array $e): bool => $e['request']->getMethod() === 'POST'), 'No change was sent.');
        self::assertArrayNotHasKey(FakeRobot::ip(601), $this->ptr);

        // A server renamed in Robot (no longer this service's) is not touched.
        $this->robot->servers[601]['server_name'] = 'nebrix-free-x';
        $this->expectException(IntegrationException::class);
        $this->rdns()->set($other, FakeRobot::ip(601), 'mail.example.com', null, null);
    }

    public function testProxmoxAddressesOfPoolsLinkedToRobot(): void
    {
        $pools = $this->app->container()->get(IpPoolService::class);
        $linked = $pools->createPool('Hetzner subnet', '203.0.113.64/29', '203.0.113.65', null, null);
        $pools->setRdnsIntegration($linked, $this->robotId);
        $plain = $pools->createPool('Local', '192.0.2.0/29', '192.0.2.1', null, null);
        $proxmox = $this->createIntegration('proxmox', ['base_url' => 'https://203.0.113.10:8006', 'token_id' => 'nebrix@pve!panel', 'verify_tls' => true], ['token_secret' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0']);

        $service = $this->createService($this->createUser(), 'lxc', $proxmox, ['ip_pool_id' => $linked]);
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        $pools->allocate($linked, $service->id, 4);
        $pools->allocateAdditional($linked, $service->id, 'addon:5');
        $unlinked = $this->createService($this->createUser(), 'lxc', $proxmox, ['ip_pool_id' => $plain]);
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $unlinked->id]);
        $pools->allocate($plain, $unlinked->id, 4);

        self::assertFalse($this->rdns()->available($this->reload($unlinked->id)), 'Pools without Robot: no reverse DNS.');
        self::assertTrue($this->rdns()->available($this->reload($service->id)));
        self::assertSame(['203.0.113.66', '203.0.113.67'], array_column($this->rdns()->overview($this->reload($service->id))['addresses'], 'ip'), 'Main and additional address.');

        $this->rdns()->set($this->reload($service->id), '203.0.113.67', 'extra.example.com', null, null);
        self::assertSame('extra.example.com', $this->ptr['203.0.113.67']);
        try {
            $this->rdns()->set($this->reload($service->id), '203.0.113.68', 'free.example.com', null, null);
            self::fail('A free address of the pool was accepted.');
        } catch (ServiceStateException) {
        }
        try {
            $this->rdns()->set($this->reload($unlinked->id), '192.0.2.2', 'x.example.com', null, null);
            self::fail('Reverse DNS for a pool without Robot.');
        } catch (ServiceStateException) {
        }
    }
}
