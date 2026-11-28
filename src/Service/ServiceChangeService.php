<?php

declare(strict_types=1);

namespace Nebrix\Service;

use DateTimeImmutable;
use DateTimeZone;
use Nebrix\Audit\AuditLogger;
use Nebrix\Auth\User;
use Nebrix\Billing\InvoiceRepository;
use Nebrix\Billing\InvoiceService;
use Nebrix\Billing\Money;
use Nebrix\Catalog\BillingCycle;
use Nebrix\Catalog\ProductConfigurator;
use Nebrix\Catalog\ProductPricing;
use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Mail\Mailer;
use Nebrix\Provisioning\PackageChangeable;
use Nebrix\Provisioning\ProvisionerRegistry;
use Nebrix\Queue\JobQueue;
use Nebrix\Queue\PermanentJobFailure;
use Nebrix\Repository\CustomerGroupRepository;
use Nebrix\Repository\ProductRepository;
use Nebrix\Repository\UserRepository;
use Nebrix\View\Translator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Upgrades and downgrades: a service switches to another product of the
 * same upgrade group (same category, integration, currency and billing
 * cycle).
 *
 * Upgrade:   the prorated difference for the rest of the paid period is
 *            invoiced; when the invoice is paid, the package is changed
 *            through the queue and the new price applies to renewals.
 * Downgrade: takes effect at the end of the paid period (no refund); the
 *            next renewal is already invoiced at the new price.
 *
 * Only the package values of the configuration (cores, memory, slots,
 * plan, ...) are taken from the target product; the installation (template,
 * node, address, options) stays as it is.
 */
class ServiceChangeService
{
    public const PENDING_PAYMENT = 'pending_payment';
    public const SCHEDULED = 'scheduled';
    public const QUEUED = 'queued';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    public const UPGRADE = 'upgrade';
    public const DOWNGRADE = 'downgrade';

    /** Unpaid upgrade invoices are cancelled after this many days. */
    private const PAYMENT_DAYS = 14;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly ServiceRepository $services,
        private readonly ProductRepository $products,
        private readonly UserRepository $users,
        private readonly CustomerGroupRepository $groups,
        private readonly ProvisionerRegistry $provisioners,
        private readonly InvoiceService $invoices,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly JobQueue $jobs,
        private readonly Mailer $mailer,
        private readonly AuditLogger $audit,
        private readonly Translator $translator,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * What the customer can switch to.
     *
     * @return array{blocker: string|null, open: array<string, mixed>|null, targets: list<array<string, mixed>>}
     */
    public function options(Service $service, User $owner): array
    {
        $open = $this->openChange($service->id);
        $blocker = $open !== null ? 'A package change is already in progress.' : $this->serviceBlocker($service, true);
        $targets = [];
        if ($blocker === null) {
            foreach ($this->candidates($service, true) as $product) {
                $targets[] = $this->describe($service, $owner, $product, true);
            }
        }

        return ['blocker' => $blocker, 'open' => $open, 'targets' => $targets];
    }

    /**
     * Products staff may switch a service to (any product of the same kind,
     * not only the upgrade group).
     *
     * @return list<array<string, mixed>>
     */
    public function adminTargets(Service $service, User $owner): array
    {
        $targets = [];
        foreach ($this->candidates($service, false) as $product) {
            $targets[] = $this->describe($service, $owner, $product, false);
        }

        return $targets;
    }

    /**
     * Requests a change. Customers (actor = owner) are limited to the
     * upgrade group; staff may pick any matching product and apply it at
     * once without invoice ($free).
     *
     * @return array{change_id: int, status: string, invoice_id: int|null}
     *
     * @throws ServiceChangeException with a message for the user
     */
    public function request(Service $service, User $owner, int $productId, ?int $actorId, ?string $ip, bool $byStaff = false, bool $free = false): array
    {
        $result = $this->db->transaction(function () use ($service, $owner, $productId, $actorId, $byStaff, $free): array {
            // Lock the service: no second change, no renewal in between.
            $this->db->fetchValue('SELECT `id` FROM `nx_services` WHERE `id` = ? FOR UPDATE', [$service->id]);
            $service = $this->services->find($service->id) ?? throw new ServiceChangeException('The service was not found.');
            if ($this->openChange($service->id) !== null) {
                throw new ServiceChangeException('A package change is already in progress.');
            }
            $blocker = $this->serviceBlocker($service, !$byStaff);
            if ($blocker !== null) {
                throw new ServiceChangeException($blocker);
            }
            $product = null;
            foreach ($this->candidates($service, !$byStaff) as $candidate) {
                if ((int) $candidate['id'] === $productId) {
                    $product = $candidate;
                }
            }
            if ($product === null) {
                throw new ServiceChangeException('This package is not available for your service.');
            }
            $offer = $this->describe($service, $owner, $product, !$byStaff);
            if ($offer['blocker'] !== null) {
                throw new ServiceChangeException((string) $offer['blocker']);
            }
            if (!$this->products->reserveStock((int) $product['id'])) {
                throw new ServiceChangeException('This package is sold out.');
            }

            return $this->createChange($service, $owner, $product, $offer, null, $actorId, $free);
        });

        return $this->afterRequest($service, $result, $actorId, $ip, $free);
    }

