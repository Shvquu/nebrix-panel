<?php

declare(strict_types=1);

namespace Nebrix\Controller;

use Nebrix\Http\Controller;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Payment\PayPal\PayPalGateway;
use Nebrix\Payment\Stripe\StripeGateway;

/**
 * Payment provider webhooks. These routes are exempt from CSRF checks
 * because every request is authenticated by the provider's signature.
 */
final class WebhookController extends Controller
{
    private const MAX_BODY = 512 * 1024;

    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly PayPalGateway $paypal,
        private readonly \Nebrix\Security\RateLimiter $limiter,
    ) {
    }

    public function stripe(Request $request): Response
    {
        if (strlen($request->body()) > self::MAX_BODY) {
            return Response::json(['error' => 'payload too large'], 413);
        }
        if (!$this->withinLimit($request, 'stripe')) {
            return Response::json(['error' => 'too many requests'], 429);
        }

        return $this->stripe->handleWebhook($request);
    }

    public function paypal(Request $request): Response
    {
        if (strlen($request->body()) > self::MAX_BODY) {
            return Response::json(['error' => 'payload too large'], 413);
        }
        if (!$this->withinLimit($request, 'paypal')) {
            return Response::json(['error' => 'too many requests'], 429);
        }

        return $this->paypal->handleWebhook($request);
    }

    /**
     * Providers send far fewer requests than this per sender address; floods of forged
     * requests are cut off before any signature check or outbound verification call.
     * A provider that receives 429 retries later.
     */
    private function withinLimit(Request $request, string $gateway): bool
    {
        return $this->limiter->attempt('webhook:' . $gateway . ':' . \Nebrix\Security\RateLimiter::network($request->clientIp()), 120, 60);
    }
}
