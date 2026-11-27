<?php

declare(strict_types=1);

namespace Nebrix\Controller\Auth;

use Nebrix\Auth\AuthenticationException;
use Nebrix\Auth\AuthService;
use Nebrix\Http\Controller;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Http\SecurityHeaders;
use Nebrix\Referral\ReferralService;
use Nebrix\Security\Captcha;
use Nebrix\Security\ValidationException;
use Nebrix\Settings\SettingsRepository;

final class RegisterController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly SettingsRepository $settings,
        private readonly Captcha $captcha,
        private readonly ReferralService $referrals,
    ) {
    }

    public function show(Request $request): Response
    {
        if (!$this->settings->bool('auth.registration_enabled')) {
            $this->flash($request, 'info', 'Registration is currently disabled.');

            return $this->redirect('/login');
        }

        $response = $this->render($request, 'auth/register.twig', [
            'require_legal' => $this->settings->bool('auth.require_legal_acceptance'),
            'captcha' => $this->captcha->widget(),
            'referrals' => $this->referrals->enabled(),
            // Remembered from the referral link (/r/{code}); the visitor sees and can remove it.
            'referral_code' => $this->rememberedCode($request),
        ]);
        if ($this->captcha->enabled()) {
            $response->setHeader('Content-Security-Policy', SecurityHeaders::contentSecurityPolicy($this->captcha->cspSources()));
        }

        return $response;
    }

    public function register(Request $request): Response
    {
        $rules = [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'company' => 'nullable|string|max:150',
            'email' => 'required|email|max:254',
            'password' => 'required|string|min:10|max:256|confirmed',
            'referral_code' => 'nullable|string|max:20',
        ];
        if ($this->settings->bool('auth.require_legal_acceptance')) {
            $rules['accept_terms'] = 'bool';
        }
        $data = $this->validate($request, $rules, ['accept_terms' => 'terms']);
        $this->captcha->verify($request);

        $referralCode = $this->referrals->enabled() ? ReferralService::normalize((string) ($data['referral_code'] ?? '')) : '';
        if ($referralCode !== '' && $this->referrals->referrerByCode($referralCode) === null) {
            throw new ValidationException(
                ['referral_code' => ['This referral code is not valid.']],
                \Nebrix\Security\Validator::safeInput($request->all()),
            );
        }

        if (($rules['accept_terms'] ?? null) !== null && $data['accept_terms'] !== true) {
            throw new ValidationException(
                ['accept_terms' => ['Please accept the terms and the privacy policy.']],
                \Nebrix\Security\Validator::safeInput($request->all()),
            );
        }

        try {
            $user = $this->auth->register($request, [
                'email' => (string) $data['email'],
                'password' => (string) $data['password'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'company' => $data['company'],
                'referral_code' => $referralCode,
            ]);
        } catch (AuthenticationException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/register');
        }

        if ($user->isActive()) {
            $this->flash($request, 'success', 'Your account has been created. You can sign in now.');
        } else {
            $this->flash($request, 'success', 'Your account has been created. Please confirm your e-mail address using the link we sent you.');
        }

        $response = $this->redirect('/login');
        if ($request->cookie(ReferralService::COOKIE) !== null) {
            $response->withCookie(ReferralService::COOKIE, '', ['expires' => time() - 3600]);
        }

        return $response;
    }

    private function rememberedCode(Request $request): string
    {
        $code = ReferralService::normalize((string) $request->cookie(ReferralService::COOKIE));

        return ReferralService::isValidCode($code) && $this->referrals->referrerByCode($code) !== null ? $code : '';
    }
}
