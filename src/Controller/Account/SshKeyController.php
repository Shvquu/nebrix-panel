<?php

declare(strict_types=1);

namespace Nebrix\Controller\Account;

use InvalidArgumentException;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Security\RateLimiter;
use Nebrix\Security\ValidationException;
use Nebrix\Server\SshKeys\SshKeyRepository;
use Nebrix\Server\SshKeys\SshPublicKey;

/**
 * Account → SSH keys: public keys the customer can install on new and
 * reinstalled servers. Only the owner sees, adds and deletes them.
 */
final class SshKeyController extends Controller
{
    public function __construct(
        private readonly SshKeyRepository $keys,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        return $this->render($request, 'account/ssh_keys.twig', [
            'keys' => $this->keys->forUser($user->id),
            'max_keys' => $this->keys->maxKeys(),
        ]);
    }

    public function store(Request $request): Response
    {
        $user = $this->user($request);
        $data = $this->validate($request, [
            'name' => 'required|string|max:100',
            'public_key' => 'required|string|max:16384',
        ], ['name' => 'Name', 'public_key' => 'Public key']);
        if (!$this->limiter->attempt('ssh-key-add:' . $user->id, 20, 3600)) {
            throw HttpException::tooManyRequests();
        }

        try {
            $key = SshPublicKey::parse((string) $data['public_key']);
            $id = $this->keys->add($user->id, (string) $data['name'], $key);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['public_key' => $exception->getMessage()]);
        }
        $this->audit($request, 'ssh_key.added', 'ssh_key', $id, ['type' => $key->type, 'bits' => $key->bits, 'fingerprint' => $key->fingerprint()]);
        $this->flash($request, 'success', 'The SSH key has been added.');

        return $this->redirect('/account/ssh-keys');
    }

    public function delete(Request $request, int $id): Response
    {
        $user = $this->user($request);
        $key = $this->keys->findForUser($id, $user->id) ?? throw HttpException::notFound();
        $this->keys->delete($id, $user->id);
        $this->audit($request, 'ssh_key.deleted', 'ssh_key', $id, ['fingerprint' => $key['fingerprint']]);
        $this->flash($request, 'success', 'The SSH key has been deleted. Servers that already have it keep it until you remove it there.');

        return $this->redirect('/account/ssh-keys');
    }
}
