<?php

declare(strict_types=1);

namespace Nebrix\Controller;

use Nebrix\Http\Controller;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\RateLimiter;
use Nebrix\Support\InboundMailProcessor;
use Nebrix\Support\TooManyInboundMails;
use Psr\Log\LoggerInterface;

/**
 * Inbound e-mail webhook: replies to ticket notifications become ticket
 * replies. Exempt from CSRF because every request is authenticated by the
 * HMAC signature (shared secret) over timestamp and body; see
 * InboundMailProcessor for the checks.
 *
 * Answers: 404 while the feature is off, 413 body too large, 429 rate
 * limited (retry later), 401 signature/timestamp invalid, 422 malformed,
 * 200 with {"status": "accepted" | "duplicate" | "ignored", "reason"}.
 */
final class InboundMailController extends Controller
{
    public function __construct(
        private readonly InboundMailProcessor $processor,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function receive(Request $request): Response
    {
        if (!$this->processor->enabled()) {
            return Response::json(['error' => 'not found'], 404);
        }
        $body = $request->body();
        if (strlen($body) > InboundMailProcessor::MAX_BODY) {
            return Response::json(['error' => 'payload too large'], 413);
        }
        $network = RateLimiter::network($request->clientIp());
        if (!$this->limiter->attempt('webhook:inbound-mail:' . $network, 120, 60)) {
            return Response::json(['error' => 'too many requests'], 429);
        }
        if (!$this->processor->verifySignature($body, $request->header('X-Nebrix-Timestamp'), $request->header('X-Nebrix-Signature'))) {
            // Logged at most 10 times per hour and network, so forged requests cannot flood the log.
            if ($this->limiter->attempt('webhook:inbound-mail-invalid-log:' . $network, 10, 3600)) {
                $this->logger->warning('Inbound e-mail webhook: invalid signature or timestamp', ['ip' => $request->clientIp()]);
            }

            return Response::json(['error' => 'invalid signature'], 401);
        }

        try {
            $mail = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return Response::json(['error' => 'invalid JSON'], 422);
        }
        if (!is_array($mail)) {
            return Response::json(['error' => 'invalid payload'], 422);
        }

        try {
            return Response::json($this->processor->process($mail));
        } catch (\InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        } catch (TooManyInboundMails) {
            $response = Response::json(['error' => 'too many requests'], 429);
            $response->setHeader('Retry-After', '600');

            return $response;
        }
    }
}
