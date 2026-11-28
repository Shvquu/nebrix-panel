<?php

declare(strict_types=1);

namespace Nebrix\Tests\Integration\Integrations;

use Nebrix\Integration\IntegrationException;
use Nebrix\Provisioning\RetryLaterException;
use Nebrix\Provisioning\ServiceStateException;
use Nebrix\Server\Graphs\ResourceGraphs;
use Nebrix\Server\Snapshots\SnapshotJob;
use Nebrix\Server\Snapshots\SnapshotService;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;
use Nebrix\Tests\Support\FakeHttp;
use Nebrix\Tests\Support\FakeProxmox;

/**
 * Snapshots (through the queue) and resource graphs of Proxmox guests
 * against a fake API.
 */
final class ServerSnapshotTest extends IntegrationTestCase
{
    private FakeProxmox $pve;

    private int $integrationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->integrationId = $this->createIntegration('proxmox', ['base_url' => 'https://203.0.113.10:8006', 'token_id' => 'nebrix@pve!panel', 'verify_tls' => true], ['token_secret' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0']);
        $this->pve = new FakeProxmox();
        $this->pve->register($this->http);
    }

    /**
     * An active guest of the given type with VMID 30000 + service ID.
     *
     * @param array<string, mixed> $config
     */
    private function server(string $category = 'vps', array $config = ['max_snapshots' => 2]): Service
    {
        $service = $this->createService($this->createUser(), $category, $this->integrationId, $config + ['node' => 'pve1', 'vmid_start' => 30000]);
        $vmid = 30000 + $service->id;
        $type = $category === 'lxc' ? 'lxc' : 'qemu';
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['node' => 'pve1', 'vmid' => $vmid, 'type' => $type, 'step' => 'done']);
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);
        $this->pve->addGuest($vmid, $type, $service->id);

