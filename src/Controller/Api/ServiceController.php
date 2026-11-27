<?php

declare(strict_types=1);

namespace Nebrix\Controller\Api;

use Nebrix\Api\ApiException;
use Nebrix\Api\ApiScope;
use Nebrix\Api\Middleware\IdempotentRequest;
use Nebrix\Api\Resource;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Integration\IntegrationException;
use Nebrix\Provisioning\ProvisionerRegistry;
use Nebrix\Provisioning\ServiceAction;
use Nebrix\Provisioning\ServiceActionRunner;
use Nebrix\Provisioning\ServiceStateException;
use Nebrix\Repository\CustomerGroupRepository;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;
use Psr\Log\LoggerInterface;

/**
 * The customer's services: details, live status, actions and access data.
 * Same checks as the web interface (ownership, customer group may manage
 * services, provisioner action whitelist, shared rate limits).
 */
final class ServiceController extends ApiController
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly ProvisionerRegistry $provisioners,
        private readonly ServiceActionRunner $runner,
        private readonly CustomerGroupRepository $groups,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function index(Request $request): Response
    {
        $status = $this->stringQuery($request, 'status', 20);
        $services = array_values(array_filter(
            $this->services->forUser($this->user($request)->id),
            static fn (Service $service): bool => $status === '' || $service->status === $status,
        ));

        return $this->pageOf($request, $services, Resource::service(...));
    }

    public function show(Request $request, int $id): Response
    {
        return $this->data(Resource::service($this->find($request, $id)));
    }

    public function status(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        if (!$service->isActive() || !$this->provisioners->supportsService($service)) {
            return $this->data(['state' => $service->status, 'live' => false, 'connection' => []]);
        }
        // Live data is fetched from the remote system on every call.
        $this->throttle('api-live:' . $this->user($request)->id, 30, 60, 'Live data can be requested at most 30 times per minute.');

        try {
            $status = $this->provisioners->for($service->category)->status($service);
        } catch (IntegrationException $exception) {
            $this->logger->notice('Live status unavailable (API)', ['service' => $id, 'message' => $exception->getMessage()]);

            throw new ApiException(502, 'upstream_error', 'Live information is temporarily unavailable.');
        }

        return $this->data(['live' => true] + $status);
    }

    public function actions(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        if (!$this->mayManage($request)) {
            return Response::json(['data' => []]);
        }

        $this->throttle('api-live:' . $this->user($request)->id, 30, 60, 'Live data can be requested at most 30 times per minute.');
        try {
            $available = $this->runner->available($service);
        } catch (IntegrationException $exception) {
            $this->logger->warning('Service actions unavailable (API)', ['service' => $id, 'message' => $exception->getMessage()]);

            throw new ApiException(502, 'upstream_error', 'The actions are temporarily unavailable.');
        }

        $actions = [];
        foreach ($available as $key => $action) {
            $fields = [];
            foreach ($action->fields as $name => $field) {
                $fields[] = [
                    'name' => $name,
                    'label' => $field['label'],
                    'type' => $field['type'] ?? 'text',
                    'required' => str_contains($field['rules'], 'required'),
                    'options' => $field['options'] ?? null,
                    'help' => $field['help'] ?? null,
                ];
            }
            $actions[] = [
                'key' => $key,
                'label' => $action->label,
                'mode' => $action->mode,
                'dangerous' => $action->danger,
                'requires_confirmation' => $action->confirm !== null,
                'confirmation_text' => $action->confirm,
                'fields' => $fields,
            ];
        }

        return Response::json(['data' => $actions]);
    }

    public function runAction(Request $request, int $id, string $action): Response
    {
        $service = $this->find($request, $id);
        if (!$service->isActive() || !$this->mayManage($request) || !$this->provisioners->supportsService($service)) {
            throw ApiException::forbidden('Actions are not available for this service.');
        }

        try {
            $definition = $this->runner->available($service)[$action] ?? throw ApiException::notFound('The action is not available for this service.');
        } catch (IntegrationException $exception) {
            $this->logger->warning('Service actions unavailable (API)', ['service' => $id, 'message' => $exception->getMessage()]);

            throw new ApiException(502, 'upstream_error', 'The action could not be executed. Please try again later.');
        }

        // Single sign-on opens a full control panel session: at least as
        // sensitive as the access data, so it also needs that scope.
        if ($definition->mode === ServiceAction::REDIRECT && !$this->token($request)->hasScope(ApiScope::SERVICES_CREDENTIALS)) {
            throw new ApiException(403, 'insufficient_scope', sprintf('Single sign-on actions also need the scope "%s".', ApiScope::SERVICES_CREDENTIALS));
        }

        $input = $this->input($request);
        // Actions the web interface confirms in a dialog need an explicit confirmation.
        if (($definition->confirm !== null || $definition->danger) && ($input['confirm'] ?? null) !== true) {
            throw new ApiException(422, 'confirmation_required', $definition->confirm ?? 'Confirm the action with "confirm": true.');
        }

        $this->throttle('service-action:' . $id, 10, 300);
        if ($definition->mode === ServiceAction::REINSTALL) {
            $this->throttle('service-reinstall:' . $id, 3, 3600);
        }

        unset($input['confirm']);
        try {
            $result = $this->runner->run($service, $action, $input, $this->user($request)->id, $request->clientIp());
        } catch (ServiceStateException $exception) {
            throw ApiException::conflict($exception->getMessage());
        } catch (IntegrationException $exception) {
            $this->logger->warning('Customer service action failed (API)', ['service' => $id, 'action' => $action, 'message' => $exception->getMessage()]);

            throw new ApiException(502, 'upstream_error', 'The action could not be executed. Please try again later or contact support.');
        }

        $this->apiAudit($request, 'service.action', 'service', $id, ['action' => $action]);
        if ($result['redirect'] !== null) {
            // Login URLs are one-time credentials: never kept for idempotent replays.
            $request->setAttribute(IdempotentRequest::DO_NOT_STORE, true);
        }

        return $this->data([
            'message' => $result['message'],
            'redirect_url' => $result['redirect'],
            'service' => Resource::service($this->services->find($id) ?? $service),
        ], $definition->mode === ServiceAction::REINSTALL ? 202 : 200);
    }

    /**
     * Ordinary cancellation of the service (end of the paid term or a later
     * date), same rules as the cancellation page.
     */
    public function cancel(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        if (!in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
            throw ApiException::conflict('Only active or suspended services can be cancelled here.');
        }
        if ($service->attributes['cancel_at'] !== null) {
            throw ApiException::conflict('The service is already cancelled.');
        }
        $data = $this->validateInput($request, ['end_date' => 'nullable|date', 'reason' => 'nullable|string|max:5000']);
        if ($data['end_date'] !== null && (string) $data['end_date'] < $this->container->get(\Nebrix\Core\Clock::class)->now()->format('Y-m-d')) {
            throw \Nebrix\Security\ValidationException::withMessages(['end_date' => 'Please choose a date in the future.']);
        }
        $this->throttle('contract-declaration:' . $request->clientIp(), 10, 3600);
        $user = $this->user($request);
        $declaration = $this->container->get(\Nebrix\Contract\CancellationService::class)->submit([
            'kind' => \Nebrix\Contract\CancellationService::ORDINARY,
            'name' => $user->displayName(),
            'email' => $user->email,
            'reason' => $data['reason'] !== null ? (string) $data['reason'] : null,
            'requested_date' => $data['end_date'] !== null ? (string) $data['end_date'] : null,
            'service_id' => $id,
        ], $user, $request->clientIp());

        return $this->data([
            'reference' => (string) $declaration['reference'],
            'received_at' => Resource::date($declaration['received_at']),
            'ends_at' => Resource::date($declaration['effective_at']),
            'service' => Resource::service($this->services->find($id) ?? $service),
        ], 201);
    }

    public function withdrawCancellation(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        try {
            $this->container->get(\Nebrix\Contract\CancellationService::class)->withdraw($service, $this->user($request)->id, $request->clientIp());
        } catch (\PDOException $exception) {
            throw $exception; // database errors: generic error page, details only in the log
        } catch (\RuntimeException $exception) {
            throw ApiException::conflict($exception->getMessage());
        }

        return $this->data(Resource::service($this->services->find($id) ?? $service));
    }

    /** Packages the service can switch to (upgrade/downgrade) with prices. */
    public function packageOptions(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        $options = $this->container->get(\Nebrix\Service\ServiceChangeService::class)->options($service, $this->user($request));
        $open = $options['open'];

        return $this->data([
            'blocker' => $options['blocker'],
            'open_change' => $open === null ? null : [
                'id' => (int) $open['id'], 'status' => (string) $open['status'], 'direction' => (string) $open['direction'],
                'product_id' => (int) $open['to_product_id'], 'invoice_id' => $open['invoice_id'] !== null ? (int) $open['invoice_id'] : null,
                'effective_at' => $open['effective_at'] !== null ? Resource::date((string) $open['effective_at']) : null,
            ],
            'packages' => array_map(static fn (array $t): array => [
                'product_id' => (int) $t['product']['id'],
                'name' => (string) $t['product']['name'],
                'direction' => (string) $t['direction'],
                'recurring_net' => (int) $t['recurring'],
                'recurring_gross' => (int) $t['recurring_gross'],
                'charge_net' => (int) $t['charge'],
                'charge_gross' => (int) $t['charge_gross'],
                'effective_at' => $t['effective_at'] !== null ? Resource::date((string) $t['effective_at']) : null,
                'available' => $t['blocker'] === null,
                'reason' => $t['blocker'],
            ], $options['targets']),
        ]);
    }

    /** Requests an upgrade (returns the invoice to pay) or schedules a downgrade. */
    public function changePackage(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        if (!$this->mayManage($request)) {
            throw ApiException::forbidden('Your customer group may not manage services.');
        }
        $data = $this->validateInput($request, ['product_id' => 'required|int|min:1']);
        $this->throttle('package-change:' . $this->user($request)->id, 10, 3600, 'Too many package changes. Please try again later.');
        try {
            $result = $this->container->get(\Nebrix\Service\ServiceChangeService::class)->request($service, $this->user($request), (int) $data['product_id'], $this->user($request)->id, $request->clientIp());
        } catch (\Nebrix\Service\ServiceChangeException $exception) {
            throw new ApiException(422, 'package_change_invalid', $exception->getMessage());
        }

        return Response::json(['data' => ['change_id' => $result['change_id'], 'status' => $result['status'], 'invoice_id' => $result['invoice_id']]], 201);
    }

    public function withdrawPackageChange(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        if (!$this->mayManage($request)) {
            throw ApiException::forbidden('Your customer group may not manage services.');
        }
        try {
            $this->container->get(\Nebrix\Service\ServiceChangeService::class)->withdraw($service, $this->user($request)->id, $request->clientIp());
        } catch (\Nebrix\Service\ServiceChangeException $exception) {
            throw ApiException::conflict($exception->getMessage());
        }

        return Response::json(['data' => ['withdrawn' => true]]);
    }

    public function credentials(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        if (!in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
            throw ApiException::notFound('No access data is available for this service.');
        }
        $this->apiAudit($request, 'service.credentials_viewed', 'service', $id);

        $credentials = [];
        foreach ($this->services->credentials($service) as $key => $value) {
            $credentials[(string) $key] = is_scalar($value) || $value === null ? $value : null;
        }

        return $this->data($credentials);
    }

    private function mayManage(Request $request): bool
    {
        $user = $this->user($request);
        if ($user->customerGroupId === null) {
            return true;
        }
        $group = $this->groups->find($user->customerGroupId);

        return $group === null || (bool) $group['can_manage_services'];
    }

    private function find(Request $request, int $id): Service
    {
        return $this->services->findForUser($id, $this->user($request)->id) ?? throw ApiException::notFound();
    }
}
