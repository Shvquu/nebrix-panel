<?php

declare(strict_types=1);

namespace Nebrix\Controller\Customer;

use Nebrix\Billing\InvoiceRepository;
use Nebrix\Billing\OrderRepository;
use Nebrix\Core\Config;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Http\SecurityHeaders;
use Nebrix\Integration\IntegrationException;
use Nebrix\Provisioning\ProvisionerRegistry;
use Nebrix\Provisioning\ServiceAction;
use Nebrix\Provisioning\ServiceActionRunner;
use Nebrix\Provisioning\ServiceStateException;
use Nebrix\RemoteConsole\ConsoleSessions;
use Nebrix\Repository\CustomerGroupRepository;
use Nebrix\Security\RateLimiter;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;
use Psr\Log\LoggerInterface;

/**
 * Customer service management. Every lookup is scoped to the signed-in
 * customer; remote actions are limited to what the provisioner offers and
 * to the permissions of the customer's group.
 */
final class ServiceController extends Controller
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly ProvisionerRegistry $provisioners,
        private readonly CustomerGroupRepository $groups,
        private readonly InvoiceRepository $invoices,
        private readonly OrderRepository $orders,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
        private readonly ServiceActionRunner $runner,
        private readonly ConsoleSessions $consoles,
        private readonly Config $config,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render($request, 'customer/services/index.twig', [
            'services' => $this->services->forUser($this->user($request)->id),
            'shared' => $this->team()->sharedServices($this->user($request)),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        $live = null;
        $liveError = null;
        $actions = [];

        if ($service->isActive() && $this->provisioners->supportsService($service)) {
            $provisioner = $this->provisioners->for($service->category);
            try {
                $live = $provisioner->status($service);
                $actions = $this->mayManage($request, $service) ? $this->runner->available($service) : [];
            } catch (IntegrationException $exception) {
                // Customers get a generic message; details are in the integration log.
                $this->logger->notice('Live status unavailable', ['service' => $id, 'message' => $exception->getMessage()]);
                $liveError = 'Live information is temporarily unavailable.';
            }
        }

        return $this->render($request, 'customer/services/show.twig', [
            'service' => $service,
            'live' => $live,
            'live_error' => $liveError,
            'actions' => $actions,
            'renewal' => $this->invoices->openRenewalFor($id),
            'has_credentials' => $service->hasCredentials && in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true) && $this->allows($request, $service, 'services.credentials'),
            'console' => $this->mayManage($request, $service) && $this->consoles->supports($service),
            // Shared with the signed-in user by another account (team access): owner-only parts are hidden.
            'shared' => $service->userId !== $this->user($request)->id,
            'now' => $this->container->get(\Nebrix\Core\Clock::class)->nowString(),
            'can_withdraw_cancellation' => $this->container->get(\Nebrix\Contract\CancellationService::class)->canWithdraw($service),
            'package_change' => $this->packageChange($service),
        ]);
    }

    /**
     * Access data is only rendered on explicit request and never cached.
     */
    public function credentials(Request $request, int $id): Response
    {
        $service = $this->find($request, $id, 'services.credentials');
        if (!in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
            throw HttpException::notFound();
        }
        $this->audit($request, 'service.credentials_viewed', 'service', $id);

        return $this->render($request, 'customer/services/credentials.twig', [
            'service' => $service,
            'credentials' => $this->services->credentials($service),
        ]);
    }

    public function action(Request $request, int $id): Response
    {
        $service = $this->find($request, $id, 'services.manage');
        $data = $this->validate($request, ['action' => 'required|alpha_dash|max:30']);
        if (!$service->isActive() || !$this->mayManage($request, $service) || !$this->provisioners->supportsService($service)) {
            throw HttpException::forbidden();
        }

        try {
            $definition = $this->runner->available($service)[(string) $data['action']] ?? throw HttpException::forbidden();
        } catch (IntegrationException $exception) {
            $this->logger->warning('Service actions unavailable', ['service' => $id, 'message' => $exception->getMessage()]);
            $this->flash($request, 'error', 'The action could not be executed. Please try again later or contact support.');

            return $this->redirect('/services/' . $id);
        }

        if (!$this->limiter->attempt('service-action:' . $id, 10, 300)
            || ($definition->mode === ServiceAction::REINSTALL && !$this->limiter->attempt('service-reinstall:' . $id, 3, 3600))) {
            throw HttpException::tooManyRequests();
        }

        try {
            $result = $this->runner->run($service, (string) $data['action'], $request->all(), $this->user($request)->id, $request->clientIp());
            if ($result['redirect'] !== null) {
                // A page with a link instead of an HTTP redirect: the CSP
                // (form-action 'self') does not allow form posts to redirect away.
                return $this->render($request, 'customer/services/sso.twig', ['service' => $service, 'url' => $result['redirect']]);
            }
            $this->flash($request, 'success', $result['message']);
        } catch (ServiceStateException $exception) {
            $this->flash($request, 'error', $exception->getMessage());
        } catch (IntegrationException $exception) {
            $this->logger->warning('Customer service action failed', ['service' => $id, 'action' => $data['action'], 'message' => $exception->getMessage()]);
            $this->flash($request, 'error', 'The action could not be executed. Please try again later or contact support.');
        }

        return $this->redirect('/services/' . $id);
    }

    /** Takes back a scheduled cancellation before it takes effect. */
    public function withdrawCancellation(Request $request, int $id): Response
    {
        // Owner only (team members cannot change the contract).
        $service = $this->services->findForUser($id, $this->user($request)->id) ?? throw HttpException::notFound();
        try {
            $this->container->get(\Nebrix\Contract\CancellationService::class)->withdraw($service, $this->user($request)->id, $request->clientIp());
            $this->flash($request, 'success', 'The cancellation has been taken back. The service will be renewed as usual.');
        } catch (\PDOException $exception) {
            throw $exception; // database errors: generic error page, details only in the log
        } catch (\RuntimeException $exception) {
            $this->flash($request, 'error', $exception->getMessage());
        }

        return $this->redirect('/services/' . $id);
    }

    public function console(Request $request, int $id): Response
    {
        $service = $this->find($request, $id, 'services.manage');
        if (!$this->mayManage($request, $service) || !$this->consoles->supports($service)) {
            throw HttpException::notFound();
        }

        $response = $this->render($request, 'customer/services/console.twig', ['service' => $service]);
        $response->setHeader('Content-Security-Policy', SecurityHeaders::contentSecurityPolicy(['connect-src' => [$this->consoleOrigin()]]));

        return $response;
    }

    /** Issues a single-use console session (JSON for the console page). */
    public function consoleSession(Request $request, int $id): Response
    {
        $service = $this->find($request, $id, 'services.manage');
        if (!$this->mayManage($request, $service) || !$this->consoles->supports($service)) {
            return Response::json(['error' => 'not_available'], 404);
        }
        if (!$this->limiter->attempt('console:' . $this->user($request)->id, 10, 300)) {
            return Response::json(['error' => 'too_many_requests'], 429);
        }

        try {
            $session = $this->consoles->open($service, $this->user($request), $request->clientIp());
        } catch (IntegrationException|ServiceStateException $exception) {
            $this->logger->warning('Console could not be opened', ['service' => $id, 'message' => $exception->getMessage()]);

            return Response::json(['error' => 'unavailable'], 502);
        }
        $this->audit($request, 'service.console_opened', 'service', $id);

        return Response::json(['url' => $session['url'], 'password' => $session['password']]);
    }

    private function consoleOrigin(): string
    {
        $url = parse_url($this->config->string('console.ws_url'));

        return sprintf('%s://%s%s', $url['scheme'] ?? 'wss', $url['host'] ?? 'localhost', isset($url['port']) ? ':' . $url['port'] : '');
    }

    public function orders(Request $request): Response
    {
        $user = $this->user($request);
        $orders = [];
        foreach ($this->orders->forUser($user->id) as $order) {
            $order['items'] = $this->orders->items((int) $order['id']);
            $orders[] = $order;
        }

        return $this->render($request, 'customer/orders.twig', ['orders' => $orders]);
    }

    /**
     * Whether the service belongs to an upgrade group (link to the package page) and an open change.
     *
     * @return array{available: bool, open: array<string, mixed>|null}
     */
    private function packageChange(Service $service): array
    {
        $group = $service->productId !== null ? $this->container->get(\Nebrix\Database\Connection::class)->fetchValue('SELECT `upgrade_group` FROM `nx_products` WHERE `id` = ?', [$service->productId]) : null;

        $changes = $this->container->get(\Nebrix\Service\ServiceChangeService::class);

        return [
            // An upgrade group or a configurator (new resources for the same product).
            'available' => $service->isActive() && ((is_string($group) && trim($group) !== '') || $changes->configurationRanges($service) !== null),
            'open' => $changes->openChange($service->id),
        ];
    }

    /**
     * Whether the services may be managed: the owner's customer group allows
     * it and, for a shared service, the team member has the manage permission.
     */
    private function mayManage(Request $request, Service $service): bool
    {
        $user = $this->user($request);
        if (!$this->allows($request, $service, 'services.manage')) {
            return false;
        }
        $groupId = $service->userId === $user->id
            ? $user->customerGroupId
            : $this->container->get(\Nebrix\Repository\UserRepository::class)->find($service->userId)?->customerGroupId;
        if ($groupId === null) {
            return true;
        }
        $group = $this->groups->find($groupId);

        return $group === null || (bool) $group['can_manage_services'];
    }

    /** Own services allow everything; shared ones what the owner granted. */
    private function allows(Request $request, Service $service, string $permission): bool
    {
        $user = $this->user($request);

        return $service->userId === $user->id || in_array($permission, $this->team()->sharedPermissions($service, $user), true);
    }

    /** An own service or one shared with the user with the given permission (IDOR protection). */
    private function find(Request $request, int $id, string $permission = 'services.view'): Service
    {
        return $this->team()->service($id, $this->user($request), $permission) ?? throw HttpException::notFound();
    }

    private function team(): \Nebrix\Account\TeamService
    {
        return $this->container->get(\Nebrix\Account\TeamService::class);
    }
}
