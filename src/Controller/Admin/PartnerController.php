<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use InvalidArgumentException;
use Nebrix\Finance\Partner\PartnerService;
use Nebrix\Http\Controller;
use Nebrix\Http\DocumentResponse;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;

/**
 * Finance → Partners (permission partners.manage): partners, commissions,
 * payout approval, payment and SEPA export. Staff cannot process their own
 * payouts; every action is audit-logged.
 */
final class PartnerController extends Controller
{
    public function __construct(private readonly PartnerService $partners)
    {
    }

    public function index(Request $request): Response
    {
        return $this->render($request, 'admin/finance/partners.twig', [
            'partners' => $this->partners->all(),
            'payouts' => $this->partners->allPayouts(),
            'enabled' => $this->partners->enabled(),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $partner = $this->partners->partner($id) ?? throw HttpException::notFound();
        $user = $this->container->get(\Nebrix\Repository\UserRepository::class)->find($id) ?? throw HttpException::notFound();

        return $this->render($request, 'admin/finance/partner.twig', [
            'partner' => $partner,
            'customer' => $user,
            'balance' => $this->partners->balance($id),
            'commissions' => $this->partners->commissions($id, null, null, 500, true),
            'payouts' => $this->partners->allPayouts($id),
        ]);
    }

    public function status(Request $request, int $id): Response
    {
        $this->partners->partner($id) ?? throw HttpException::notFound();
        $data = $this->validate($request, ['status' => 'required|in:active,suspended']);
        $this->partners->setStatus($id, (string) $data['status'], $this->user($request)->id, $request->clientIp());
        $this->flash($request, 'success', 'The partner status has been changed.');

        return $this->redirect('/admin/partners/' . $id);
    }

    public function approve(Request $request, int $id): Response
    {
        return $this->act($request, fn () => $this->partners->approve($id, $this->user($request)->id, $request->clientIp()), 'The payout has been approved. Transfer the amount and mark it as paid.');
    }

    public function reject(Request $request, int $id): Response
    {
        $data = $this->validate($request, ['note' => 'required|string|max:255']);

        return $this->act($request, fn () => $this->partners->reject($id, $this->user($request)->id, (string) $data['note'], $request->clientIp()), 'The payout has been rejected; the commissions are payable again.');
    }

    public function markPaid(Request $request, int $id): Response
    {
        $data = $this->validate($request, ['bank_reference' => 'nullable|string|max:100']);

        return $this->act($request, fn () => $this->partners->markPaid($id, $this->user($request)->id, trim((string) ($data['bank_reference'] ?? '')), $request->clientIp()), 'The payout has been marked as paid.');
    }

    /** SEPA credit transfer file of the approved payouts that were not exported yet. */
    public function sepa(Request $request): Response
    {
        try {
            $result = $this->partners->sepaExport([], $this->user($request)->id, $request->clientIp());
        } catch (InvalidArgumentException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/admin/partners');
        }

        return DocumentResponse::make($result['xml'], $result['message_id'] . '.xml', 'application/xml');
    }

    private function act(Request $request, callable $action, string $success): Response
    {
        try {
            $action();
            $this->flash($request, 'success', $success);
        } catch (InvalidArgumentException $exception) {
            $this->flash($request, 'error', $exception->getMessage());
        }

        return $this->redirect('/admin/partners');
    }
}
