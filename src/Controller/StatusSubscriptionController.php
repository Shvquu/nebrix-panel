<?php

declare(strict_types=1);

namespace Nebrix\Controller;

use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Http\SecurityHeaders;
use Nebrix\Security\Captcha;
use Nebrix\Security\RateLimiter;
use Nebrix\Status\StatusSubscriptions;
use Nebrix\View\Translator;

/**
 * E-mail subscriptions to the status page: subscribe (double opt-in,
 * CAPTCHA when configured, rate-limited), confirm and one-click
 * unsubscribe. Confirming and unsubscribing need a click on a button –
 * mail scanners that open links do not change anything – except the
 * RFC 8058 one-click POST, which the unsubscribe token authenticates.
 */
final class StatusSubscriptionController extends Controller
{
    public function __construct(
        private readonly StatusSubscriptions $subscriptions,
        private readonly Captcha $captcha,
        private readonly RateLimiter $limiter,
        private readonly Translator $translator,
    ) {
    }

    public function form(Request $request): Response
    {
        $this->ensureEnabled();

        return $this->withCaptcha($this->render($request, 'status/subscribe.twig', ['captcha' => $this->captcha->widget(), 'sent' => false]));
    }

    public function subscribe(Request $request): Response
    {
        $this->ensureEnabled();
        if (!$this->limiter->attempt('status-subscribe:' . RateLimiter::network($request->clientIp(), 48), 10, 3600)) {
            throw HttpException::tooManyRequests();
        }
        $data = $this->validate($request, ['email' => 'required|email|max:190']);
        $this->captcha->verify($request);
        $this->subscriptions->subscribe((string) $data['email'], $this->translator->locale());

        // The same answer for new, pending and already confirmed addresses.
        return $this->render($request, 'status/subscribe.twig', ['captcha' => null, 'sent' => true]);
    }

    public function confirmForm(Request $request, string $token): Response
    {
        $this->ensureEnabled();

        return $this->render($request, 'status/confirm.twig', ['token' => $token, 'valid' => $this->subscriptions->pending($token) !== null, 'done' => false]);
    }

    public function confirm(Request $request): Response
    {
        $this->ensureEnabled();
        if (!$this->limiter->attempt('status-confirm:' . RateLimiter::network($request->clientIp()), 30, 3600)) {
            throw HttpException::tooManyRequests();
        }
        $token = $request->string('token');
        $confirmed = $this->subscriptions->confirm($token);

        return $this->render($request, 'status/confirm.twig', ['token' => '', 'valid' => $confirmed, 'done' => $confirmed], $confirmed ? 200 : 404);
    }

    public function unsubscribeForm(Request $request, int $id, string $token): Response
    {
        return $this->render($request, 'status/unsubscribe.twig', ['id' => $id, 'token' => $token, 'valid' => $this->subscriptions->forUnsubscribe($id, $token) !== null, 'done' => false]);
    }

    /** Button on the page and RFC 8058 one-click (List-Unsubscribe-Post) from mail clients. */
    public function unsubscribe(Request $request, int $id, string $token): Response
    {
        if (!$this->limiter->attempt('status-unsubscribe:' . RateLimiter::network($request->clientIp()), 60, 3600)) {
            throw HttpException::tooManyRequests();
        }
        $done = $this->subscriptions->unsubscribe($id, $token);
        if (str_contains((string) $request->header('Content-Type'), 'application/x-www-form-urlencoded') && $request->string('List-Unsubscribe') === 'One-Click') {
            return Response::text($done ? 'unsubscribed' : 'not found', $done ? 200 : 404);
        }

        return $this->render($request, 'status/unsubscribe.twig', ['id' => $id, 'token' => '', 'valid' => $done, 'done' => $done], $done ? 200 : 404);
    }

    private function ensureEnabled(): void
    {
        if (!$this->subscriptions->enabled()) {
            throw HttpException::notFound();
        }
    }

    private function withCaptcha(Response $response): Response
    {
        if ($this->captcha->enabled()) {
            $response->setHeader('Content-Security-Policy', SecurityHeaders::contentSecurityPolicy($this->captcha->cspSources()));
        }

        return $response;
    }
}
