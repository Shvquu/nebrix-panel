<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Authorization\Permission;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Referral\ReferralService;

/**
 * Referrals (Customers → Referrals). Viewing needs customers.view;
 * approving held referrals and rejecting needs customers.manage.
 */
final class ReferralController extends Controller
{
    public function __construct(
        private readonly ReferralService $referrals,
    ) {
    }

    public function index(Request $request): Response
    {
        $status = $request->string('status');
        $status = in_array($status, ReferralService::STATUSES, true) ? $status : null;

        return $this->render($request, 'admin/referrals/index.twig', [
            'referrals' => $this->referrals->all($status),
            'status' => $status,
            'statuses' => ReferralService::STATUSES,
            'enabled' => $this->referrals->enabled(),
        ]);
    }

    public function approve(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::CUSTOMERS_MANAGE);
        $this->referrals->find($id) ?? throw HttpException::notFound();
        if ($this->referrals->approve($id, $this->user($request)->id, $request->clientIp())) {
            $this->flash($request, 'success', 'The referral was approved. It is rewarded once the customer has paid.');
        } else {
            $this->flash($request, 'warning', 'Only referrals under review can be approved.');
        }

        return $this->redirect('/admin/referrals');
    }

    public function reject(Request $request, int $id): Response
    {
        $this->authorize($request, Permission::CUSTOMERS_MANAGE);
        $this->referrals->find($id) ?? throw HttpException::notFound();
        $data = $this->validate($request, ['reason' => 'nullable|string|max:200']);
        if ($this->referrals->reject($id, $this->user($request)->id, $request->clientIp(), trim((string) ($data['reason'] ?? '')))) {
            $this->flash($request, 'success', 'The referral was rejected.');
        } else {
            $this->flash($request, 'warning', 'Rewarded or already rejected referrals cannot be changed.');
        }

        return $this->redirect('/admin/referrals');
    }
}
