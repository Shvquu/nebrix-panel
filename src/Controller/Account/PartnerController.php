<?php

declare(strict_types=1);

namespace Nebrix\Controller\Account;

use InvalidArgumentException;
use Nebrix\Core\Clock;
use Nebrix\Finance\Partner\PartnerService;
use Nebrix\Http\Controller;
use Nebrix\Http\DocumentResponse;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Referral\ReferralService;
use Nebrix\Security\RateLimiter;
use Nebrix\Security\ValidationException;
use Nebrix\Settings\SettingsRepository;

/**
 * Customer area → Partner program: join with consent, payout details,
 * commissions, payout requests and the yearly statement. Every action works
 * on the signed-in customer's own partner account only.
 */
final class PartnerController extends Controller
{
    public function __construct(
        private readonly PartnerService $partners,
        private readonly ReferralService $referrals,
        private readonly SettingsRepository $settings,
        private readonly RateLimiter $limiter,
        private readonly Clock $clock,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $this->user($request);
        $partner = $this->partners->partner($user->id);

        return $this->render($request, 'account/partner.twig', [
            'partner' => $partner,
            'balance' => $partner !== null ? $this->partners->balance($user->id) : null,
            'commissions' => $partner !== null ? $this->partners->commissions($user->id) : [],
            'payouts' => $partner !== null ? $this->partners->payouts($user->id) : [],
            'link' => $partner !== null ? $this->referrals->link($this->referrals->codeFor($user->id)) : null,
            'rate' => $this->partners->rateBp(),
            'months' => $this->settings->int('partner.commission_months'),
            'holding_days' => $this->settings->int('partner.holding_days'),
            'minimum' => $this->partners->minimumPayout(),
            'currency' => $this->partners->currency(),
            'terms_url' => $this->settings->string('partner.terms_url'),
            'years' => range((int) $this->clock->now()->format('Y'), max(2026, (int) $this->clock->now()->format('Y') - 9)),
        ]);
    }

    public function join(Request $request): Response
    {
        $this->ensureEnabled();
        $data = $this->validate($request, ['accept_terms' => 'bool']);
        if ($data['accept_terms'] !== true) {
            throw ValidationException::withMessages(['accept_terms' => 'Please accept the partner terms.']);
        }
        try {
            $this->partners->join($this->user($request), $request->clientIp());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['accept_terms' => $exception->getMessage()]);
        }
        $this->flash($request, 'success', 'Welcome to the partner program.');

        return $this->redirect('/account/partner');
    }

    public function details(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $this->user($request);
        if (!$this->limiter->attempt('partner-details:' . $user->id, 10, 3600)) {
            throw HttpException::tooManyRequests();
        }
        $data = $this->validate($request, ['iban' => 'required|string|max:42', 'holder' => 'required|string|max:70', 'bic' => 'nullable|string|max:11']);
        try {
            $this->partners->saveDetails($user->id, (string) $data['iban'], (string) $data['holder'], (string) ($data['bic'] ?? ''), $request->clientIp());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['iban' => $exception->getMessage()]);
        }
        $this->flash($request, 'success', 'Your payout details have been saved.');

        return $this->redirect('/account/partner');
    }

    public function requestPayout(Request $request): Response
    {
        $this->ensureEnabled();
        try {
            $this->partners->requestPayout($this->user($request)->id, $request->clientIp());
        } catch (InvalidArgumentException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/account/partner');
        }
        $this->flash($request, 'success', 'Your payout has been requested. We will transfer the amount after checking it.');

        return $this->redirect('/account/partner');
    }

    public function statement(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $this->user($request);
        if ($this->partners->partner($user->id) === null) {
            throw HttpException::notFound();
        }
        $year = (string) $request->query('year', $this->clock->now()->format('Y'));
        if (preg_match('/^20\d{2}$/', $year) !== 1) {
            throw HttpException::badRequest();
        }

        return DocumentResponse::make($this->partners->statementCsv($user->id, (int) $year), 'partner-statement-' . $year . '.csv', 'text/csv; charset=utf-8');
    }

    private function ensureEnabled(): void
    {
        if (!$this->partners->enabled()) {
            throw HttpException::notFound();
        }
    }
}
