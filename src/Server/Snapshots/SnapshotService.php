<?php

declare(strict_types=1);

namespace Nebrix\Server\Snapshots;

use Nebrix\Audit\AuditLogger;
use Nebrix\Catalog\ProductCategory;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Integration\IntegrationException;
use Nebrix\Integration\Proxmox\ProxmoxClient;
use Nebrix\Provisioning\ProvisionerRegistry;
use Nebrix\Provisioning\ProxmoxProvisioner;
use Nebrix\Provisioning\RetryLaterException;
use Nebrix\Provisioning\ServiceStateException;
use Nebrix\Queue\JobQueue;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;

/**
 * Snapshots of Proxmox servers (create, roll back, delete).
 *
 * Requests are recorded in nx_server_snapshot_tasks and executed by the
 * queue worker (job "service.snapshot"); the customer sees each task's state.
 * The number of snapshots is limited per product ("max_snapshots"); only one
 * task runs per server at a time, and none while the server is reinstalled
 * or restored (status provisioning) or a backup runs. Every remote call
 * checks the guest's ownership marker first.
 */
class SnapshotService
{
    public const JOB_TYPE = 'service.snapshot';

    public const ACTIONS = ['create', 'rollback', 'delete'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly JobQueue $jobs,
        private readonly AuditLogger $audit,
        private readonly ServiceRepository $services,
        private readonly ProvisionerRegistry $provisioners,
    ) {
    }

    /** Snapshot limit of the service (service configuration, else the product's current one). */
    public function maxSnapshots(Service $service): int
    {
        $max = $service->config('max_snapshots');
        if ($max === null && $service->productId !== null) {
            $config = json_decode((string) $this->db->fetchValue('SELECT `provisioning_config` FROM `nx_products` WHERE `id` = ?', [$service->productId]), true);
            $max = is_array($config) ? ($config['max_snapshots'] ?? 0) : 0;
        }

        return max(0, min(50, (int) $max));
    }

