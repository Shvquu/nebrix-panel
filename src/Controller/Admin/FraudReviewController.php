<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use Nebrix\Authorization\Permission;
use Nebrix\Billing\FraudCheck;
use Nebrix\Http\Controller;
use Nebrix\Http\Request;
use Nebrix\Http\Response;

/**
 * Orders held by the fraud check: staff approve (the order is set up) or
 * reject them (nothing is set up; a refund is issued through the payment).
 */
final class FraudReviewController extends Controller
{
    public function __construct(private readonly FraudCheck $fraud)
    {
    }

    public function index(Request $request): Response
    {
        $this->authorize($request, Permission::ORDERS_VIEW);

        return $this->render($request, 'admin/fraud/index.twig', ['orders' => $this->fraud->queue()]);
    }

    public function approve(Request $request, int $id): Response
    {
        return $this->decide($request, $id, true);
    }

    public function reject(Request $request, int $id): Response
    {
        return $this->decide($request, $id, false);
    }

    private function decide(Request $request, int $id, bool $approve): Response
    {
        $this->authorize($request, Permission::ORDERS_MANAGE);
        if (!$this->fraud->decide($id, $approve, $this->user($request)->id)) {
            $this->flash($request, 'error', 'This order is not waiting for review.');

            return $this->redirect('/admin/orders/review');
        }
        $this->audit($request, $approve ? 'order.fraud_approved' : 'order.fraud_rejected', 'order', $id);
        $this->flash($request, 'success', $approve ? 'The order has been approved and is being set up.' : 'The order has been rejected. Refund the payment under Payments if necessary.');

        return $this->redirect('/admin/orders/review');
    }
}