        return $this->reload($service->id);
    }

    /** Runs the queued snapshot jobs until they are finished (tasks complete in between). */
    private function runJobs(string $result = 'done'): void
    {
        $job = $this->app->container()->get(SnapshotJob::class);
        foreach ($this->db->fetchAll("SELECT id, payload FROM nx_jobs WHERE type = 'service.snapshot' AND status = 'pending' ORDER BY id") as $row) {
            $this->db->update('nx_jobs', ['status' => 'completed'], ['id' => $row['id']]);
            for ($i = 0; $i < 5; $i++) {
                try {
                    $job->handle((array) json_decode((string) $row['payload'], true));
                    break;
                } catch (RetryLaterException) {
                    $this->pve->finishTasks($result);
                }
            }
        }
    }

    private function task(int $id): array
    {
        return (array) $this->db->fetchOne('SELECT * FROM nx_server_snapshot_tasks WHERE id = ?', [$id]);
    }

    public function testSnapshotsAreCreatedRolledBackAndDeletedThroughTheQueue(): void
    {
        $service = $this->server('vps');
        $vmid = 30000 + $service->id;
        $snapshots = $this->app->container()->get(SnapshotService::class);
        self::assertTrue($snapshots->available($service));
        self::assertSame([], $snapshots->snapshots($service), '"current" is not a snapshot.');

        $taskId = $snapshots->request($service, 'create', 'before-update', "Before the\nupdate", $service->userId, '198.51.100.7');
        self::assertSame('queued', $this->task($taskId)['status']);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_jobs WHERE type = 'service.snapshot' AND unique_key = ?", ['service.snapshot:' . $service->id . ':' . $taskId]));
        self::assertSame([], $this->http->requests('POST', '/api2/json/nodes/pve1/qemu/' . $vmid . '/snapshot'), 'Nothing happens before the worker runs.');

        $this->runJobs();
        $create = $this->http->requests('POST', '/api2/json/nodes/pve1/qemu/' . $vmid . '/snapshot');
        self::assertCount(1, $create);
        self::assertSame(['snapname' => 'before-update', 'description' => 'Before the update', 'vmstate' => '0'], FakeHttp::form($create[0]));
        self::assertSame('done', $this->task($taskId)['status']);
        self::assertNotNull($this->task($taskId)['finished_at']);
        self::assertSame(['before-update'], array_column($snapshots->snapshots($service), 'name'));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM nx_audit_log WHERE action = 'service.snapshot_create_requested' AND target_id = ?", [$service->id]));

        // Roll back: the running VM is started again after the rollback.
        $rollback = $snapshots->request($service, 'rollback', 'before-update', null, $service->userId, null);
        $this->runJobs();
        $request = $this->http->requests('POST', '/api2/json/nodes/pve1/qemu/' . $vmid . '/snapshot/before-update/rollback');
        self::assertCount(1, $request);
        self::assertSame(['start' => '1'], FakeHttp::form($request[0]));
        self::assertSame('done', $this->task($rollback)['status']);

        $delete = $snapshots->request($service, 'delete', 'before-update', null, $service->userId, null);
        $this->runJobs();
        self::assertCount(1, $this->http->requests('DELETE', '/api2/json/nodes/pve1/qemu/' . $vmid . '/snapshot/before-update'));
        self::assertSame('done', $this->task($delete)['status']);
        self::assertSame([], $snapshots->snapshots($service));
    }

    public function testRequestsAreValidatedAndLimited(): void
    {
        $service = $this->server('lxc');
        $vmid = 30000 + $service->id;
        $snapshots = $this->app->container()->get(SnapshotService::class);
        $this->pve->snapshots[$vmid] = [['name' => 'one', 'description' => '', 'snaptime' => 1764300000], ['name' => 'two', 'description' => '', 'snaptime' => 1764300100]];

        $refused = [
            ['create', 'three', 'The maximum number of snapshots has been reached.'],
            ['create', 'one', 'already exists'],
            ['create', '1abc', 'must start with a letter'],
            ['create', 'current', 'must start with a letter'],
            ['create', 'x', 'must start with a letter'],
            ['create', "a'; rm -rf /", 'must start with a letter'],
            ['rollback', 'missing', 'does not exist'],
            ['delete', 'missing', 'does not exist'],
            ['destroy', 'one', 'not available'],
        ];
        foreach ($refused as [$action, $name, $message]) {
            try {
                $snapshots->request($service, $action, $name, null, null, null);
                self::fail(sprintf('Accepted %s %s', $action, $name));
            } catch (ServiceStateException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
        try {
            $snapshots->request($service, 'delete', 'one', str_repeat('x', 201), null, null);
            self::fail('Long description accepted.');
        } catch (ServiceStateException) {
        }
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM nx_server_snapshot_tasks'));

        // One task at a time.
        $snapshots->request($service, 'delete', 'one', null, null, null);
        try {
            $snapshots->request($service, 'delete', 'two', null, null, null);
            self::fail('A second task was queued.');
        } catch (ServiceStateException $exception) {
            self::assertStringContainsString('still running', $exception->getMessage());
        }

        // Disabled on the product: not available.
        self::assertFalse($snapshots->available($this->server('lxc', ['max_snapshots' => 0])));
        self::assertFalse($snapshots->available($this->server('lxc', [])));
    }

    public function testNoSnapshotsDuringBackupsOrReinstallsAndFailuresAreShown(): void
    {
        $service = $this->server('lxc');
        $vmid = 30000 + $service->id;
        $snapshots = $this->app->container()->get(SnapshotService::class);

        // A running backup blocks new requests.
        $this->app->container()->get(ServiceRepository::class)->mergeRemote($service->id, ['backup_upid' => $this->pve->task('vzdump', $vmid)]);
        self::assertStringContainsString('backup of the server is running', (string) $snapshots->blocker($this->reload($service->id)));
        try {
            $snapshots->request($this->reload($service->id), 'create', 'during-backup', null, null, null);
            self::fail('Snapshot during a backup.');
        } catch (ServiceStateException) {
        }
        $this->pve->finishTasks();
        self::assertNull($snapshots->blocker($this->reload($service->id)));

        // A queued task whose server is being reinstalled meanwhile fails without a remote call.
        $taskId = $snapshots->request($this->reload($service->id), 'create', 'queued-one', null, null, null);
        $this->db->update('nx_services', ['status' => 'provisioning'], ['id' => $service->id]);
        $this->runJobs();
        self::assertSame('failed', $this->task($taskId)['status']);
        self::assertSame([], $this->http->requests('POST', '/api2/json/nodes/pve1/lxc/' . $vmid . '/snapshot'));
        self::assertStringContainsString('being set up, reinstalled or restored', (string) $snapshots->blocker($this->reload($service->id)));
        $this->db->update('nx_services', ['status' => 'active'], ['id' => $service->id]);

        // A failed Proxmox task is recorded as failed with its exit status.
        $failing = $snapshots->request($this->reload($service->id), 'create', 'no-zfs', null, null, null);
        $this->runJobs('error');
        self::assertSame('failed', $this->task($failing)['status']);
        self::assertStringContainsString('snapshot feature is not available', (string) $this->task($failing)['error']);

        // A reused VMID (marker of another service) is never touched.
        $this->pve->guests[$vmid]['config']['description'] = 'nebrix-service-999999';
        $this->expectException(IntegrationException::class);
        $snapshots->snapshots($this->reload($service->id));
    }

    public function testGraphDataIsReadCachedAndChecked(): void
    {
        $service = $this->server('vps', []);
        $vmid = 30000 + $service->id;
        $this->pve->rrd[$vmid] = [
            ['time' => 1764300060, 'cpu' => 0.25, 'maxcpu' => 2, 'mem' => 536870912, 'maxmem' => 2147483648, 'netin' => 1200.5, 'netout' => 300, 'diskread' => 0, 'diskwrite' => 4096],
            ['time' => 1764300000, 'cpu' => 0.5, 'mem' => 268435456, 'maxmem' => 2147483648],
            ['time' => 'broken'],
        ];
        $graphs = $this->app->container()->get(ResourceGraphs::class);

        $data = $graphs->data($service, 'hour');
        self::assertSame('hour', $data['timeframe']);
        self::assertSame([1764300000, 1764300060], array_column($data['points'], 't'), 'Sorted, invalid rows dropped.');
        self::assertSame(50.0, $data['points'][0]['cpu']);
        self::assertNull($data['points'][0]['netin']);
        self::assertSame(25.0, $data['points'][1]['cpu']);
        self::assertSame(1200.5, $data['points'][1]['netin']);
        $request = $this->http->requests('GET', '/api2/json/nodes/pve1/qemu/' . $vmid . '/rrddata');
        self::assertCount(1, $request);
        parse_str($request[0]->getUri()->getQuery(), $query);
        self::assertSame(['timeframe' => 'hour', 'cf' => 'AVERAGE'], $query);

        // Cached: a second page view does not reach Proxmox.
        $graphs->data($service, 'hour');
        self::assertCount(1, $this->http->requests('GET', '/api2/json/nodes/pve1/qemu/' . $vmid . '/rrddata'));
        $graphs->data($service, 'week');
        self::assertCount(2, $this->http->requests('GET', '/api2/json/nodes/pve1/qemu/' . $vmid . '/rrddata'));

        try {
            $graphs->data($service, 'year; DROP');
            self::fail('Unknown time frame accepted.');
        } catch (\InvalidArgumentException) {
        }

        // A guest with the marker of another service is not read.
        $other = $this->server('lxc', []);
        $this->pve->guests[30000 + $other->id]['config']['description'] = 'nebrix-service-999999';
        $this->expectException(IntegrationException::class);
        $graphs->data($other, 'day');
    }
}
