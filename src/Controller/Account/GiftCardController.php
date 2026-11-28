<?php

declare(strict_types=1);

namespace Nebrix\Controller\Account;

use Nebrix\Billing\Money;
use Nebrix\Finance\GiftCards\GiftCardException;
use Nebrix\Finance\GiftCards\GiftCardSales;
use Nebrix\Finance\GiftCards\GiftCardService;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\ValidationException;

/**
 * Customer area → Gift cards: redeem a code (account credit) and buy gift
 * cards (purchase invoice; the code is e-mailed after the payment).
 */
final class GiftCardController extends Controller
{
    public function __construct(
        private readonly GiftCardService $giftCards,
        private readonly GiftCardSales $sales,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->giftCards->redeemEnabled()) {
            throw HttpException::notFound();
        }
        $user = $this->user($request);
        [$min, $max] = $this->sales->limits();

        return $this->render($request, 'account/gift_cards.twig', [
            'cards' => $this->giftCards->forCustomer($user->id),
            'sale_enabled' => $this->sales->enabled(),
            'min' => $min,
            'max' => $max,
            'currency' => $this->sales->currency(),
        ]);
    }

    public function redeem(Request $request): Response
    {
        if (!$this->giftCards->redeemEnabled()) {
            throw HttpException::notFound();
        }
        $data = $this->validate($request, ['code' => 'required|string|max:40']);
        $user = $this->user($request);
        try {
            $this->giftCards->redeem($user, (string) $data['code'], $request->clientIp());
        } catch (GiftCardException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }
        $this->flash($request, 'success', 'The gift card has been redeemed. The value was added to your account credit.');

        return $this->redirect('/account/gift-cards');
    }

    public function buy(Request $request): Response
    {
        if (!$this->sales->enabled()) {
            throw HttpException::notFound();
        }
        $data = $this->validate($request, ['value' => 'required|regex:/^\d{1,6}([.,]\d{1,2})?$/', 'accept_terms' => 'bool']);
        if ($data['accept_terms'] !== true) {
            throw ValidationException::withMessages(['accept_terms' => 'Please confirm that you have read the notes on gift cards.']);
        }
        $user = $this->user($request);
        try {
            $invoiceId = $this->sales->createInvoice($user, Money::parse((string) $data['value'], $this->sales->currency()));
        } catch (GiftCardException $exception) {
            throw ValidationException::withMessages(['value' => $exception->getMessage()]);
        }
        $this->audit($request, 'gift_card.purchase_requested', 'invoice', $invoiceId);

        return $this->redirect('/invoices/' . $invoiceId);
    }
}
