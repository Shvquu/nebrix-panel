<?php

declare(strict_types=1);

namespace Nebrix\Controller;

use Nebrix\Auth\User;
use Nebrix\Contract\CancellationService;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Http\SecurityHeaders;
use Nebrix\Security\Captcha;
use Nebrix\Security\RateLimiter;
use Nebrix\Security\ValidationException;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;

/**
 * "Cancel contracts here" (§ 312k BGB) and "Withdraw from contract here"
 * (EU withdrawal function).
 *
 * Reachable without signing in and during maintenance. The page is the
 * confirmation page required by law: it asks for the type, identity,
 * contract, end date and the address for the confirmation, and has the
 * final button ("Cancel now" / "Confirm withdrawal"). After submitting, the
 * declaration is shown with its receipt time so it can be saved, and a
 * confirmation e-mail is sent immediately.
 */
final class ContractController extends Controller
{
    public function __construct(
        private readonly CancellationService $cancellations,
        private readonly ServiceRepository $services,
        private readonly RateLimiter $limiter,
        private readonly Captcha $captcha,
    ) {
    }

    public function cancelForm(Request $request): Response
    {
        return $this->form($request, false);
    }

    public function withdrawForm(Request $request): Response
    {
        return $this->form($request, true);
    }

    public function cancel(Request $request): Response
    {
        return $this->submit($request, false);
    }

    public function withdraw(Request $request): Response
    {
        return $this->submit($request, true);
    }

    private function form(Request $request, bool $withdrawal): Response
    {
        $user = $request->attribute('user');
        $user = $user instanceof User ? $user : null;
        $preselect = $request->query('service');

        $response = $this->render($request, 'contract/form.twig', [
            'withdrawal' => $withdrawal,
            'user' => $user,
            'services' => $user !== null ? $this->cancellable($user) : [],
            'preselect' => is_string($preselect) && ctype_digit($preselect) ? (int) $preselect : null,
            'captcha' => $user === null ? $this->captcha->widget() : null,
            'today' => $this->today(),
        ]);
        $this->withCaptchaPolicy($response, $user);

        return $response;
    }

    private function submit(Request $request, bool $withdrawal): Response
    {
        $user = $request->attribute('user');
        $user = $user instanceof User ? $user : null;

        // Per client network (an IPv6 /48 counts as one client).
        if (!$this->limiter->attempt('contract-declaration:' . RateLimiter::network($request->clientIp(), 48), 10, 3600)) {
            throw HttpException::tooManyRequests();
        }
        $data = $this->validate($request, [
            'kind' => $withdrawal ? 'nullable|string|max:20' : 'required|in:ordinary,extraordinary',
            'name' => 'required|string|max:200',
            'email' => 'required|email',
            'customer_reference' => 'nullable|string|max:255',
            'service_id' => 'nullable|int|min:1',
            'contract' => 'nullable|string|max:500',
            'reason' => 'nullable|string|max:5000',
            'effective' => 'nullable|in:earliest,date',
            'requested_date' => 'nullable|date',
        ]);
        $kind = $withdrawal ? CancellationService::WITHDRAWAL : (string) $data['kind'];

        $errors = [];
        if ($data['service_id'] !== null && ($user === null || !isset($this->cancellable($user)[(int) $data['service_id']]))) {
            $errors['service_id'] = 'Please choose one of your contracts.';
        }
        if ($data['service_id'] === null && trim((string) $data['contract']) === '') {
            $errors['contract'] = 'Please describe the contract (e.g. service name, domain or customer number).';
        }
        if ($kind === CancellationService::EXTRAORDINARY && trim((string) $data['reason']) === '') {
            $errors['reason'] = 'Please state the reason for the extraordinary cancellation.';
        }
        $requested = null;
        if (!$withdrawal && $data['effective'] === 'date') {
            $requested = (string) $data['requested_date'];
            if ($requested === '' || $requested < $this->today()) {
                $errors['requested_date'] = 'Please choose a date in the future.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $mailReceipt = true;
        if ($user === null) {
            $this->captcha->verify($request);
            // The receipt is e-mailed to the address entered. Per address (3 per day) and in
            // total (100 per hour) the panel writes only a limited number of such mails; above
            // that the declaration is still recorded and confirmed on the page (the cancellation
            // must stay possible), only the e-mail is skipped.
            $mailReceipt = $this->limiter->attempt('contract-declaration-to:' . hash('sha256', mb_strtolower(trim((string) $data['email']))), 3, 86400)
                && $this->limiter->attempt('contract-declaration-mail:all', 100, 3600);
        }

        $declaration = $this->cancellations->submit([
            'kind' => $kind,
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'customer_reference' => $data['customer_reference'] !== null ? (string) $data['customer_reference'] : ($user?->email),
            'contract' => (string) ($data['contract'] ?? ''),
            'reason' => $data['reason'] !== null ? (string) $data['reason'] : null,
            'requested_date' => $requested,
            'service_id' => $data['service_id'] !== null ? (int) $data['service_id'] : null,
        ], $user, $request->clientIp(), $mailReceipt);

        // Shown directly (no redirect) so the customer can save or print the confirmation.
        $response = $this->render($request, 'contract/received.twig', ['declaration' => $declaration, 'withdrawal' => $withdrawal, 'mailed' => $mailReceipt, 'mailed_to' => $user !== null ? $user->email : (string) $data['email']]);
        $response->setHeader('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @return array<int, string> service ID => label (active and suspended services)
     */
    private function cancellable(User $user): array
    {
        $list = [];
        foreach ($this->services->forUser($user->id) as $service) {
            // Services that are already cancelled are not offered again.
            if (in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED, Service::STATUS_PENDING, Service::STATUS_PROVISIONING], true) && ($service->attributes['cancel_at'] ?? null) === null) {
                $list[$service->id] = sprintf('%s (#%d)', $service->name, $service->id);
            }
        }

        return $list;
    }

    private function today(): string
    {
        return $this->container->get(\Nebrix\Core\Clock::class)->now()->format('Y-m-d');
    }

    private function withCaptchaPolicy(Response $response, ?User $user): void
    {
        if ($user === null && $this->captcha->enabled()) {
            $response->setHeader('Content-Security-Policy', SecurityHeaders::contentSecurityPolicy($this->captcha->cspSources()));
        }
    }
}