    /** Whether the feature is offered for the service (no remote call). */
    public function available(Service $service): bool
    {
        return in_array($service->category, [ProductCategory::VPS, ProductCategory::LXC], true)
            && $this->provisioners->supportsService($service)
            && isset($service->remote['node'], $service->remote['vmid'])
            && in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_PROVISIONING], true)
            && $this->maxSnapshots($service) > 0;
    }

    /**
     * Snapshots of the guest (newest first).
     *
     * @return list<array{name: string, description: string, snaptime: int, parent: string|null}>
     *
     * @throws IntegrationException
     */
    public function snapshots(Service $service): array
    {
        [$client, $node, $vmid, $type] = $this->guest($service);
        $snapshots = $client->snapshots($node, $type, $vmid);
        usort($snapshots, static fn (array $a, array $b): int => $b['snaptime'] <=> $a['snaptime']);

        return $snapshots;
    }

    /**
     * @return list<array<string, mixed>> the latest tasks of the service
     */
    public function tasks(int $serviceId, int $limit = 10): array
    {
        return $this->db->fetchAll('SELECT * FROM `nx_server_snapshot_tasks` WHERE `service_id` = ? ORDER BY `id` DESC LIMIT ' . max(1, min(50, $limit)), [$serviceId]);
    }

    /**
     * Why no snapshot task can be started right now (null = it can).
     *
     * @throws IntegrationException
     */
    public function blocker(Service $service): ?string
    {
        if (!$service->isActive()) {
            return 'Snapshots are not available while the server is being set up, reinstalled or restored.';
        }
        if ($this->openTask($service->id) !== null) {
            return 'A snapshot task is still running. Please wait until it is finished.';
        }
        if ($this->backupRunning($service)) {
            return 'A backup of the server is running. Please wait until it is finished.';
        }

        return null;
    }

    /**
     * Records a snapshot request and queues it.
     *
     * @throws ServiceStateException with a user-facing message
     * @throws IntegrationException
     */
    public function request(Service $service, string $action, string $name, ?string $description, ?int $actorId, ?string $ip): int
    {
        if (!in_array($action, self::ACTIONS, true) || !$this->available($service)) {
            throw new ServiceStateException('Snapshots are not available for this server.');
        }
        if (!ProxmoxClient::isSnapshotName($name)) {
            throw new ServiceStateException('The snapshot name must start with a letter and may contain letters, digits, "-" and "_" (2 to 40 characters).');
        }
        $description = $description !== null ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $description)) : '';
        if (mb_strlen($description) > 200) {
            throw new ServiceStateException('The description may have at most 200 characters.');
        }

        // Serialised per server: two parallel requests cannot both pass the checks below.
        $taskId = $this->db->withLock('nebrix-snapshot-' . $service->id, 5, function () use ($service, $action, $name, $description, $actorId): int {
            $service = $this->services->find($service->id) ?? $service;
            $blocker = $this->blocker($service);
            if ($blocker !== null) {
                throw new ServiceStateException($blocker);
            }
            $existing = array_column($this->snapshots($service), null, 'name');
            if ($action === 'create') {
                if (isset($existing[$name])) {
                    throw new ServiceStateException('A snapshot with this name already exists.');
                }
                if (count($existing) >= $this->maxSnapshots($service)) {
                    throw new ServiceStateException('The maximum number of snapshots has been reached. Delete an old snapshot first.');
                }
            } elseif (!isset($existing[$name])) {
                throw new ServiceStateException('The selected snapshot does not exist (anymore).');
            }

            $now = $this->clock->nowString();
            $taskId = $this->db->insert('nx_server_snapshot_tasks', [
                'service_id' => $service->id,
                'action' => $action,
                'snapshot' => $name,
                'description' => $action === 'create' && $description !== '' ? $description : null,
                'status' => 'queued',
                'requested_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->jobs->dispatch(self::JOB_TYPE, ['task_id' => $taskId], self::JOB_TYPE . ':' . $service->id . ':' . $taskId);

            return $taskId;
        });
        $this->audit->log($actorId, 'service.snapshot_' . $action . '_requested', 'service', $service->id, ['snapshot' => $name, 'task' => $taskId], $ip);

        return $taskId;
    }

    /**
     * Queue worker: runs or follows one task (idempotent; RetryLater while
     * Proxmox works on it).
     *
     * @throws RetryLaterException|IntegrationException
     */
    public function process(int $taskId): void
    {
        $task = $this->db->fetchOne('SELECT * FROM `nx_server_snapshot_tasks` WHERE `id` = ?', [$taskId]);
        if ($task === null || in_array($task['status'], ['done', 'failed'], true)) {
            return;
        }
        $service = $this->services->find((int) $task['service_id']);
        if ($service === null) {
            $this->finish($taskId, 'failed', 'The service no longer exists.');

            return;
        }
        [$client, $node, $vmid, $type] = $this->guest($service);

        if ($task['status'] === 'running' && is_string($task['upid']) && $task['upid'] !== '') {
            $status = $client->taskStatus($node, $task['upid']);
            if ($status['status'] !== 'stopped') {
                throw new RetryLaterException('Waiting for the snapshot task.', 5);
            }
            $ok = $status['exitstatus'] === 'OK';
            $this->finish($taskId, $ok ? 'done' : 'failed', $ok ? null : 'Proxmox: ' . mb_substr((string) $status['exitstatus'], 0, 200));

            return;
        }

        // Queued: the server must still be active and no backup may run.
        if (!$service->isActive()) {
            $this->finish($taskId, 'failed', 'The server was being reinstalled or restored.');

            return;
        }
        if ($this->backupRunning($service)) {
            throw new RetryLaterException('Waiting for the running backup.', 30);
        }
        $name = (string) $task['snapshot'];
        $upid = match ((string) $task['action']) {
            'create' => $client->createSnapshot($node, $type, $vmid, $name, (string) ($task['description'] ?? '')),
            'rollback' => $client->rollbackSnapshot($node, $type, $vmid, $name, ($client->status($node, $type, $vmid)['status'] ?? '') === 'running'),
            'delete' => $client->deleteSnapshot($node, $type, $vmid, $name),
            default => throw new IntegrationException('Unknown snapshot action.'),
        };
        $this->db->update('nx_server_snapshot_tasks', ['status' => 'running', 'upid' => $upid, 'updated_at' => $this->clock->nowString()], ['id' => $taskId]);

        throw new RetryLaterException('Waiting for the snapshot task.', 5);
    }

    /** The job failed for good (all attempts used): the task is marked failed. */
    public function failed(int $taskId, string $error): void
    {
        $this->finish($taskId, 'failed', mb_substr($error, 0, 200));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function openTask(int $serviceId): ?array
    {
        return $this->db->fetchOne("SELECT * FROM `nx_server_snapshot_tasks` WHERE `service_id` = ? AND `status` IN ('queued', 'running') ORDER BY `id` LIMIT 1", [$serviceId]);
    }

    private function finish(int $taskId, string $status, ?string $error): void
    {
        $now = $this->clock->nowString();
        $this->db->run(
            "UPDATE `nx_server_snapshot_tasks` SET `status` = ?, `error` = ?, `updated_at` = ?, `finished_at` = ? WHERE `id` = ? AND `status` IN ('queued', 'running')",
            [$status, $error, $now, $now, $taskId],
        );
    }

    /** @throws IntegrationException */
    private function backupRunning(Service $service): bool
    {
        $upid = $service->remote['backup_upid'] ?? null;
        if (!is_string($upid) || $upid === '') {
            return false;
        }
        [$client, $node] = $this->guest($service);

        return $client->taskStatus($node, $upid)['status'] !== 'stopped';
    }

    /**
     * @return array{0: ProxmoxClient, 1: string, 2: int, 3: string}
     *
     * @throws IntegrationException
     */
    private function guest(Service $service): array
    {
        $provisioner = $this->provisioners->for($service->category);
        if (!$provisioner instanceof ProxmoxProvisioner) {
            throw new IntegrationException('Snapshots are only available for Proxmox servers.');
        }

        return $provisioner->verifiedGuest($service);
    }
}
