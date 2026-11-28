<?php

declare(strict_types=1);

namespace Nebrix\Controller\Customer;

use Nebrix\Domain\Dns\DnsRecordValidator;
use Nebrix\Domain\Dns\DnsZoneService;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Integration\IntegrationException;
use Nebrix\Provisioning\ServiceStateException;
use Nebrix\Repository\CustomerGroupRepository;
use Nebrix\Security\RateLimiter;
use Nebrix\Security\ValidationException;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;
use Nebrix\Settings\SettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * DNS records of a customer's domain whose zone is hosted at the registrar.
 * Scoped to the signed-in customer; changes need the "manage services" right
 * of the customer group, are rate-limited per domain and audit-logged.
 */
final class DnsController extends Controller
{
    private const RECORD_ID = '/^[A-Za-z0-9]{1,40}$/';

    public function __construct(
        private readonly ServiceRepository $services,
        private readonly CustomerGroupRepository $groups,
        private readonly DnsZoneService $dns,
        private readonly RateLimiter $limiter,
        private readonly SettingsRepository $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function show(Request $request, int $id): Response
    {
        $service = $this->find($request, $id);
        if (!$this->dns->available($service)) {
            throw HttpException::notFound();
        }
        $zone = null;
        $error = null;
        try {
            $zone = $this->dns->zone($service);
        } catch (ServiceStateException $exception) {
            $error = $exception->getMessage();
        } catch (IntegrationException $exception) {
            $this->logger->notice('DNS zone unavailable', ['service' => $id, 'message' => $exception->getMessage()]);
            $error = 'The DNS records could not be loaded. Please try again later.';
        }
        $editId = $request->string('edit');
        $editing = null;
        foreach ($zone['records'] ?? [] as $record) {
            if ($record['editable'] && $editId !== '' && hash_equals($record['id'], $editId)) {
                $editing = $record;
            }
        }

        return $this->render($request, 'customer/services/dns.twig', [
            'service' => $service,
            'zone' => $zone,
            'error' => $error,
            'editing' => $editing,
            'types' => array_combine(DnsRecordValidator::TYPES, DnsRecordValidator::TYPES),
            'max_records' => DnsZoneService::MAX_RECORDS,
            'may_manage' => $this->mayManage($request),
        ]);
    }

    public function create(Request $request, int $id): Response
    {
        return $this->change($request, $id, fn (Service $service) => $this->dns->create($service, $this->recordInput($request), $this->user($request)->id, $request->clientIp()), 'The DNS record has been added. Changes can take a while to propagate.');
    }

    public function update(Request $request, int $id): Response
    {
        $recordId = $this->recordId($request);

        return $this->change($request, $id, fn (Service $service) => $this->dns->update($service, $recordId, $this->recordInput($request), $this->user($request)->id, $request->clientIp()), 'The DNS record has been updated. Changes can take a while to propagate.');
    }

    public function delete(Request $request, int $id): Response
    {
        $recordId = $this->recordId($request);

        return $this->change($request, $id, fn (Service $service) => $this->dns->delete($service, $recordId, $this->user($request)->id, $request->clientIp()), 'The DNS record has been deleted.');
    }

    /**
     * @param callable(Service): void $change
     */
    private function change(Request $request, int $id, callable $change, string $message): Response
    {
        $service = $this->find($request, $id);
        if (!$this->mayManage($request) || !$this->dns->available($service)) {
            throw HttpException::forbidden();
        }
        if (!$this->limiter->attempt('domain-dns:' . $id, max(1, $this->settings->int('server.dns_changes_per_hour')), 3600)) {
            throw HttpException::tooManyRequests();
        }
        try {
            $change($service);
            $this->flash($request, 'success', $message);
        } catch (ValidationException $exception) {
            throw new ValidationException($exception->errors(), $this->recordInput($request));
        } catch (ServiceStateException $exception) {
            $this->flash($request, 'error', $exception->getMessage());
        } catch (IntegrationException $exception) {
            $this->logger->warning('DNS change failed', ['service' => $id, 'message' => $exception->getMessage()]);
            $this->flash($request, 'error', 'The DNS change could not be saved at the registrar. Please try again later or contact support.');
        }

        return $this->redirect('/services/' . $id . '/dns');
    }

    /**
     * @return array<string, string>
     */
    private function recordInput(Request $request): array
    {
        $input = [];
        foreach (['type', 'name', 'content', 'prio', 'ttl'] as $field) {
            $input[$field] = mb_substr($request->string($field), 0, 2048);
        }

        return $input;
    }

    private function recordId(Request $request): string
    {
        $recordId = $request->string('record');
        if (preg_match(self::RECORD_ID, $recordId) !== 1) {
            throw HttpException::notFound();
        }

        return $recordId;
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
        return $this->services->findForUser($id, $this->user($request)->id) ?? throw HttpException::notFound();
    }
}
