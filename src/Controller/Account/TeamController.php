<?php

declare(strict_types=1);

namespace Nebrix\Controller\Account;

use Nebrix\Account\TeamException;
use Nebrix\Account\TeamService;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\RateLimiter;
use Nebrix\Service\ServiceRepository;

/**
 * Team access: the account owner invites and manages members; members see
 * their memberships and can leave. Invitations are accepted by the signed-in
 * user whose verified e-mail address matches the invitation.
 */
final class TeamController extends Controller
{
    public function __construct(
        private readonly TeamService $team,
        private readonly ServiceRepository $services,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        return $this->render($request, 'account/team.twig', [
            'members' => $this->team->members($user->id),
            'memberships' => $this->team->memberships($user->id),
            'services' => $this->services->forUser($user->id),
            'permissions' => TeamService::PERMISSIONS,
        ]);
    }

    public function invite(Request $request): Response
    {
        $user = $this->user($request);
        $data = $this->validate($request, ['email' => 'required|email|max:190']);
        if (!$this->limiter->attempt('team-invite:' . $user->id, 10, 3600)) {
            throw HttpException::tooManyRequests();
        }
        try {
            $id = $this->team->invite($user, (string) $data['email'], $this->listInput($request, 'permissions'), $request->input('all_services') !== null, array_map('intval', $this->listInput($request, 'services')));
        } catch (TeamException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/account/team');
        }
        $this->audit($request, 'team.invited', 'account_member', $id);
        $this->flash($request, 'success', 'The invitation has been sent.');

        return $this->redirect('/account/team');
    }

    public function update(Request $request, int $id): Response
    {
        $user = $this->user($request);
        try {
            $this->team->update($user->id, $id, $this->listInput($request, 'permissions'), $request->input('all_services') !== null, array_map('intval', $this->listInput($request, 'services')));
        } catch (TeamException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/account/team');
        }
        $this->audit($request, 'team.updated', 'account_member', $id);
        $this->flash($request, 'success', 'The permissions have been saved.');

        return $this->redirect('/account/team');
    }

    public function remove(Request $request, int $id): Response
    {
        try {
            $this->team->revoke($this->user($request)->id, $id);
        } catch (TeamException) {
            throw HttpException::notFound();
        }
        $this->audit($request, 'team.removed', 'account_member', $id);
        $this->flash($request, 'success', 'The team member has been removed.');

        return $this->redirect('/account/team');
    }

    public function leave(Request $request, int $id): Response
    {
        try {
            $this->team->leave($this->user($request)->id, $id);
        } catch (TeamException) {
            throw HttpException::notFound();
        }
        $this->audit($request, 'team.left', 'account_member', $id);
        $this->flash($request, 'success', 'You have left the account.');

        return $this->redirect('/account/team');
    }

    public function invitation(Request $request, string $token): Response
    {
        $this->throttle($request);
        $invitation = $this->team->invitation($token);
        if ($invitation === null) {
            throw HttpException::notFound('This invitation is invalid or has expired.');
        }

        return $this->render($request, 'account/team_invitation.twig', [
            'invitation' => $invitation,
            'owner' => TeamService::ownerName($invitation),
            'token' => $token,
            'permissions' => TeamService::PERMISSIONS,
            'matches' => strcasecmp((string) $invitation['email'], $this->user($request)->email) === 0,
        ]);
    }

    public function accept(Request $request, string $token): Response
    {
        $this->throttle($request);
        try {
            $this->team->accept($token, $this->user($request));
        } catch (TeamException $exception) {
            $this->flash($request, 'error', $exception->getMessage());

            return $this->redirect('/account/team');
        }
        $this->audit($request, 'team.joined', 'user', $this->user($request)->id);
        $this->flash($request, 'success', 'You have joined the account. Shared services are listed under "My services".');

        return $this->redirect('/services');
    }

    /** Guessing invitation tokens is pointless (256 bit), but keep the lookups cheap. */
    private function throttle(Request $request): void
    {
        if (!$this->limiter->attempt('team-invitation:' . $this->user($request)->id, 30, 3600)) {
            throw HttpException::tooManyRequests();
        }
    }

    /** @return list<string> */
    private function listInput(Request $request, string $key): array
    {
        $value = $request->input($key, []);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