    /**
     * Offer for new resources of a configured service (same product): price,
     * direction and blocker – like a package change.
     *
     * @param array<string, mixed> $input option name => value (e.g. slots, or cores/memory_gb/disk_gb)
     * @return array<string, mixed>
     *
     * @throws ServiceChangeException
     * @throws \Nebrix\Security\ValidationException invalid values
     */
    public function configurationOffer(Service $service, User $owner, array $input, bool $selfService = true): array
    {
        $product = $service->productId !== null ? $this->products->find($service->productId) : null;
        // The product must still offer the service's billing cycle: the new price is one of that cycle.
        if ($product === null || !ProductConfigurator::enabled($product) || !$product['is_active'] || !ProductPricing::offersCycle($product, $service->billingCycle)) {
            throw new ServiceChangeException('The resources of this service cannot be changed here.');
        }
        $choice = ProductConfigurator::resolve($product, $input);
        $category = (string) $product['category'];
        $current = ProductConfigurator::choiceOf($category, $service->config);
        $wanted = array_diff_key($choice, ['price' => true]);
        if ($wanted === $current) {
            throw new ServiceChangeException('Choose a configuration that differs from the current one.');
        }
        $recurring = $this->recurringPrice($service, $product, $owner, (int) $choice['price']);
        $difference = $recurring - $service->recurringAmount;
        $increases = count(array_filter($wanted, static fn (int $value, string $name): bool => $value > ($current[$name] ?? 0), ARRAY_FILTER_USE_BOTH)) > 0;
        $direction = $difference > 0 || ($difference === 0 && $increases) ? self::UPGRADE : self::DOWNGRADE;
        $charge = $direction === self::UPGRADE ? $this->prorate($service, max(0, $difference)) : 0;
        $provisioner = $this->changer($service);
        $blocker = $provisioner !== null ? $provisioner->packageChangeBlocker($service, $this->configuredConfig($service, $wanted)) : null;
        if ($blocker === null && $selfService && $provisioner === null) {
            $blocker = 'Please contact our support to change the package of this service.';
        }
        $rate = (int) $product['tax_rate_bp'];

        return [
            'product' => $product,
            'from_name' => (string) $product['name'],
            'direction' => $direction,
            'recurring' => $recurring,
            'recurring_gross' => $recurring + Money::tax($recurring, $rate),
            'charge' => $charge,
            'charge_gross' => $charge + Money::tax($charge, $rate),
            'effective_at' => $direction === self::DOWNGRADE ? $service->nextDueAt : null,
            'blocker' => $blocker,
            'configuration' => $wanted,
            'current' => $current,
        ];
    }

    /**
     * Ranges and current resources of a configured service (null = its
     * product has no configurator).
     *
     * @return array{ranges: array<string, array{min: int, max: int, step: int, price: int}>, current: array<string, int>}|null
     */
    public function configurationRanges(Service $service): ?array
    {
        $product = $service->productId !== null ? $this->products->find($service->productId) : null;
        $ranges = $product !== null && (bool) $product['is_active'] ? ProductConfigurator::ranges($product) : null;

        return $ranges !== null ? ['ranges' => $ranges, 'current' => ProductConfigurator::choiceOf($service->category, $service->config)] : null;
    }

