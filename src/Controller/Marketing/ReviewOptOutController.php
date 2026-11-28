<?php

declare(strict_types=1);

namespace Nebrix\Controller\Marketing;

use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Marketing\ReviewInvitations;
use Nebrix\Marketing\Seo;
use Nebrix\Marketing\SiteContext;

/**
 * Opt-out link of the review invitation: withdraws the consent to review
 * invitations. The GET shows a button (link scanners must not withdraw
 * anything); the POST also serves RFC 8058 one-click requests of mail
 * clients, therefore it is not CSRF-protected – the 256-bit token in the
 * address authenticates it.
 */
final class ReviewOptOutController extends Controller
{
    public function __construct(
        private readonly ReviewInvitations $invitations,
        private readonly SiteContext $site,
        private readonly Seo $seo,
    ) {
    }

    public function show(Request $request, string $token): Response
    {
        $valid = $this->invitations->findByToken($token) !== null;

        return $this->notice($request, $valid ? 'confirm' : 'invalid', $token, $valid ? 200 : 404);
    }

    public function optOut(Request $request, string $token): Response
    {
        if (!$this->invitations->optOut($token, $request->clientIp())) {
            if ($this->isOneClick($request)) {
                throw HttpException::notFound();
            }

            return $this->notice($request, 'invalid', $token, 404);
        }
        if ($this->isOneClick($request)) {
            return Response::text('OK');
        }

        return $this->notice($request, 'done', $token);
    }

    private function isOneClick(Request $request): bool
    {
        return $request->string('List-Unsubscribe') === 'One-Click';
    }

    private function notice(Request $request, string $state, string $token, int $status = 200): Response
    {
        return $this->render($request, 'website/marketing_notice.twig', [
            'kind' => 'reviews',
            'state' => $state,
            'action' => '/reviews/opt-out/' . $token,
            'seo' => $this->seo->page(null, [], ['noindex' => true]),
        ] + $this->site->layout(), $status);
    }
}
