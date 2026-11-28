<?php

declare(strict_types=1);

namespace Nebrix\Service\Addon;

use DateTimeImmutable;
use DateTimeZone;
use Nebrix\Audit\AuditLogger;
use Nebrix\Auth\User;
use Nebrix\Billing\InvoiceRepository;
use Nebrix\Billing\InvoiceService;
use Nebrix\Billing\Money;
use Nebrix\Billing\OrderRepository;
use Nebrix\Catalog\BillingCycle;
use Nebrix\Core\Clock;
use Nebrix\Core\Config;
use Nebrix\Database\Connection;
use Nebrix\Mail\Mailer;
use Nebrix\Payment\PaymentService;
use Nebrix\Provisioning\RetryLaterException;
use Nebrix\Queue\JobQueue;
use Nebrix\Queue\PermanentJobFailure;
use Nebrix\Repository\CustomerGroupRepository;
use Nebrix\Repository\ProductRepository;
use Nebrix\Repository\UserRepository;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceChangeException;
use Nebrix\Service\ServiceRepository;
use Nebrix\View\Translator;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Booked add-ons of services.
 *
 * Booking: with the order (cart / buy now, an order item per add-on) or
 * later on the service page (prorated invoice for the rest of the paid
 * term plus the setup fee). After a verified payment the add-on is set up
 * in a queued job by the first AddonProvisioner that supports its kind –
 * or, without one, by staff (Admin → Add-ons to set up). Renewal invoices
 * carry every live add-on as a separate line.
 *
 * Cancellation: like a service, at the end of the paid term, confirmed by
 * e-mail at once and possible to take back until then. Add-ons end with
 * their service (termination) and are removed by their provisioner or by
 * staff; a suspended service suspends its add-ons (hasActive() is false).
 */
class AddonService
{
    public const JOB_PROVISION = InvoiceService::JOB_PROVISION_ADDON;
    public const JOB_DEPROVISION = 'addon.deprovision';

