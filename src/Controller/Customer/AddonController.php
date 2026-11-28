<?php

declare(strict_types=1);

namespace Nebrix\Controller\Customer;

use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Repository\CustomerGroupRepository;
use Nebrix\Security\RateLimiter;
use Nebrix\Security\ValidationException;
use Nebrix\Service\Addon\AddonRepository;
use Nebrix\Service\Addon\AddonService;
use Nebrix\Service\CycleChangeService;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceChangeException;
use Nebrix\Service\ServiceRepository;

/**
 * Add-ons and the billing cycle of a customer's own service (service page).
 * Every lookup is scoped to the signed-in customer; prices are calculated
 * on the server.
 */
final class AddonController extends Controller
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly AddonService $addonService,
        private readonly AddonRepository $addons,
        private readonly CycleChangeService $cycles,
        private readonly CustomerGroupRepository $groups,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function book(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        $this->ensureMayManage($request);
        $data = $this->validate($request, ['addon_id' => 'required|int|min:1', 'quantity' => 'required|int|min:1|max:100', 'accept' => 'bool']);
        if ($data['accept'] !== true) {
            throw ValidationException::withMessages(['accept' => 'Please confirm the booking with costs.']);
        }
        if (!$this->limiter->attempt('addon-booking:' . $this->user($request)->id, 10, 3600)) {
            throw HttpException::tooManyRequests();
        }

        try {
            $result = $this->addonService->book($service, $this->user($request), (int) $data['addon_id'], (int) $data['quantity'], $this->user($request)->id, $request->clientIp());
        } catch (ServiceChangeException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/services/' . $id);
        }
        if (!$result['paid']) {
            $this->flash($request, 'success', 'Please pay the invoice; the add-on is set up as soon as it is paid.');

            return $this->redirect('/invoices/' . $result['invoice_id']);
        }
        $this->flash($request, 'success', 'The add-on has been booked and is being set up.');

        return $this->redirect('/services/' . $id);
    }

    /** Confirmation page of the cancellation (what ends and when). */
    public function confirmCancel(Request $request, int $id, int $addon): Response
    {
        $service = $this->find($request, $id);
        $booked = $this->addons->bookedForUser($addon, $service->id, $this->user($request)->id) ?? throw HttpException::notFound();
        if (!in_array($booked['status'], [...AddonRepository::LIVE, AddonRepository::PENDING_PAYMENT], true) || $booked['cancel_at'] !== null) {
            throw HttpException::notFound();
        }

        return $this->render($request, 'customer/services/addon_cancel.twig', ['service' => $service, 'addon' => $booked]);
    }

    public function cancel(Request $request, int $id, int $addon): Response
    {
        $service = $this->find($request, $id);
        $this->ensureMayManage($request);
        $data = $this->validate($request, ['confirm' => 'bool']);
        if ($data['confirm'] !== true) {
            throw ValidationException::withMessages(['confirm' => 'Please confirm the cancellation.']);
        }

        try {
            $end = $this->addonService->cancel($service, $addon, $this->user($request)->id, $request->clientIp());
        } catch (ServiceChangeException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/services/' . $id);
        }
        $this->flash($request, 'success', $end === null
            ? 'The booking has been withdrawn and its invoice cancelled.'
            : 'The add-on has been cancelled for the end of the paid term. We have sent you a confirmation by e-mail.');

        return $this->redirect('/services/' . $id);
    }

    public function withdrawCancellation(Request $request, int $id, int $addon): Response
    {
        $service = $this->find($request, $id);
        $this->ensureMayManage($request);
        try {
            $this->addonService->withdrawCancellation($service, $addon, $this->user($request)->id, $request->clientIp());
            $this->flash($request, 'success', 'The cancellation of the add-on has been taken back.');
        } catch (ServiceChangeException $exception) {
            $this->flash($request, 'error', $exception->getMessage());
        }

        return $this->redirect('/services/' . $id);
    }

    public function changeCycle(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        $this->ensureMayManage($request);
        $data = $this->validate($request, ['billing_cycle' => 'required|string|max:20']);
        if (!$this->limiter->attempt('cycle-change:' . $this->user($request)->id, 10, 3600)) {
            throw HttpException::tooManyRequests();
        }
        try {
            $this->cycles->request($service, (string) $data['billing_cycle'], $this->user($request)->id, $request->clientIp());
            $this->flash($request, 'success', 'The new billing cycle applies from the next renewal.');
        } catch (ServiceChangeException $exception) {
            $this->flash($request, 'error', $exception->getMessage());
        }

        return $this->redirect('/services/' . $id);
    }

    public function withdrawCycle(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        $this->ensureMayManage($request);
        try {
            $this->cycles->withdraw($service, $this->user($request)->id, $request->clientIp());
            $this->flash($request, 'success', 'The change of the billing cycle has been taken back.');
        } catch (ServiceChangeException $exception) {
            $this->flash($request, 'error', $exception->getMessage());
        }

        return $this->redirect('/services/' . $id);
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
