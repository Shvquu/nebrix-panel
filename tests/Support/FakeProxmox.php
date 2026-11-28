<?php

declare(strict_types=1);

namespace Nebrix\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Stateful fake of the Proxmox VE API endpoints used by the server
 * self-service features (one node "pve1"): guests with their configuration,
 * tasks, snapshots and rrddata. Every request is recorded by FakeHttp.
 */
final class FakeProxmox
{
    /** @var array<int, array{type: string, status: string, config: array<string, string>}> */
    public array $guests = [];

    /** @var array<string, string> upid => running|done|error */
    public array $tasks = [];

    /** @var array<int, list<array{name: string, description: string, snaptime: int}>> */
    public array $snapshots = [];

    /** @var array<int, list<array<string, int|float>>> */
    public array $rrd = [];

    private int $counter = 0;

    public function addGuest(int $vmid, string $type, int $serviceId, array $config = [], string $status = 'running'): void
    {
        $this->guests[$vmid] = ['type' => $type, 'status' => $status, 'config' => $config + ['description' => 'nebrix-service-' . $serviceId, 'net0' => $type === 'lxc' ? 'name=eth0,bridge=vmbr0,ip=dhcp' : 'virtio=BC:24:11:00:00:01,bridge=vmbr0']];
    }

    public function task(string $kind, int $vmid): string
    {
        $upid = sprintf('UPID:pve1:%08X:00000002:6553F00D:%s:%d:nebrix@pve!panel:', ++$this->counter, $kind, $vmid);
        $this->tasks[$upid] = 'running';

        return $upid;
    }

    /** Completes running tasks; deletion tasks remove their guest. */
    public function finishTasks(string $result = 'done'): void
    {
        foreach ($this->tasks as $upid => $state) {
            if ($state === 'running') {
                $this->tasks[$upid] = $result;
                if ($result === 'done' && str_contains($upid, ':vzdestroy:')) {
                    unset($this->guests[(int) explode(':', $upid)[6]]);
                }
            }
        }
    }