    /**
     * Requests new resources for a configured service (same flow as a
     * package change: upgrades are invoiced pro rata, downgrades apply at
     * the end of the period).
     *
     * @param array<string, mixed> $input
     * @return array{change_id: int, status: string, invoice_id: int|null}
     *
     * @throws ServiceChangeException
     * @throws \Nebrix\Security\ValidationException
     */
    public function requestConfiguration(Service $service, User $owner, array $input, ?int $actorId, ?string $ip): array
    {
        $result = $this->db->transaction(function () use ($service, $owner, $input, $actorId): array {
            $this->db->fetchValue('SELECT `id` FROM `nx_services` WHERE `id` = ? FOR UPDATE', [$service->id]);
            $service = $this->services->find($service->id) ?? throw new ServiceChangeException('The service was not found.');
            if ($this->openChange($service->id) !== null) {
                throw new ServiceChangeException('A package change is already in progress.');
            }
            $blocker = $this->serviceBlocker($service, true);
            if ($blocker !== null) {
                throw new ServiceChangeException($blocker);
            }
            $offer = $this->configurationOffer($service, $owner, $input);
            if ($offer['blocker'] !== null) {
                throw new ServiceChangeException((string) $offer['blocker']);
            }

            // Same product: the service keeps its unit of stock.
            return $this->createChange($service, $owner, $offer['product'], $offer, $offer['configuration'], $actorId, false);
        });

        return $this->afterRequest($service, $result, $actorId, $ip, false);
    }

