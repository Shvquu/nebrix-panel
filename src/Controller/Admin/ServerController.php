<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Authorization\Permission;
use Nebrix\Catalog\ProductCategory;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Integration\IntegrationException;
use Nebrix\Network\IpPoolService;
use Nebrix\Queue\JobQueue;
use Nebrix\Server\AdditionalIpv4Job;
use Nebrix\Server\Graphs\ResourceGraphs;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;

/**
 * Staff views of server self-service: resource graphs (services.view) and
 * additional IPv4 addresses added or removed by hand (services.manage,
 * through the queue, audit-logged).
 */
final class ServerController extends Controller
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly ResourceGraphs $graphs,
        private readonly IpPoolService $pools,
        private readonly JobQueue $jobs,
    ) {
    }

    public function graphs(Request $request, int $id): Response
    {
        $service = $this->find($id);
        if (!$this->graphs->available($service)) {
            throw HttpException::notFound();
        }

        return $this->render($request, 'admin/services/graphs.twig', [
            'service' => $service,
            'data_url' => '/admin/services/' . $id . '/graphs.json',
            'back_url' => '/admin/services/' . $id,
            'timeframes' => ResourceGraphs::TIMEFRAMES,
        ]);
    }

    public function graphData(Request $request, int $id): Response
    {
        $service = $this->services->find($id);
        if ($service === null || !$this->graphs->available($service)) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $timeframe = $request->string('timeframe', 'hour');
        if (!in_array($timeframe, ResourceGraphs::TIMEFRAMES, true)) {
            return Response::json(['error' => 'invalid_timeframe'], 422);
        }
        try {
            return Response::json($this->graphs->data($service, $timeframe));
        } catch (IntegrationException $exception) {
            return Response::json(['error' => mb_substr($exception->getMessage(), 0, 200)], 502);
        }
    }

    public function addIpv4(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::SERVICES_MANAGE);
        $service = $this->find($id);
        if (!in_array($service->category, [ProductCategory::VPS, ProductCategory::LXC], true) || $service->integrationId === null || (int) $service->config('ip_pool_id', 0) < 1
            || in_array($service->status, [Service::STATUS_TERMINATED, Service::STATUS_CANCELLED], true)) {
            $this->flash($request, 'error', 'Additional IPv4 addresses need a Proxmox server whose product has an IPv4 pool.');

            return $this->redirect('/admin/services/' . $id);
        }
        $key = 'manual:' . bin2hex(random_bytes(10));
        $this->jobs->dispatch(AdditionalIpv4Job::JOB_TYPE, ['service_id' => $id, 'operation' => 'attach', 'key' => $key], AdditionalIpv4Job::JOB_TYPE . ':' . $id . ':' . $key);
        $this->audit($request, 'service.ipv4_add_requested', 'service', $id, ['key' => $key]);
        $this->flash($request, 'success', 'An additional IPv4 address is being added. It appears here when the job has run.');

        return $this->redirect('/admin/services/' . $id);
    }

    public function removeIpv4(Request $request, int $id, int $address): Response
    {
        $this->authorize($request, Permission::SERVICES_MANAGE);
        $service = $this->find($id);
        $entry = null;
        foreach ($this->pools->additionalAddresses($service->id) as $candidate) {
            if ($candidate['id'] === $address) {
                $entry = $candidate;
            }
        }
        if ($entry === null) {
            throw HttpException::notFound();
        }
        if (!str_starts_with($entry['addon_key'], 'manual:')) {
            $this->flash($request, 'error', 'This address belongs to a booked add-on. Cancel the add-on instead.');

            return $this->redirect('/admin/services/' . $id);
        }
        $this->jobs->dispatch(AdditionalIpv4Job::JOB_TYPE, ['service_id' => $id, 'operation' => 'detach', 'key' => $entry['addon_key']], AdditionalIpv4Job::JOB_TYPE . ':' . $id . ':' . $entry['addon_key'] . ':detach');
        $this->audit($request, 'service.ipv4_remove_requested', 'service', $id, ['address' => $entry['address']]);
        $this->flash($request, 'success', 'The address is being removed from the server.');

        return $this->redirect('/admin/services/' . $id);
    }

    private function find(int $id): Service
    {
        return $this->services->find($id) ?? throw HttpException::notFound();
    }
}
