<?php

declare(strict_types=1);

namespace Nebrix\Controller\Account;

use Nebrix\Discord\DiscordLinkException;
use Nebrix\Discord\DiscordService;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\RateLimiter;

/**
 * Discord account link of the signed-in customer (OAuth2 authorization
 * code flow with a session-bound state).
 */
final class DiscordController extends Controller
{
    public function __construct(
        private readonly DiscordService $discord,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function connect(Request $request): Response
    {
        if (!$this->discord->isEnabled()) {
            throw HttpException::notFound();
        }
        if (!$this->limiter->attempt('discord-link:' . $this->user($request)->id, 10, 3600)) {
            throw HttpException::tooManyRequests();
        }

        // Discord's authorization page; the URL is built from fixed parts.
        return Response::redirect($this->discord->startLink($request->session()));
    }

    public function callback(Request $request): Response
    {
        $user = $this->user($request);
        if ($request->string('error') !== '') {
            $request->session()->forget('_discord_oauth_state');
            $this->flash($request, 'warning', 'The Discord link was cancelled.');

            return $this->redirect('/dashboard');
        }

        try {
            $this->discord->completeLink($user, $request->session(), $request->string('code'), $request->string('state'), $request->clientIp());
            $this->flash($request, 'success', 'Your Discord account has been linked.');
        } catch (DiscordLinkException $exception) {
            $this->flash($request, 'error', $exception->getMessage());
        }

        return $this->redirect('/dashboard');
    }

    public function unlink(Request $request): Response
    {
        $user = $this->user($request);
        if ($this->discord->unlink($user->id, $user->id, $request->clientIp())) {
            $this->flash($request, 'success', 'Your Discord account has been unlinked.');
        }

        return $this->redirect('/dashboard');
    }
}
