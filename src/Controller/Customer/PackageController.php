<?php

declare(strict_types=1);

namespace Nebrix\Controller\Customer;

use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Repository\CustomerGroupRepository;
use Nebrix\Security\RateLimiter;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceChangeException;
use Nebrix\Service\ServiceChangeService;
use Nebrix\Service\ServiceRepository;

/**
 * Upgrade / downgrade of a customer's own service.
 */
final class PackageController extends Controller
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly ServiceChangeService $changes,
        private readonly CustomerGroupRepository $groups,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function show(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);

        return $this->render($request, 'customer/services/package.twig', [
            'service' => $service,
            'options' => $this->changes->options($service, $this->user($request)),
            'history' => $this->changes->history($id),
            'may_manage' => $this->mayManage($request),
        ]);
    }

    public function change(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        $this->ensureMayManage($request);
        $data = $this->validate($request, ['product_id' => 'required|int|min:1', 'accept' => 'bool']);
        if ($data['accept'] !== true) {
            throw \Nebrix\Security\ValidationException::withMessages(['accept' => 'Please confirm the package change.']);
        }
        if (!$this->limiter->attempt('package-change:' . $this->user($request)->id, 10, 3600)) {
            throw HttpException::tooManyRequests();
        }

        try {
            $result = $this->changes->request($service, $this->user($request), (int) $data['product_id'], $this->user($request)->id, $request->clientIp());
        } catch (ServiceChangeException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/services/' . $id . '/package');
        }

        if ($result['invoice_id'] !== null) {
            $this->flash($request, 'success', 'Please pay the invoice; the package changes as soon as it is paid.');

            return $this->redirect('/invoices/' . $result['invoice_id']);
        }
        $this->flash($request, 'success', $result['status'] === ServiceChangeService::SCHEDULED
            ? 'The package change has been scheduled for the end of the paid period.'
            : 'The package is being changed.');

        return $this->redirect('/services/' . $id . '/package');
    }

    public function withdraw(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        $this->ensureMayManage($request);
        try {
            $this->changes->withdraw($service, $this->user($request)->id, $request->clientIp());
            $this->flash($request, 'success', 'The package change has been withdrawn.');
        } catch (ServiceChangeException $exception) {
            $this->flash($request, 'error', $exception->getMessage());
        }

        return $this->redirect('/services/' . $id . '/package');
    }

    private function mayManage(Request $request): bool
    {
        $user = $this->user($request);
        if ($user->customerGroupId === null) {
            return true;
        }
        $group = $this->groups->find($user->customerGroupId);

        return $group === null || (bool) $group['can_manage_services'];
    }

    private function ensureMayManage(Request $request): void
    {
        if (!$this->mayManage($request)) {
            throw HttpException::forbidden();
        }
    }

    private function find(Request $request, int $id): Service
    {
        return $this->services->findForUser($id, $this->user($request)->id) ?? throw HttpException::notFound();
    }
}