    public function register(FakeHttp $http): void
    {
        $json = static fn (mixed $data, int $status = 200): Response => new Response($status, ['Content-Type' => 'application/json'], json_encode(['data' => $data], JSON_THROW_ON_ERROR));
        $guestPath = '/api2/json/nodes/pve1/(?<type>lxc|qemu)/(?<vmid>\d+)';
        $applyConfig = function (int $vmid, array $form): void {
            foreach (array_filter(explode(',', (string) ($form['delete'] ?? ''))) as $key) {
                unset($this->guests[$vmid]['config'][$key]);
            }
            unset($form['delete']);
            foreach ($form as $key => $value) {
                $this->guests[$vmid]['config'][$key] = (string) $value;
            }
        };

        $http
            ->on('GET', '/api2/json/nodes', ['data' => [['node' => 'pve1', 'status' => 'online', 'mem' => 1, 'maxmem' => 10]]])
            ->on('GET', '/api2/json/cluster/resources', fn (): Response => $json(array_map(
                static fn (int $vmid, array $g): array => ['vmid' => $vmid, 'name' => $g['config']['hostname'] ?? $g['config']['name'] ?? 'guest', 'status' => $g['status'], 'node' => 'pve1', 'type' => $g['type']],
                array_keys($this->guests),
                $this->guests,
            )))
            ->on('POST', '/api2/json/nodes/pve1/lxc', function (RequestInterface $r) use ($json): Response {
                $form = FakeHttp::form($r);
                $vmid = (int) $form['vmid'];
                $this->guests[$vmid] = ['type' => 'lxc', 'status' => 'running', 'config' => array_map('strval', $form)];

                return $json($this->task('vzcreate', $vmid));
            })
            ->on('POST', '/api2/json/nodes/pve1/qemu/(?<template>\d+)/clone', function (RequestInterface $r) use ($json): Response {
                $form = FakeHttp::form($r);
                $vmid = (int) $form['newid'];
                $this->guests[$vmid] = ['type' => 'qemu', 'status' => 'stopped', 'config' => ['name' => (string) $form['name'], 'description' => (string) ($form['description'] ?? ''), 'scsi0' => 'local-lvm:vm-' . $vmid . '-disk-0,size=10G']];

                return $json($this->task('qmclone', $vmid));
            })
            ->on('GET', $guestPath . '/config', fn ($r, array $m): Response => isset($this->guests[(int) $m['vmid']]) ? $json($this->guests[(int) $m['vmid']]['config']) : $json(null, 500))
            ->on('POST', $guestPath . '/config', function (RequestInterface $r, array $m) use ($json, $applyConfig): Response {
                $applyConfig((int) $m['vmid'], FakeHttp::form($r));

                return $json(null);
            })
            ->on('PUT', $guestPath . '/config', function (RequestInterface $r, array $m) use ($json, $applyConfig): Response {
                $applyConfig((int) $m['vmid'], FakeHttp::form($r));

                return $json(null);
            })
            ->on('PUT', $guestPath . '/resize', fn (): Response => $json(null))
            ->on('GET', $guestPath . '/status/current', fn ($r, array $m): Response => $json(['status' => $this->guests[(int) $m['vmid']]['status'] ?? 'stopped']))
            ->on('POST', $guestPath . '/status/(?<action>\w+)', function ($r, array $m) use ($json): Response {
                $this->guests[(int) $m['vmid']]['status'] = in_array($m['action'], ['stop', 'shutdown'], true) ? 'stopped' : 'running';

                return $json($this->task('vz' . $m['action'], (int) $m['vmid']));
            })
            ->on('DELETE', $guestPath, fn ($r, array $m): Response => $json($this->task('vzdestroy', (int) $m['vmid'])))
            ->on('GET', '/api2/json/nodes/pve1/tasks/(?<upid>[^/]+)/status', function ($r, array $m) use ($json): Response {
                $state = $this->tasks[rawurldecode($m['upid'])] ?? 'unknown';

                return $json(['status' => in_array($state, ['done', 'error'], true) ? 'stopped' : 'running', 'exitstatus' => $state === 'done' ? 'OK' : ($state === 'error' ? 'snapshot feature is not available' : null)]);
            })
            ->on('GET', $guestPath . '/snapshot', fn ($r, array $m): Response => $json([
                ...array_map(static fn (array $s): array => $s + ['parent' => null], $this->snapshots[(int) $m['vmid']] ?? []),
                ['name' => 'current', 'description' => 'You are here!', 'running' => 1],
            ]))
            ->on('POST', $guestPath . '/snapshot', function (RequestInterface $r, array $m) use ($json): Response {
                $form = FakeHttp::form($r);
                $this->snapshots[(int) $m['vmid']][] = ['name' => (string) $form['snapname'], 'description' => (string) ($form['description'] ?? ''), 'snaptime' => 1764300000 + count($this->snapshots[(int) $m['vmid']] ?? [])];

                return $json($this->task('vzsnapshot', (int) $m['vmid']));
            })
            ->on('POST', $guestPath . '/snapshot/(?<name>[^/]+)/rollback', fn ($r, array $m): Response => $json($this->task('vzrollback', (int) $m['vmid'])))
            ->on('DELETE', $guestPath . '/snapshot/(?<name>[^/]+)', function ($r, array $m) use ($json): Response {
                $this->snapshots[(int) $m['vmid']] = array_values(array_filter($this->snapshots[(int) $m['vmid']] ?? [], static fn (array $s): bool => $s['name'] !== $m['name']));

                return $json($this->task('vzdelsnapshot', (int) $m['vmid']));
            })
            ->on('GET', $guestPath . '/rrddata', fn ($r, array $m): Response => $json($this->rrd[(int) $m['vmid']] ?? []));
    }
}
