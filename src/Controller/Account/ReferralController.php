<?php

declare(strict_types=1);

namespace Nebrix\Controller\Account;

use Nebrix\Auth\User;
use Nebrix\Billing\Money;
use Nebrix\Core\Config;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Referral\ReferralService;
use Nebrix\Settings\SettingsRepository;

/**
 * "Refer a friend": the public referral link and the customer's page.
 */
final class ReferralController extends Controller
{
    public function __construct(
        private readonly ReferralService $referrals,
        private readonly SettingsRepository $settings,
        private readonly Config $config,
    ) {
    }

    /**
     * /r/{code}: remembers the code for the registration (cookie, 30 days)
     * and leads to the registration form. Signed-in visitors simply go to
     * their dashboard. Unknown codes are not remembered.
     */
    public function visit(Request $request, string $code): Response
    {
        if ($request->attribute('user') instanceof User) {
            return $this->redirect('/dashboard');
        }
        $code = ReferralService::normalize($code);
        $response = $this->redirect('/register');
        if ($this->referrals->referrerByCode($code) !== null) {
            $response->withCookie(ReferralService::COOKIE, $code, [
                'expires' => time() + ReferralService::COOKIE_DAYS * 86400,
                'secure' => $this->config->bool('session.secure_cookie') || $request->isSecure(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return $response;
    }

    public function index(Request $request): Response
    {
        if (!$this->referrals->enabled()) {
            throw HttpException::notFound();
        }
        $user = $this->user($request);
        $code = $this->referrals->codeFor($user->id);
        $currency = $this->settings->string('general.default_currency') ?: 'EUR';
        $amount = static function (string $value) use ($currency): int {
            try {
                return max(0, Money::parse($value, $currency));
            } catch (\InvalidArgumentException) {
                return 0;
            }
        };

        return $this->render($request, 'account/referrals.twig', [
            'code' => $code,
            'link' => $this->referrals->link($code),
            'overview' => $this->referrals->overview($user->id),
            'currency' => $currency,
            'reward' => $amount($this->settings->string('referral.reward')),
            'welcome' => $amount($this->settings->string('referral.welcome')),
            'minimum' => $amount($this->settings->string('referral.min_amount')),
            'holding_days' => $this->settings->int('referral.holding_days'),
            'monthly_limit' => $this->settings->int('referral.monthly_limit'),
        ]);
    }
}
