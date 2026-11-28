<?php

declare(strict_types=1);

namespace Nebrix\Controller\Marketing;

use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Http\SecurityHeaders;
use Nebrix\Marketing\Newsletter;
use Nebrix\Marketing\Seo;
use Nebrix\Marketing\SiteContext;
use Nebrix\Security\Captcha;
use Nebrix\Security\RateLimiter;
use Nebrix\Settings\SettingsRepository;
use Nebrix\View\Translator;

/**
 * Newsletter on the website: sign-up (double opt-in, same answer for every
 * address, rate-limited, CAPTCHA when configured), confirmation and
 * unsubscribing.
 *
 * Confirmation and unsubscribe links open a page with a button, so that
 * link scanners of mail providers cannot confirm or unsubscribe anything.
 * The unsubscribe POST also serves RFC 8058 one-click requests of mail
 * clients ("List-Unsubscribe=One-Click"), which carry no CSRF token: the
 * 256-bit token in the address authenticates it. Unsubscribing always
 * works, also when the newsletter or the website is switched off.
 */
final class NewsletterController extends Controller
{
    /** Sign-ups per client network (IPv4 address or IPv6 /64) and hour. */
    private const SIGNUPS_PER_NETWORK = 10;

    public function __construct(
        private readonly Newsletter $newsletter,
        private readonly SiteContext $site,
        private readonly Seo $seo,
        private readonly Captcha $captcha,
        private readonly RateLimiter $limiter,
        private readonly Translator $translator,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function show(Request $request): Response
    {
        $this->assertSignupAvailable();
        $response = $this->render($request, 'website/newsletter.twig', [
            'consent_text' => $this->consentText(),
            'captcha' => $this->captcha->widget(),
            'seo' => $this->seo->page('/newsletter', [], ['description' => $this->translator->translate('News, offers and maintenance tips from :brand – unsubscribe at any time.', ['brand' => $this->settings->string('general.company_name')])]),
        ] + $this->site->layout());
        if ($this->captcha->enabled()) {
            $response->setHeader('Content-Security-Policy', SecurityHeaders::contentSecurityPolicy($this->captcha->cspSources()));
        }

        return $response;
    }

    public function subscribe(Request $request): Response
    {
        $this->assertSignupAvailable();
        $data = $this->validate($request, ['email' => 'required|email|max:254'], ['email' => 'e-mail address']);
        if (!$this->limiter->attempt('newsletter-signup:' . RateLimiter::network($request->clientIp()), self::SIGNUPS_PER_NETWORK, 3600)) {
            $this->flash($request, 'error', 'Too many sign-ups from your network. Please try again later.');

            return $this->redirect('/newsletter');
        }
        $this->captcha->verify($request);
        $this->newsletter->subscribe((string) $data['email'], $this->translator->locale(), $request->clientIp(), $this->consentText());
        // The same answer for every address: nobody learns whether an address is subscribed.
        $this->flash($request, 'success', 'Thank you! If the address can receive our newsletter, we have sent an e-mail with a confirmation link. Your subscription starts when you confirm it.');

        return $this->redirect('/newsletter');
    }

    public function confirmForm(Request $request, string $token): Response
    {
        if (!$this->newsletter->enabled()) {
            throw HttpException::notFound();
        }
        $valid = $this->newsletter->findPending($token) !== null;

        return $this->notice($request, 'newsletter_confirm', $valid ? 'confirm' : 'invalid', '/newsletter/confirm', $valid ? $token : null, $valid ? 200 : 404);
    }

    public function confirm(Request $request): Response
    {
        if (!$this->newsletter->enabled()) {
            throw HttpException::notFound();
        }
        $confirmed = $this->newsletter->confirm($request->string('token'), $request->clientIp());

        return $this->notice($request, 'newsletter_confirm', $confirmed ? 'done' : 'invalid', '/newsletter/confirm', null, $confirmed ? 200 : 404);
    }

    public function unsubscribeForm(Request $request, string $token): Response
    {
        $valid = $this->newsletter->findByUnsubscribeToken($token) !== null;

        return $this->notice($request, 'newsletter_unsubscribe', $valid ? 'confirm' : 'invalid', '/newsletter/unsubscribe/' . $token, null, $valid ? 200 : 404);
    }

    public function unsubscribe(Request $request, string $token): Response
    {
        $oneClick = $request->string('List-Unsubscribe') === 'One-Click';
        if (!$this->newsletter->unsubscribeByToken($token, $request->clientIp())) {
            if ($oneClick) {
                throw HttpException::notFound();
            }

            return $this->notice($request, 'newsletter_unsubscribe', 'invalid', '', null, 404);
        }

        return $oneClick ? Response::text('OK') : $this->notice($request, 'newsletter_unsubscribe', 'done', '', null);
    }

    /** The text the subscriber agrees to (stored with the subscription as proof). */
    private function consentText(): string
    {
        return $this->translator->translate('I would like to receive the newsletter of :brand with news and offers by e-mail. I can unsubscribe at any time with the link in every newsletter.', ['brand' => $this->settings->string('general.company_name')]);
    }

    private function assertSignupAvailable(): void
    {
        if (!$this->site->websiteEnabled() || !$this->newsletter->enabled()) {
            throw HttpException::notFound();
        }
    }

    private function notice(Request $request, string $kind, string $state, string $action, ?string $token, int $status = 200): Response
    {
        return $this->render($request, 'website/marketing_notice.twig', [
            'kind' => $kind,
            'state' => $state,
            'action' => $action,
            'token' => $token,
            'seo' => $this->seo->page(null, [], ['noindex' => true]),
        ] + $this->site->layout(), $status);
    }
}