    /** Unpaid booking invoices are cancelled after this many days. */
    private const PAYMENT_DAYS = 14;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AddonRepository $addons,
        private readonly AddonProvisioners $provisioners,
        private readonly ServiceRepository $services,
        private readonly ProductRepository $products,
        private readonly UserRepository $users,
        private readonly CustomerGroupRepository $groups,
        private readonly OrderRepository $orders,
        private readonly InvoiceService $invoices,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PaymentService $payments,
        private readonly JobQueue $jobs,
        private readonly Mailer $mailer,
        private readonly AuditLogger $audit,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Whether the customer has an active add-on of this kind on an active
     * service (e.g. priority support for tickets). Suspended or ended
     * services and add-ons whose cancellation date has passed do not count.
     */
    public function hasActive(int $userId, string $kind): bool
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM `nx_service_addons` a JOIN `nx_services` s ON s.`id` = a.`service_id`
              WHERE a.`user_id` = ? AND a.`kind` = ? AND a.`status` = 'active' AND s.`status` = 'active' AND s.`user_id` = a.`user_id`
                AND (a.`cancel_at` IS NULL OR a.`cancel_at` > ?)",
            [$userId, $kind, $this->clock->nowString()],
        ) > 0;
    }

    /**
     * Add-ons the customer can book for the service now, with prices.
     *
     * @return array{blocker: string|null, booked: list<array<string, mixed>>, offers: list<array<string, mixed>>}
     */
    public function overview(Service $service, User $owner): array
    {
        $blocker = $this->blocker($service);
        $offers = [];
        foreach ($this->addons->offeredFor($this->productOf($service)) as $addon) {
            $available = max(0, (int) $addon['max_quantity'] - $this->addons->bookedQuantity($service->id, (int) $addon['id']));
            $price = $this->price($service, $owner, $addon, 1);
            // Net prices of one unit (the page says "plus VAT"; the invoice applies the customer's tax).
            $offers[] = $addon + [
                'available' => $available,
                'recurring' => $price['recurring'],
                'charge' => $price['charge'],
                'setup' => $price['setup'],
            ];
        }

        return ['blocker' => $blocker, 'booked' => $this->addons->forService($service->id), 'offers' => $offers];
    }

    /**
     * Books an add-on for a running service: invoice for the rest of the
     * term; set up after payment.
     *
     * @return array{service_addon_id: int, invoice_id: int, paid: bool}
     *
     * @throws ServiceChangeException with a message for the customer
     */
    public function book(Service $service, User $owner, int $addonId, int $quantity, ?int $actorId, ?string $ip): array
    {
        $result = $this->db->transaction(function () use ($service, $owner, $addonId, $quantity): array {
            // The service row is locked: no renewal, package change or second booking in between.
            $this->db->fetchValue('SELECT `id` FROM `nx_services` WHERE `id` = ? FOR UPDATE', [$service->id]);
            $service = $this->services->find($service->id) ?? throw new ServiceChangeException('The service was not found.');
            $blocker = $this->blocker($service);
            if ($blocker !== null) {
                throw new ServiceChangeException($blocker);
            }
            $addon = $this->db->fetchOne('SELECT * FROM `nx_addons` WHERE `id` = ? FOR UPDATE', [$addonId]);
            $addon = $addon !== null ? AddonRepository::decode($addon) : null;
            if ($addon === null || !AddonRepository::offers($addon, $this->productOf($service))) {
                throw new ServiceChangeException('This add-on is not available for this service.');
            }
            if ($quantity < 1 || $this->addons->bookedQuantity($service->id, $addonId) + $quantity > (int) $addon['max_quantity']) {
                throw new ServiceChangeException('This quantity is not available for this add-on.');
            }
            $price = $this->price($service, $owner, $addon, $quantity);
            $start = $this->clock->now();
            $end = new DateTimeImmutable((string) $service->nextDueAt, new DateTimeZone('UTC'));
            $invoiceId = $this->invoices->createAddon($service, $owner, $addon, $quantity, $price['charge'], $price['setup'], $start, $end);
            $id = $this->addons->create([
                'service_id' => $service->id,
                'user_id' => $owner->id,
                'addon_id' => $addonId,
                'kind' => (string) $addon['kind'],
                'name' => (string) $addon['name'],
                'quantity' => $quantity,
                'unit_price' => (int) $addon['price'],
                'recurring_amount' => $price['recurring'],
                'tax_rate_bp' => (int) $addon['tax_rate_bp'],
                'currency' => $service->currency,
                'status' => AddonRepository::PENDING_PAYMENT,
                'invoice_id' => $invoiceId,
            ]);
            $paid = false;
            $total = (int) $this->db->fetchValue('SELECT `total` FROM `nx_invoices` WHERE `id` = ?', [$invoiceId]);
            if ($total === 0) {
                // Nothing to pay (e.g. free add-on): settled at once like free orders.
                $paid = $this->payments->recordPayment('free', 'free-invoice-' . $invoiceId, $invoiceId, 0, $service->currency)->isSuccessful();
            }

            return ['service_addon_id' => $id, 'invoice_id' => $invoiceId, 'paid' => $paid];
        });
        $this->audit->log($actorId, 'addon.booked', 'service', $service->id, ['addon' => $addonId, 'quantity' => $quantity, 'service_addon' => $result['service_addon_id'], 'invoice' => $result['invoice_id']], $ip);

        return $result;
    }

    /**
     * Cancels a booked add-on at the end of the paid term (an unpaid
     * booking is withdrawn at once). Returns the end date (null: withdrawn).
     *
     * @throws ServiceChangeException
     */
    public function cancel(Service $service, int $serviceAddonId, int $userId, ?string $ip): ?string
    {
        $outcome = $this->db->transaction(function () use ($service, $serviceAddonId, $userId): array {
            $this->db->fetchValue('SELECT `id` FROM `nx_services` WHERE `id` = ? FOR UPDATE', [$service->id]);
            $addon = $this->addons->bookedForUser($serviceAddonId, $service->id, $userId);
            if ($addon === null) {
                throw new ServiceChangeException('The add-on was not found.');
            }
            $addon = $this->addons->lockBooked($serviceAddonId) ?? $addon;
            if ($addon['status'] === AddonRepository::PENDING_PAYMENT && $addon['invoice_id'] !== null) {
                return ['invoice' => (int) $addon['invoice_id'], 'end' => null, 'addon' => $addon];
            }
            if (!in_array($addon['status'], AddonRepository::LIVE, true) || $addon['cancel_at'] !== null) {
                throw new ServiceChangeException('This add-on cannot be cancelled.');
            }
            $current = $this->services->find($service->id) ?? throw new ServiceChangeException('The service was not found.');
            // End of the paid term (an earlier end of the service ends the add-on as well).
            $end = $current->nextDueAt !== null && $current->nextDueAt > $this->clock->nowString() ? $current->nextDueAt : $this->clock->nowString();
            $this->addons->update($serviceAddonId, ['cancel_at' => $end, 'cancelled_at' => $this->clock->nowString()]);
            // An unpaid renewal for the following term was issued with the add-on: it is issued again without it.
            $renewal = $this->invoiceRepository->openRenewalFor($service->id);

            return ['invoice' => null, 'end' => $end, 'addon' => $addon, 'renewal' => $renewal !== null ? (int) $renewal['id'] : null];
        });

        if ($outcome['end'] === null) {
            try {
                $this->invoices->cancel((int) $outcome['invoice']);
            } catch (\RuntimeException) {
                throw new ServiceChangeException('The booking invoice has already been paid.');
            }
            $this->audit->log($userId, 'addon.booking_withdrawn', 'service', $service->id, ['service_addon' => $serviceAddonId], $ip);

            return null;
        }
        if (($outcome['renewal'] ?? null) !== null) {
            try {
                $this->invoices->cancel((int) $outcome['renewal']);
                $updated = $this->services->find($service->id);
                if ($updated !== null) {
                    $this->invoices->createRenewal($updated);
                }
            } catch (Throwable $exception) {
                $this->logger->error('Renewal invoice could not be replaced after an add-on cancellation', ['service' => $service->id, 'invoice' => $outcome['renewal'], 'message' => $exception->getMessage()]);
            }
        }

        $this->audit->log($userId, 'addon.cancelled', 'service', $service->id, ['service_addon' => $serviceAddonId, 'effective_at' => $outcome['end']], $ip);
        $owner = $this->users->find($service->userId);
        if ($owner !== null) {
            $locale = $this->mailer->localeFor($owner->email);
            // Confirmation on a durable medium, sent at once (like contract cancellations).
            $this->mailer->queue($owner->email, $owner->displayName(), 'addon_cancelled', [
                'name' => $owner->displayName(),
                'addon_name' => (string) $outcome['addon']['name'],
                'service_name' => $service->name,
                'received_at' => $this->clock->nowString() . ' UTC',
                'effective_date' => Translator::date($locale, new DateTimeImmutable((string) $outcome['end'], new DateTimeZone('UTC'))),
                'service_url' => $this->url('/services/' . $service->id),
            ]);
        }

        return (string) $outcome['end'];
    }

    /**
     * Takes a cancellation back before it takes effect.
     *
     * @throws ServiceChangeException
     */
    public function withdrawCancellation(Service $service, int $serviceAddonId, int $userId, ?string $ip): void
    {
        $this->db->transaction(function () use ($service, $serviceAddonId, $userId): void {
            $addon = $this->addons->bookedForUser($serviceAddonId, $service->id, $userId);
            $addon = $addon !== null ? $this->addons->lockBooked($serviceAddonId) : null;
            if ($addon === null || $addon['cancel_at'] === null || (string) $addon['cancel_at'] <= $this->clock->nowString() || !in_array($addon['status'], AddonRepository::LIVE, true)) {
                throw new ServiceChangeException('There is no pending cancellation for this add-on.');
            }
            if ($this->invoiceRepository->openRenewalFor($service->id) !== null) {
                throw new ServiceChangeException('The next term has already been invoiced. Please book the add-on again after the renewal.');
            }
            $this->addons->update($serviceAddonId, ['cancel_at' => null, 'cancelled_at' => null]);
        });
        $this->audit->log($userId, 'addon.cancellation_withdrawn', 'service', $service->id, ['service_addon' => $serviceAddonId], $ip);
    }

    /**
     * Add-ons booked with a paid order (called by the fulfilment job after
     * the services were created). Idempotent: one add-on per order item.
     */
    public function fulfilOrder(int $orderId): void
    {
        $order = $this->orders->find($orderId);
        if ($order === null || $order['status'] !== OrderRepository::STATUS_PAID) {
            return;
        }
        foreach ($this->orders->items($orderId) as $item) {
            if (($item['addon_id'] ?? null) === null || ($item['parent_item_id'] ?? null) === null) {
                continue;
            }
            $service = $this->services->findByOrderItem((int) $item['parent_item_id']);
            if ($service === null) {
                $this->logger->warning('Add-on of an order without its service', ['order' => $orderId, 'item' => $item['id']]);
                continue;
            }
            $id = (int) ($this->db->fetchValue('SELECT `id` FROM `nx_service_addons` WHERE `order_item_id` = ?', [(int) $item['id']]) ?? 0);
            if ($id === 0) {
                $catalog = $this->addons->find((int) $item['addon_id']);
                try {
                    $id = $this->addons->create([
                        'service_id' => $service->id,
                        'user_id' => (int) $order['user_id'],
                        'addon_id' => $catalog !== null ? (int) $catalog['id'] : null,
                        'kind' => (string) ($catalog['kind'] ?? AddonRepository::KIND_CUSTOM),
                        'name' => mb_substr((string) ($catalog['name'] ?? $item['description']), 0, 100),
                        'quantity' => max(1, (int) $item['quantity']),
                        'unit_price' => (int) ($catalog['price'] ?? 0),
                        // Renewal price per billing period: without one-time discounts.
                        'recurring_amount' => max(0, (int) $item['unit_price'] - (int) ($item['recurring_discount'] ?? $item['discount'])),
                        'tax_rate_bp' => (int) ($catalog['tax_rate_bp'] ?? $item['tax_rate_bp']),
                        'currency' => (string) $order['currency'],
                        'status' => AddonRepository::PENDING,
                        'order_item_id' => (int) $item['id'],
                    ]);
                } catch (PDOException $exception) {
                    // A concurrent run created it first (unique order_item_id).
                    if (($exception->errorInfo[1] ?? null) !== 1062) {
                        throw $exception;
                    }
                    $id = (int) $this->db->fetchValue('SELECT `id` FROM `nx_service_addons` WHERE `order_item_id` = ?', [(int) $item['id']]);
                }
            }
            $this->jobs->dispatch(self::JOB_PROVISION, ['service_addon_id' => $id], self::JOB_PROVISION . ':' . $id);
        }
    }

    /**
     * Job: sets a paid add-on up through its provisioner; without one it
     * is handed to staff. Waits while the service itself is being set up.
     */
    public function provision(int $id): void
    {
        $addon = $this->addons->booked($id) ?? throw new PermanentJobFailure('Add-on not found.');
        if ($addon['status'] !== AddonRepository::PENDING) {
            return; // set up, ended or handed to staff – idempotent
        }
        $service = $this->serviceRow((int) $addon['service_id']);
        if ($service === null || in_array($service['status'], [Service::STATUS_TERMINATED, Service::STATUS_CANCELLED], true)) {
            $this->addons->update($id, ['status' => AddonRepository::REMOVED, 'removed_at' => $this->clock->nowString()]);

            return;
        }
        $provisioner = $this->provisioners->for((string) $addon['kind'], $service);
        if ($provisioner === null) {
            if ($addon['fulfilment'] !== 'manual') {
                $this->addons->update($id, ['fulfilment' => 'manual']);
                $this->audit->log(null, 'addon.manual_setup_required', 'service', (int) $addon['service_id'], ['service_addon' => $id, 'kind' => $addon['kind']]);
            }

            return;
        }
        if ($addon['fulfilment'] !== 'auto') {
            $this->addons->update($id, ['fulfilment' => 'auto']);
        }
        if ($service['status'] !== Service::STATUS_ACTIVE) {
            throw new RetryLaterException('The service is not active yet.', 300);
        }

        $details = $provisioner->provision($service, $addon + ['fulfilment' => 'auto']);
        $activated = $this->db->transaction(function () use ($id, $details): bool {
            $row = $this->addons->lockBooked($id);
            if ($row === null || $row['status'] !== AddonRepository::PENDING) {
                return false;
            }
            $this->addons->update($id, ['status' => AddonRepository::ACTIVE, 'details' => $details, 'activated_at' => $this->clock->nowString(), 'last_error' => null]);

            return true;
        });
        if ($activated) {
            $this->audit->log(null, 'addon.provisioned', 'service', (int) $addon['service_id'], ['service_addon' => $id]);
            $this->notifyReady($addon, $details);
        }
    }

    /** The automatic set-up failed after all attempts: staff take over. */
    public function provisioningFailed(int $id, string $error): void
    {
        $this->db->run(
            "UPDATE `nx_service_addons` SET `status` = 'failed', `last_error` = ?, `updated_at` = ? WHERE `id` = ? AND `status` = 'pending'",
            [mb_substr($error, 0, 2000), $this->clock->nowString(), $id],
        );
        $this->audit->log(null, 'addon.provisioning_failed', 'service_addon', $id, ['error' => mb_substr($error, 0, 200)]);
    }

    /**
     * Job: removes an ended add-on through its provisioner (or hands it to
     * staff when none supports it any more).
     */
    public function deprovision(int $id): void
    {
        $addon = $this->addons->booked($id) ?? throw new PermanentJobFailure('Add-on not found.');
        if ($addon['status'] !== AddonRepository::REMOVING) {
            return;
        }
        $service = $this->serviceRow((int) $addon['service_id']) ?? throw new PermanentJobFailure('Service not found.');
        $provisioner = $addon['fulfilment'] === 'auto' ? $this->provisioners->for((string) $addon['kind'], $service) : null;
        if ($provisioner === null) {
            $this->addons->update($id, ['fulfilment' => 'manual']);

            return;
        }
        $provisioner->deprovision($service, $addon);
        $this->db->run(
            "UPDATE `nx_service_addons` SET `status` = 'removed', `removed_at` = ?, `last_error` = NULL, `updated_at` = ? WHERE `id` = ? AND `status` = 'removing'",
            [$this->clock->nowString(), $this->clock->nowString(), $id],
        );
        $this->audit->log(null, 'addon.deprovisioned', 'service', (int) $addon['service_id'], ['service_addon' => $id]);
    }

    /** Automatic removal failed after all attempts: staff remove it by hand. */
    public function deprovisioningFailed(int $id, string $error): void
    {
        $this->db->run(
            "UPDATE `nx_service_addons` SET `fulfilment` = 'manual', `last_error` = ?, `updated_at` = ? WHERE `id` = ? AND `status` = 'removing'",
            [mb_substr($error, 0, 2000), $this->clock->nowString(), $id],
        );
        $this->audit->log(null, 'addon.deprovisioning_failed', 'service_addon', $id, ['error' => mb_substr($error, 0, 200)]);
    }

    /**
     * Staff: the add-on has been set up by hand (details are shown to the customer).
     *
     * @throws ServiceChangeException
     */
    public function markDone(int $id, string $details, int $actorId, ?string $ip): void
    {
        $addon = $this->db->transaction(function () use ($id, $details): array {
            $addon = $this->addons->lockBooked($id);
            if ($addon === null || !in_array($addon['status'], [AddonRepository::PENDING, AddonRepository::FAILED], true)) {
                throw new ServiceChangeException('This add-on is not waiting to be set up.');
            }
            $this->addons->update($id, [
                'status' => AddonRepository::ACTIVE,
                'fulfilment' => 'manual',
                'details' => trim($details) !== '' ? ['info' => mb_substr(trim($details), 0, 1000)] : $addon['details'],
                'activated_at' => $this->clock->nowString(),
                'last_error' => null,
            ]);

            return $addon;
        });
        $this->audit->log($actorId, 'addon.set_up_manually', 'service', (int) $addon['service_id'], ['service_addon' => $id], $ip);
        $this->notifyReady($addon, trim($details) !== '' ? ['info' => trim($details)] : $addon['details']);
    }

    /**
     * Staff: the ended add-on has been removed by hand.
     *
     * @throws ServiceChangeException
     */
    public function markRemoved(int $id, string $note, int $actorId, ?string $ip): void
    {
        $addon = $this->db->transaction(function () use ($id, $note): array {
            $addon = $this->addons->lockBooked($id);
            if ($addon === null || $addon['status'] !== AddonRepository::REMOVING) {
                throw new ServiceChangeException('This add-on is not waiting to be removed.');
            }
            $this->addons->update($id, ['status' => AddonRepository::REMOVED, 'removed_at' => $this->clock->nowString(), 'staff_note' => trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null, 'last_error' => null]);

            return $addon;
        });
        $this->audit->log($actorId, 'addon.removed_manually', 'service', (int) $addon['service_id'], ['service_addon' => $id], $ip);
    }

    /**
     * Staff: try the automatic set-up of a failed add-on again.
     *
     * @throws ServiceChangeException
     */
    public function retry(int $id, int $actorId, ?string $ip): void
    {
        $changed = $this->db->run("UPDATE `nx_service_addons` SET `status` = 'pending', `last_error` = NULL, `updated_at` = ? WHERE `id` = ? AND `status` = 'failed'", [$this->clock->nowString(), $id])->rowCount() > 0;
        if (!$changed) {
            throw new ServiceChangeException('This add-on is not waiting to be set up.');
        }
        $this->jobs->dispatch(self::JOB_PROVISION, ['service_addon_id' => $id], self::JOB_PROVISION . ':' . $id . ':retry:' . $this->clock->now()->getTimestamp());
        $this->audit->log($actorId, 'addon.provisioning_retried', 'service_addon', $id, [], $ip);
    }

    /**
     * Scheduler: ends add-ons whose cancellation date was reached, whose
     * service ended or whose booking was refunded, and cancels booking
     * invoices that stayed unpaid.
     *
     * @return array{ended: int, expired: int}
     */
    public function process(): array
    {
        $now = $this->clock->nowString();
        $ended = 0;
        $due = $this->db->fetchAll(
            "SELECT a.`id` FROM `nx_service_addons` a
               JOIN `nx_services` s ON s.`id` = a.`service_id`
          LEFT JOIN `nx_invoices` i ON i.`id` = a.`invoice_id`
              WHERE a.`status` IN ('pending', 'active', 'failed')
                AND ((a.`cancel_at` IS NOT NULL AND a.`cancel_at` <= ?) OR s.`status` IN ('terminated', 'cancelled') OR i.`status` = 'refunded')
              ORDER BY a.`id` LIMIT 500",
            [$now],
        );
        foreach ($due as $row) {
            if ($this->end((int) $row['id'])) {
                $ended++;
            }
        }

        $expired = 0;
        $before = $this->clock->now()->modify('-' . self::PAYMENT_DAYS . ' days')->format('Y-m-d H:i:s');
        foreach ($this->db->fetchAll(
            "SELECT a.`invoice_id` FROM `nx_service_addons` a JOIN `nx_invoices` i ON i.`id` = a.`invoice_id`
               JOIN `nx_services` s ON s.`id` = a.`service_id`
              WHERE a.`status` = 'pending_payment' AND i.`status` = 'unpaid' AND (a.`created_at` < ? OR s.`status` IN ('terminated', 'cancelled')) LIMIT 200",
            [$before],
        ) as $row) {
            try {
                $this->invoices->cancel((int) $row['invoice_id']);
                $expired++;
            } catch (Throwable $exception) {
                $this->logger->warning('Unpaid add-on invoice could not be cancelled', ['invoice' => $row['invoice_id'], 'message' => $exception->getMessage()]);
            }
        }

        return ['ended' => $ended, 'expired' => $expired];
    }

    /**
     * Ends one add-on: set-up ones are removed (provisioner or staff), ones
     * that were never set up are closed at once.
     */
    private function end(int $id): bool
    {
        $job = $this->db->transaction(function () use ($id): ?bool {
            $addon = $this->addons->lockBooked($id);
            if ($addon === null || !in_array($addon['status'], AddonRepository::LIVE, true)) {
                return null;
            }
            if ($addon['status'] === AddonRepository::PENDING) {
                $this->addons->update($id, ['status' => AddonRepository::REMOVED, 'removed_at' => $this->clock->nowString()]);

                return false;
            }
            // A failed automatic set-up may have left something behind: staff check it.
            $automatic = $addon['status'] === AddonRepository::ACTIVE && $addon['fulfilment'] === 'auto';
            $this->addons->update($id, ['status' => AddonRepository::REMOVING, 'fulfilment' => $automatic ? 'auto' : 'manual']);

            return $automatic;
        });
        if ($job === true) {
            $this->jobs->dispatch(self::JOB_DEPROVISION, ['service_addon_id' => $id], self::JOB_DEPROVISION . ':' . $id);
        }
        if ($job !== null) {
            $this->audit->log(null, 'addon.ended', 'service_addon', $id);
        }

        return $job !== null;
    }

    /**
     * Price of booking an add-on now: per billing period after the group
     * discount, the prorated part up to the end of the paid term and the
     * setup fee (all net).
     *
     * @param array<string, mixed> $addon
     * @return array{recurring: int, charge: int, setup: int}
     */
    private function price(Service $service, User $owner, array $addon, int $quantity): array
    {
        $group = $owner->customerGroupId !== null ? $this->groups->find($owner->customerGroupId) : null;
        $period = (int) $addon['price'] * $quantity * max(1, BillingCycle::months($service->billingCycle));
        $recurring = $period - Money::discount($period, $group !== null ? (int) $group['discount_bp'] : 0);
        $charge = $service->nextDueAt !== null ? self::prorate($recurring, $service->billingCycle, $this->clock->now(), new DateTimeImmutable($service->nextDueAt, new DateTimeZone('UTC'))) : 0;

        return ['recurring' => $recurring, 'charge' => $charge, 'setup' => (int) $addon['setup_fee'] * $quantity];
    }

    /**
     * An amount per billing period × the share of [from, to] in the period
     * ending at "to" (like package changes).
     */
    public static function prorate(int $amount, string $cycle, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $months = BillingCycle::months($cycle);
        if ($months <= 0) {
            return 0;
        }
        $first = $to->setDate((int) $to->format('Y'), (int) $to->format('n') - $months, 1);
        $start = $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min((int) $to->format('j'), (int) $first->format('t')));
        $length = $to->getTimestamp() - $start->getTimestamp();
        $remaining = min($length, $to->getTimestamp() - $from->getTimestamp());
        if ($length <= 0 || $remaining <= 0) {
            return 0;
        }

        return (int) round($amount * $remaining / $length);
    }

    /** Why add-ons cannot be booked for the service now (null = they can). */
    private function blocker(Service $service): ?string
    {
        if ($service->status !== Service::STATUS_ACTIVE) {
            return 'Add-ons can only be booked for active services.';
        }
        if (!BillingCycle::isRecurring($service->billingCycle) || $service->nextDueAt === null) {
            return 'Add-ons cannot be booked for this service.';
        }
        if (($service->attributes['cancel_at'] ?? null) !== null) {
            return 'The service has been cancelled; no add-ons can be booked.';
        }
        if ($this->invoiceRepository->openRenewalFor($service->id) !== null || new DateTimeImmutable($service->nextDueAt, new DateTimeZone('UTC')) <= $this->clock->now()) {
            return 'Please pay the open renewal invoice first.';
        }

        return null;
    }

    /**
     * The product (or, without one, the category) add-ons are offered for.
     *
     * @return array<string, mixed>
     */
    private function productOf(Service $service): array
    {
        $product = $service->productId !== null ? $this->products->find($service->productId) : null;

        return $product ?? ['id' => 0, 'category' => $service->category, 'currency' => $service->currency];
    }

    /**
     * @return array<string, mixed>|null row of nx_services
     */
    private function serviceRow(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_services` WHERE `id` = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $addon
     * @param array<string, mixed> $details
     */
    private function notifyReady(array $addon, array $details): void
    {
        $owner = $this->users->find((int) $addon['user_id']);
        $service = $this->services->find((int) $addon['service_id']);
        if ($owner === null || $service === null) {
            return;
        }
        $lines = [];
        foreach ($details as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $lines[] = ($key === 'info' ? '' : $key . ': ') . (string) $value;
            }
        }
        $this->mailer->queue($owner->email, $owner->displayName(), 'addon_ready', [
            'name' => $owner->displayName(),
            'addon_name' => (string) $addon['name'],
            'service_name' => $service->name,
            'details' => implode("\n", $lines),
            'service_url' => $this->url('/services/' . $service->id),
        ]);
    }

    private function url(string $path): string
    {
        return rtrim($this->config->string('app.url'), '/') . $path;
    }
}
