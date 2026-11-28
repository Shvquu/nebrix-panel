<?php

declare(strict_types=1);

namespace Nebrix\Controller\Customer;

use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Integration\IntegrationException;
use Nebrix\Provisioning\ServiceStateException;
use Nebrix\Repository\CustomerGroupRepository;
use Nebrix\Security\RateLimiter;
use Nebrix\Server\Graphs\ResourceGraphs;
use Nebrix\Server\ReverseDns\ReverseDnsService;
use Nebrix\Server\Snapshots\SnapshotService;
use Nebrix\Service\Service;
use Nebrix\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * Server self-service of customers: snapshots, resource graphs and reverse
 * DNS. Every lookup is scoped to the signed-in customer (IDOR); changes need
 * the "manage services" right of the customer group and are rate-limited.
 */
final class ServerController extends Controller
{
    public function __construct(
        private readonly CustomerGroupRepository $groups,
        private readonly SnapshotService $snapshots,
        private readonly ResourceGraphs $graphs,
        private readonly ReverseDnsService $rdns,
        private readonly RateLimiter $limiter,
        private readonly SettingsRepository $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    // -------------------------------------------------------------- snapshots

    public function snapshots(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        if (!$this->mayManage($request) || !$this->snapshots->available($service)) {
            throw HttpException::notFound();
        }
        $snapshots = [];
        $blocker = null;
        $error = null;
        try {
            $snapshots = $this->snapshots->snapshots($service);
            $blocker = $this->snapshots->blocker($service);
        } catch (IntegrationException $exception) {
            $this->logger->notice('Snapshots unavailable', ['service' => $id, 'message' => $exception->getMessage()]);
            $error = 'The snapshots could not be loaded. Please try again later.';
        }

        return $this->render($request, 'customer/services/snapshots.twig', [
            'service' => $service,
            'snapshots' => $snapshots,
            'tasks' => $this->snapshots->tasks($id),
            'max' => $this->snapshots->maxSnapshots($service),
            'blocker' => $blocker,
            'error' => $error,
        ]);
    }

    public function createSnapshot(Request $request, int $id): Response
    {
        $data = $this->validate($request, ['name' => 'required|string|max:40', 'description' => 'nullable|string|max:200'], ['name' => 'Name', 'description' => 'Description']);

        return $this->snapshotRequest($request, $id, 'create', (string) $data['name'], $data['description'] !== null ? (string) $data['description'] : null, 'The snapshot is being created.');
    }

    public function rollbackSnapshot(Request $request, int $id): Response
    {
        $data = $this->validate($request, ['snapshot' => 'required|string|max:40', 'confirm' => 'bool']);
        if ($data['confirm'] !== true) {
            $this->flash($request, 'error', 'Confirm that the current state of the server will be overwritten.');

            return $this->redirect('/services/' . $id . '/snapshots');
        }

        return $this->snapshotRequest($request, $id, 'rollback', (string) $data['snapshot'], null, 'The server is being rolled back to the snapshot.');
    }

    public function deleteSnapshot(Request $request, int $id): Response
    {
        $data = $this->validate($request, ['snapshot' => 'required|string|max:40']);

        return $this->snapshotRequest($request, $id, 'delete', (string) $data['snapshot'], null, 'The snapshot is being deleted.');
    }

    private function snapshotRequest(Request $request, int $id, string $action, string $name, ?string $description, string $message): Response
    {
        $service = $this->find($request, $id);
        if (!$this->mayManage($request) || !$this->snapshots->available($service)) {
            throw HttpException::notFound();
        }
        if (!$this->limiter->attempt('server-snapshot:' . $id, max(1, $this->settings->int('server.snapshot_requests_per_hour')), 3600)) {
            throw HttpException::tooManyRequests();
        }
        try {
            $this->snapshots->request($service, $action, $name, $description, $this->user($request)->id, $request->clientIp());
            $this->flash($request, 'success', $message);
        } catch (ServiceStateException $exception) {
            $this->flash($request, 'error', $exception->getMessage());
        } catch (IntegrationException $exception) {
            $this->logger->warning('Snapshot request failed', ['service' => $id, 'action' => $action, 'message' => $exception->getMessage()]);
            $this->flash($request, 'error', 'The action could not be executed. Please try again later or contact support.');
        }

        return $this->redirect('/services/' . $id . '/snapshots');
    }

    // ----------------------------------------------------------------- graphs

    public function graphs(Request $request, int $id): Response
    {
        $service = $this->find($request, $id, 'services.view');
        if (!$this->graphs->available($service)) {
            throw HttpException::notFound();
        }

        return $this->render($request, 'customer/services/graphs.twig', [
            'service' => $service,
            'data_url' => '/services/' . $id . '/graphs.json',
            'back_url' => '/services/' . $id,
            'timeframes' => ResourceGraphs::TIMEFRAMES,
        ]);
    }

    public function graphData(Request $request, int $id): Response
    {
        $service = $this->container->get(\Nebrix\Account\TeamService::class)->service($id, $this->user($request), 'services.view');
        if ($service === null || !$this->graphs->available($service)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $timeframe = $request->string('timeframe', 'hour');
        if (!in_array($timeframe, ResourceGraphs::TIMEFRAMES, true)) {
            return Response::json(['error' => 'invalid_timeframe'], 422);
        }
        if (!$this->limiter->attempt('server-graphs:' . $this->user($request)->id, 120, 300)) {
            return Response::json(['error' => 'too_many_requests'], 429);
        }
        try {
            return Response::json($this->graphs->data($service, $timeframe));
        } catch (IntegrationException $exception) {
            $this->logger->notice('Graph data unavailable', ['service' => $id, 'message' => $exception->getMessage()]);

            return Response::json(['error' => 'unavailable'], 502);
        }
    }

    // ------------------------------------------------------------ reverse DNS

    public function reverseDns(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        if (!$this->mayManage($request) || !$this->rdns->available($service)) {
            throw HttpException::notFound();
        }
        $overview = ['addresses' => [], 'subnets' => []];
        $error = null;
        try {
            $overview = $this->rdns->overview($service);
        } catch (IntegrationException|ServiceStateException $exception) {
            $this->logger->notice('Reverse DNS unavailable', ['service' => $id, 'message' => $exception->getMessage()]);
            $error = 'The reverse DNS entries could not be loaded. Please try again later.';
        }

        return $this->render($request, 'customer/services/rdns.twig', ['service' => $service, 'overview' => $overview, 'error' => $error]);
    }

    public function setReverseDns(Request $request, int $id): Response
    {
        $data = $this->validate($request, ['ip' => 'required|string|max:45', 'ptr' => 'required|string|max:253'], ['ip' => 'IP address', 'ptr' => 'Host name']);

        return $this->rdnsChange($request, $id, function (Service $service) use ($request, $data): void {
            $this->rdns->set($service, (string) $data['ip'], (string) $data['ptr'], $this->user($request)->id, $request->clientIp());
        }, 'The reverse DNS entry has been updated.');
    }

    public function deleteReverseDns(Request $request, int $id): Response
    {
        $data = $this->validate($request, ['ip' => 'required|string|max:45']);

        return $this->rdnsChange($request, $id, function (Service $service) use ($request, $data): void {
            $this->rdns->delete($service, (string) $data['ip'], $this->user($request)->id, $request->clientIp());
        }, 'The reverse DNS entry has been removed.');
    }

    /**
     * @param callable(Service): void $change
     */
    private function rdnsChange(Request $request, int $id, callable $change, string $message): Response
    {
        $service = $this->find($request, $id);
        if (!$this->mayManage($request) || !$this->rdns->available($service)) {
            throw HttpException::notFound();
        }
        if (!$this->limiter->attempt('server-rdns:' . $id, max(1, $this->settings->int('server.rdns_changes_per_hour')), 3600)) {
            throw HttpException::tooManyRequests();
        }
        try {
            $change($service);
            $this->flash($request, 'success', $message);
        } catch (ServiceStateException $exception) {
            $this->flash($request, 'error', $exception->getMessage());
        } catch (IntegrationException $exception) {
            $this->logger->warning('Reverse DNS change failed', ['service' => $id, 'message' => $exception->getMessage()]);
            $this->flash($request, 'error', 'The action could not be executed. Please try again later or contact support.');
        }

        return $this->redirect('/services/' . $id . '/rdns');
    }

    // ------------------------------------------------------------------------

    private function mayManage(Request $request): bool
    {
        $user = $this->user($request);
        if ($user->customerGroupId === null) {
            return true;
        }
        $group = $this->groups->find($user->customerGroupId);

        return $group === null || (bool) $group['can_manage_services'];
    }

    /** An own service or one shared with the user (team access) with the given permission. */
    private function find(Request $request, int $id, string $permission = 'services.manage'): Service
    {
        return $this->container->get(\Nebrix\Account\TeamService::class)->service($id, $this->user($request), $permission) ?? throw HttpException::notFound();
    }
}