    /**
     * Stores a change (inside the caller's transaction) and its upgrade invoice.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $offer
     * @param array<string, int>|null $configuration new resources (configured services)
     * @return array{change_id: int, status: string, invoice_id: int|null, direction: string, to: int}
     */
    private function createChange(Service $service, User $owner, array $product, array $offer, ?array $configuration, ?int $actorId, bool $free): array
    {
        $now = $this->clock->nowString();
        $direction = $offer['direction'];
        $immediate = $free || ($direction === self::UPGRADE && (int) $offer['charge'] === 0);
        $status = $immediate ? self::QUEUED : ($direction === self::UPGRADE ? self::PENDING_PAYMENT : self::SCHEDULED);
        $changeId = $this->db->insert('nx_service_changes', [
            'service_id' => $service->id,
            'user_id' => $owner->id,
            'from_product_id' => $service->productId,
            'to_product_id' => (int) $product['id'],
            'configuration' => $configuration !== null ? json_encode($configuration, JSON_THROW_ON_ERROR) : null,
            'direction' => $direction,
            'status' => $status,
            'old_recurring' => $service->recurringAmount,
            'new_recurring' => (int) $offer['recurring'],
            'charge' => $immediate ? 0 : (int) $offer['charge'],
            'currency' => $service->currency,
            'effective_at' => $status === self::SCHEDULED ? $service->nextDueAt : null,
            'requested_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $invoiceId = null;
        if ($status === self::PENDING_PAYMENT) {
            [$start, $end] = [$this->clock->now(), $this->periodEnd($service)];
            $description = $this->translator->translateTo((string) ($owner->attribute('language') ?? 'en'), 'Upgrade :service: :from → :to (:start to :end)', [
                'service' => $service->name,
                'from' => (string) $offer['from_name'],
                'to' => (string) $product['name'] . ($configuration !== null ? ' (' . $this->summary($configuration, (string) ($owner->attribute('language') ?? 'en'), $service->category) . ')' : ''),
                'start' => Translator::date((string) ($owner->attribute('language') ?? 'en'), $start),
                'end' => Translator::date((string) ($owner->attribute('language') ?? 'en'), $end),
            ]);
            $invoiceId = $this->invoices->createUpgrade($service, $owner, $product, (int) $offer['charge'], $description, $start, $end);
            $this->db->update('nx_service_changes', ['invoice_id' => $invoiceId], ['id' => $changeId]);
        } elseif ($status === self::SCHEDULED) {
            // The next renewal is already invoiced at the new price.
            $this->services->update($service->id, ['recurring_amount' => (int) $offer['recurring']]);
        }

        return ['change_id' => $changeId, 'status' => $status, 'invoice_id' => $invoiceId, 'direction' => $direction, 'to' => (int) $product['id']];
    }

    /**
     * @param array{change_id: int, status: string, invoice_id: int|null, direction: string, to: int} $result
     * @return array{change_id: int, status: string, invoice_id: int|null}
     */
    private function afterRequest(Service $service, array $result, ?int $actorId, ?string $ip, bool $free): array
    {
        if ($result['status'] === self::QUEUED) {
            $this->jobs->dispatch(InvoiceService::JOB_APPLY_CHANGE, ['change_id' => $result['change_id']], InvoiceService::JOB_APPLY_CHANGE . ':' . $result['change_id']);
        }
        $this->audit->log($actorId, 'service.change_requested', 'service', $service->id, ['change' => $result['change_id'], 'direction' => $result['direction'], 'to_product' => $result['to'], 'status' => $result['status'], 'free' => $free], $ip);

        return ['change_id' => $result['change_id'], 'status' => $result['status'], 'invoice_id' => $result['invoice_id']];
    }

    /**
     * Withdraws a scheduled downgrade or an unpaid upgrade.
     *
     * @throws ServiceChangeException
     */
    public function withdraw(Service $service, ?int $actorId, ?string $ip): void
    {
        $invoiceToCancel = $this->db->transaction(function () use ($service): ?int {
            $this->db->fetchValue('SELECT `id` FROM `nx_services` WHERE `id` = ? FOR UPDATE', [$service->id]);
            $change = $this->db->fetchOne(
                "SELECT * FROM `nx_service_changes` WHERE `service_id` = ? AND `status` IN ('pending_payment', 'scheduled') ORDER BY `id` DESC LIMIT 1 FOR UPDATE",
                [$service->id],
            );
            if ($change === null) {
                throw new ServiceChangeException('There is no package change that can be withdrawn.');
            }
            if ($change['status'] === self::PENDING_PAYMENT) {
                return (int) $change['invoice_id'];
            }
            // A renewal at the new price that was already issued cannot be undone here.
            $renewed = (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM `nx_invoices` i JOIN `nx_invoice_items` ii ON ii.`invoice_id` = i.`id`
                  WHERE i.`service_id` = ? AND i.`type` = 'renewal' AND i.`status` IN ('unpaid', 'paid') AND ii.`period_start` >= ?",
                [$service->id, $change['effective_at']],
            );
            if ($renewed > 0) {
                throw new ServiceChangeException('The renewal for the new package has already been invoiced; please contact our support.');
            }
            $this->services->update($service->id, ['recurring_amount' => (int) $change['old_recurring']]);
            $this->db->update('nx_service_changes', ['status' => self::CANCELLED, 'error' => 'Withdrawn.', 'updated_at' => $this->clock->nowString()], ['id' => $change['id']]);
            if ($change['configuration'] === null) {
                $this->products->releaseStock((int) $change['to_product_id']);
            }

            return null;
        });
        if ($invoiceToCancel !== null && $invoiceToCancel > 0) {
            // Cancelling the unpaid invoice cancels the change (InvoiceService::cancel).
            try {
                $this->invoices->cancel($invoiceToCancel);
            } catch (\RuntimeException) {
                throw new ServiceChangeException('The upgrade invoice has already been paid.');
            }
        }
        $this->audit->log($actorId, 'service.change_withdrawn', 'service', $service->id, [], $ip);
    }

    /**
     * Job: the upgrade invoice was paid (payload invoice_id) or a change is due (change_id).
     *
     * @param array<string, mixed> $payload
     */
    public function applyFromJob(array $payload): void
    {
        $changeId = (int) ($payload['change_id'] ?? 0);
        if (isset($payload['invoice_id'])) {
            $changeId = (int) $this->db->fetchValue('SELECT `id` FROM `nx_service_changes` WHERE `invoice_id` = ?', [(int) $payload['invoice_id']]);
            if ($changeId === 0) {
                return; // follow-up invoice for an already applied upgrade: nothing to do
            }
            $this->db->run("UPDATE `nx_service_changes` SET `status` = 'queued', `updated_at` = ? WHERE `id` = ? AND `status` = 'pending_payment'", [$this->clock->nowString(), $changeId]);
        }
        if ($changeId < 1) {
            throw new PermanentJobFailure('Package change not found.');
        }
        $this->apply($changeId);
    }

    /**
     * Applies a queued change: remote first (may be retried), then the
     * database. Idempotent.
     */
    public function apply(int $changeId): void
    {
        $change = $this->db->fetchOne('SELECT * FROM `nx_service_changes` WHERE `id` = ?', [$changeId]) ?? throw new PermanentJobFailure('Package change not found.');
        if ($change['status'] !== self::QUEUED) {
            return; // completed, cancelled or not yet due
        }
        $service = $this->services->find((int) $change['service_id']);
        $product = $this->products->find((int) $change['to_product_id']);
        if ($service === null || $product === null || in_array($service->status, [Service::STATUS_TERMINATED, Service::STATUS_CANCELLED], true)) {
            $this->db->update('nx_service_changes', ['status' => self::CANCELLED, 'error' => 'The service has ended.', 'updated_at' => $this->clock->nowString()], ['id' => $changeId]);
            if ($change['configuration'] === null) {
                $this->products->releaseStock((int) $change['to_product_id']);
            }

            return;
        }

        $provisioner = $this->changer($service);
        $configuration = $change['configuration'] !== null ? json_decode((string) $change['configuration'], true) : null;
        $newConfig = is_array($configuration) ? $this->configuredConfig($service, $configuration) : $this->newConfig($service, $product, $provisioner);
        $note = null;
        if ($provisioner !== null) {
            $blocker = $provisioner->packageChangeBlocker($service, $newConfig);
            if ($blocker !== null) {
                throw new PermanentJobFailure($blocker);
            }
            $note = $provisioner->changePackage($service, $newConfig);
        }

        $renewalToReplace = $this->db->transaction(function () use ($change, $service, $product, $newConfig): ?int {
            $row = $this->db->fetchOne('SELECT `status` FROM `nx_service_changes` WHERE `id` = ? FOR UPDATE', [$change['id']]);
            if ($row === null || $row['status'] !== self::QUEUED) {
                return null;
            }
            $this->services->update($service->id, [
                'product_id' => (int) $product['id'],
                'config' => $newConfig,
                'recurring_amount' => (int) $change['new_recurring'],
                'tax_rate_bp' => (int) $product['tax_rate_bp'],
            ]);
            $this->db->update('nx_service_changes', ['status' => self::COMPLETED, 'error' => null, 'completed_at' => $this->clock->nowString(), 'updated_at' => $this->clock->nowString()], ['id' => $change['id']]);
            // A configuration change keeps the product (and its unit of stock).
            if ($change['from_product_id'] !== null && $change['configuration'] === null) {
                $this->products->releaseStock((int) $change['from_product_id']);
            }

            // An unpaid renewal issued at the old price is replaced by one at the new price.
            $open = $this->invoiceRepository->openRenewalFor($service->id);

            return $open !== null && $change['direction'] === self::UPGRADE ? (int) $open['id'] : null;
        });
        if ($renewalToReplace !== null) {
            try {
                $this->invoices->cancel($renewalToReplace);
                $updated = $this->services->find($service->id);
                if ($updated !== null) {
                    $this->invoices->createRenewal($updated);
                }
            } catch (Throwable $exception) {
                $this->logger->error('Renewal invoice could not be replaced after an upgrade', ['service' => $service->id, 'invoice' => $renewalToReplace, 'message' => $exception->getMessage()]);
            }
        }

        $this->audit->log(null, 'service.changed', 'service', $service->id, ['change' => (int) $change['id'], 'from_product' => $change['from_product_id'], 'to_product' => (int) $product['id']]);
        $owner = $this->users->find($service->userId);
        if ($owner !== null) {
            $locale = (string) ($owner->attribute('language') ?? 'en');
            $this->mailer->queue($owner->email, $owner->displayName(), 'service_package_changed', [
                'name' => $owner->displayName(),
                'service_name' => $service->name,
                'package' => (string) $product['name'] . (is_array($configuration) ? ' (' . $this->summary(array_map('intval', $configuration), $locale, $service->category) . ')' : ''),
                'price' => Money::format((int) $change['new_recurring'], $service->currency, $locale),
                'note' => $note !== null ? $this->translator->translateTo($locale, $note) : '',
                'service_url' => rtrim($this->config->string('app.url'), '/') . '/services/' . $service->id,
            ]);
        }
    }

    /**
     * The change could not be applied after all attempts: staff must look at it.
     *
     * @param array<string, mixed> $payload
     */
    public function markFailed(array $payload, string $error): void
    {
        $changeId = (int) ($payload['change_id'] ?? 0);
        if ($changeId < 1 && isset($payload['invoice_id'])) {
            $changeId = (int) $this->db->fetchValue('SELECT `id` FROM `nx_service_changes` WHERE `invoice_id` = ?', [(int) $payload['invoice_id']]);
        }
        if ($changeId < 1) {
            return;
        }
        $this->db->run(
            "UPDATE `nx_service_changes` SET `status` = 'failed', `error` = ?, `updated_at` = ? WHERE `id` = ? AND `status` = 'queued'",
            [mb_substr($error, 0, 500), $this->clock->nowString(), $changeId],
        );
        $this->audit->log(null, 'service.change_failed', 'service_change', $changeId, ['error' => mb_substr($error, 0, 200)]);
    }

    /** Staff retry a failed change (e.g. after fixing the integration). */
    public function retry(int $changeId, int $actorId, ?string $ip): bool
    {
        $changed = $this->db->run("UPDATE `nx_service_changes` SET `status` = 'queued', `error` = NULL, `updated_at` = ? WHERE `id` = ? AND `status` = 'failed'", [$this->clock->nowString(), $changeId])->rowCount() > 0;
        if ($changed) {
            $this->jobs->dispatch(InvoiceService::JOB_APPLY_CHANGE, ['change_id' => $changeId], InvoiceService::JOB_APPLY_CHANGE . ':' . $changeId . ':retry:' . $this->clock->now()->getTimestamp());
            $this->audit->log($actorId, 'service.change_retried', 'service_change', $changeId, [], $ip);
        }

        return $changed;
    }

    /**
     * Scheduler: starts due downgrades and cancels upgrades whose invoice
     * stayed unpaid.
     *
     * @return array{started: int, expired: int}
     */
    public function process(): array
    {
        $started = 0;
        foreach ($this->db->fetchAll("SELECT `id` FROM `nx_service_changes` WHERE `status` = 'scheduled' AND `effective_at` <= ? ORDER BY `effective_at` LIMIT 200", [$this->clock->nowString()]) as $row) {
            if ($this->db->run("UPDATE `nx_service_changes` SET `status` = 'queued', `updated_at` = ? WHERE `id` = ? AND `status` = 'scheduled'", [$this->clock->nowString(), $row['id']])->rowCount() > 0) {
                $this->jobs->dispatch(InvoiceService::JOB_APPLY_CHANGE, ['change_id' => (int) $row['id']], InvoiceService::JOB_APPLY_CHANGE . ':' . $row['id']);
                $started++;
            }
        }

        $expired = 0;
        $before = $this->clock->now()->modify('-' . self::PAYMENT_DAYS . ' days')->format('Y-m-d H:i:s');
        foreach ($this->db->fetchAll(
            "SELECT c.`invoice_id` FROM `nx_service_changes` c JOIN `nx_invoices` i ON i.`id` = c.`invoice_id`
              WHERE c.`status` = 'pending_payment' AND i.`status` = 'unpaid' AND c.`created_at` < ? LIMIT 200",
            [$before],
        ) as $row) {
            try {
                $this->invoices->cancel((int) $row['invoice_id']);
                $expired++;
            } catch (Throwable $exception) {
                $this->logger->warning('Unpaid upgrade invoice could not be cancelled', ['invoice' => $row['invoice_id'], 'message' => $exception->getMessage()]);
            }
        }

        return ['started' => $started, 'expired' => $expired];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $serviceId): array
    {
        return $this->db->fetchAll(
            'SELECT c.*, f.`name` AS from_name, t.`name` AS to_name, i.`number` AS invoice_number, i.`status` AS invoice_status
               FROM `nx_service_changes` c
          LEFT JOIN `nx_products` f ON f.`id` = c.`from_product_id`
          LEFT JOIN `nx_products` t ON t.`id` = c.`to_product_id`
          LEFT JOIN `nx_invoices` i ON i.`id` = c.`invoice_id`
              WHERE c.`service_id` = ? ORDER BY c.`id` DESC LIMIT 50',
            [$serviceId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function openChange(int $serviceId): ?array
    {
        return $this->db->fetchOne(
            "SELECT c.*, t.`name` AS to_name, i.`number` AS invoice_number FROM `nx_service_changes` c
          LEFT JOIN `nx_products` t ON t.`id` = c.`to_product_id`
          LEFT JOIN `nx_invoices` i ON i.`id` = c.`invoice_id`
              WHERE c.`service_id` = ? AND c.`status` IN ('pending_payment', 'scheduled', 'queued') ORDER BY c.`id` DESC LIMIT 1",
            [$serviceId],
        );
    }

    /**
     * Why the service cannot change its package at all (null = it can).
     */
    private function serviceBlocker(Service $service, bool $selfService): ?string
    {
        if ($service->status !== Service::STATUS_ACTIVE) {
            return 'Only active services can change their package.';
        }
        if ($service->productId === null || !BillingCycle::isRecurring($service->billingCycle) || $service->nextDueAt === null) {
            return 'This service cannot change its package.';
        }
        if (($service->attributes['cancel_at'] ?? null) !== null) {
            return 'The service has been cancelled; its package can no longer be changed.';
        }
        if (($service->attributes['next_billing_cycle'] ?? null) !== null) {
            return 'A change of the billing cycle is pending. Please take it back first.';
        }
        if ($this->invoiceRepository->openRenewalFor($service->id) !== null) {
            return 'Please pay the open renewal invoice first.';
        }
        if (new DateTimeImmutable($service->nextDueAt, new DateTimeZone('UTC')) <= $this->clock->now()) {
            return 'Please pay the open renewal invoice first.';
        }
        if ($selfService && $this->changer($service) === null) {
            return 'Please contact our support to change the package of this service.';
        }

        return null;
    }

    /**
     * Products the service could switch to.
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(Service $service, bool $selfService): array
    {
        $current = $service->productId !== null ? $this->products->find($service->productId) : null;
        if ($current === null) {
            return [];
        }
        $group = trim((string) ($current['upgrade_group'] ?? ''));
        if ($selfService && $group === '') {
            return [];
        }
        // The target must offer the service's billing cycle (its own or an additional one; filtered below).
        $sql = 'SELECT `id` FROM `nx_products` WHERE `id` <> ? AND `category` = ? AND `currency` = ? AND `is_active` = 1
                  AND ' . ($current['integration_id'] !== null ? '`integration_id` = ?' : '`integration_id` IS NULL');
        $params = [(int) $current['id'], (string) $current['category'], (string) $current['currency']];
        if ($current['integration_id'] !== null) {
            $params[] = (int) $current['integration_id'];
        }
        if ($selfService) {
            $sql .= ' AND `upgrade_group` = ? AND `is_visible` = 1';
            $params[] = $group;
        }
        $owner = $this->users->find($service->userId);
        $products = [];
        foreach ($this->db->fetchAll($sql . ' ORDER BY `price`, `sort_order`, `name`', $params) as $row) {
            $product = $this->products->find((int) $row['id']);
            if ($product === null || !ProductPricing::offersCycle($product, $service->billingCycle)) {
                continue;
            }
            // Products reserved for a customer group are only offered to its members.
            if ($selfService && $product['customer_group_id'] !== null && (int) $product['customer_group_id'] !== $owner?->customerGroupId) {
                continue;
            }
            $products[] = $product;
        }

        return $products;
    }

    /**
     * Price, direction and blocker of switching to one product.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function describe(Service $service, User $owner, array $product, bool $selfService): array
    {
        $recurring = $this->recurringPrice($service, $product, $owner);
        $difference = $recurring - $service->recurringAmount;
        $current = $service->productId !== null ? $this->products->find($service->productId) : null;
        // A more expensive product (list price) is an upgrade even when discounts make the
        // difference zero: it applies at once, never "free at the end of the period".
        // Configured servers: the list price is only the configurator's minimum, so only their price counts.
        $configured = is_array($service->config['_options'] ?? null) && isset($service->config['_options']['cores']);
        $direction = $difference > 0 || (!$configured && $current !== null && $this->cycleList($service, $product) > $this->cycleList($service, $current)) ? self::UPGRADE : self::DOWNGRADE;
        $charge = $direction === self::UPGRADE ? $this->prorate($service, max(0, $difference)) : 0;

        $blocker = null;
        if ($product['stock'] !== null && (int) $product['stock'] <= 0) {
            $blocker = 'This package is sold out.';
        }
        $provisioner = $this->changer($service);
        if ($blocker === null && $provisioner !== null) {
            $blocker = $provisioner->packageChangeBlocker($service, $this->newConfig($service, $product, $provisioner));
        }
        if ($blocker === null && $selfService && $provisioner === null) {
            $blocker = 'Please contact our support to change the package of this service.';
        }
        $rate = (int) $product['tax_rate_bp'];

        return [
            'product' => $product,
            'from_name' => $current !== null ? (string) $current['name'] : $service->name,
            'direction' => $direction,
            'recurring' => $recurring,
            'recurring_gross' => $recurring + Money::tax($recurring, $rate),
            'charge' => $charge,
            'charge_gross' => $charge + Money::tax($charge, $rate),
            'effective_at' => $direction === self::DOWNGRADE ? $service->nextDueAt : null,
            'blocker' => $blocker,
        ];
    }

    /**
     * Renewal price of a product for this customer: customer-group discount
     * and a permanent coupon of the original order are kept.
     *
     * @param array<string, mixed> $product
     */
    private function recurringPrice(Service $service, array $product, User $owner, ?int $listPrice = null): int
    {
        // List price in the service's billing cycle (configured price scaled like the product price).
        $price = $this->cycleList($service, $product, $listPrice);
        // A promotion of the original order stays for new resources of the same product.
        if ($listPrice !== null && $service->orderItemId !== null && (int) $product['id'] === $service->productId) {
            $price -= Money::discount($price, (int) ($this->db->fetchValue('SELECT `promo_bp` FROM `nx_order_items` WHERE `id` = ?', [$service->orderItemId]) ?? 0));
        }
        $group = $owner->customerGroupId !== null ? $this->groups->find($owner->customerGroupId) : null;
        $base = $price - Money::discount($price, $group !== null ? (int) $group['discount_bp'] : 0);
        if ($service->orderItemId !== null) {
            $coupon = $this->db->fetchOne(
                "SELECT c.`type`, c.`value`, c.`currency`, c.`product_ids` FROM `nx_order_items` oi JOIN `nx_orders` o ON o.`id` = oi.`order_id`
                   JOIN `nx_coupons` c ON c.`id` = o.`coupon_id` WHERE oi.`id` = ? AND c.`duration` = 'forever'",
                [$service->orderItemId],
            );
            // The coupon only follows the service to products it is valid for.
            $allowed = array_filter(array_map('intval', explode(',', (string) ($coupon['product_ids'] ?? ''))));
            if ($coupon !== null && ($allowed === [] || in_array((int) $product['id'], $allowed, true))) {
                $base -= $coupon['type'] === 'percent'
                    ? Money::discount($base, (int) $coupon['value'])
                    : (strtoupper((string) $coupon['currency']) === strtoupper($service->currency) ? min((int) $coupon['value'], $base) : 0);
            }
        }

        return max(0, $base);
    }

    /**
     * List price of a product for one period in the service's billing cycle.
     *
     * @param array<string, mixed> $product
     * @param int|null $basePrice price in the product's own cycle (configured price)
     */
    private function cycleList(Service $service, array $product, ?int $basePrice = null): int
    {
        $base = $basePrice ?? (int) $product['price'];

        return ProductPricing::offersCycle($product, $service->billingCycle) ? ProductPricing::cyclePrice($product, $service->billingCycle, $base) : $base;
    }

    /** Difference per period × remaining share of the paid time (rounded to minor units). */
    private function prorate(Service $service, int $difference): int
    {
        return $this->share($service, $difference, $this->clock->now(), $this->periodEnd($service));
    }

    /** Difference per period × the share of [from, to] in billing periods (the period ending at "to" is the measure). */
    private function share(Service $service, int $difference, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $months = BillingCycle::months($service->billingCycle);
        $first = $to->setDate((int) $to->format('Y'), (int) $to->format('n') - $months, 1);
        $start = $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min((int) $to->format('j'), (int) $first->format('t')));
        $length = $to->getTimestamp() - $start->getTimestamp();
        $remaining = $to->getTimestamp() - $from->getTimestamp();
        if ($length <= 0 || $remaining <= 0) {
            return 0;
        }

        return (int) round($difference * $remaining / $length);
    }

    private function periodEnd(Service $service): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $service->nextDueAt, new DateTimeZone('UTC'));
    }

    /**
     * Service configuration after the change: package values from the
     * product, everything else unchanged.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function newConfig(Service $service, array $product, ?PackageChangeable $provisioner): array
    {
        $config = $service->config;
        $target = is_array($product['provisioning_config']) ? $product['provisioning_config'] : [];
        $keys = $provisioner !== null ? $provisioner->packageKeys($service) : array_keys($target);
        foreach ($keys as $key) {
            if ($key !== '_options' && array_key_exists($key, $target)) {
                $config[$key] = $target[$key];
            }
        }

        return $config;
    }

    /**
     * Configuration of a configured service with new resources.
     *
     * @param array<string, mixed> $choice option name => value
     * @return array<string, mixed>
     */
    private function configuredConfig(Service $service, array $choice): array
    {
        $choice = array_map('intval', array_intersect_key($choice, array_flip(ProductConfigurator::optionNames($service->category))));
        $config = ProductConfigurator::applyChoice($service->category, $service->config, $choice);
        $config['_options'] = $choice + (is_array($service->config['_options'] ?? null) ? $service->config['_options'] : []);

        return $config;
    }

    /**
     * @param array<string, int> $configuration
     */
    private function summary(array $configuration, string $locale, string $category): string
    {
        $parts = [];
        foreach (ProductConfigurator::summary($category, $configuration) as $part) {
            $parts[] = $this->translator->translateTo($locale, $part['text'], $part['params']);
        }

        return implode(', ', $parts);
    }

    /** The provisioner, if the service is managed automatically and supports package changes. */
    private function changer(Service $service): ?PackageChangeable
    {
        if (!$this->provisioners->supportsService($service)) {
            return null;
        }
        $provisioner = $this->provisioners->for($service->category);

        return $provisioner instanceof PackageChangeable ? $provisioner : null;
    }
}
