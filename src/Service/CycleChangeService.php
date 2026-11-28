<?php

declare(strict_types=1);

namespace Nebrix\Service;

use DateTimeImmutable;
use DateTimeZone;
use Nebrix\Audit\AuditLogger;
use Nebrix\Billing\InvoiceRepository;
use Nebrix\Billing\Money;
use Nebrix\Catalog\BillingCycle;
use Nebrix\Catalog\ProductConfigurator;
use Nebrix\Catalog\ProductPricing;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Repository\ProductRepository;

/**
 * The customer switches the billing cycle of a service for the next term
 * (e.g. from monthly to annually). Nothing changes in the paid term: the
 * next renewal invoice is issued for a period of the new cycle at the new
 * price (InvoiceService::createRenewal()).
 *
 * New price = current renewal price × list price of the new cycle / list
 * price of the current cycle. All percentage discounts the service has
 * (promotion, sale, bundle, customer group, coupon) therefore carry over
 * unchanged, the cycle's own discount applies and nothing is counted twice.
 */
class CycleChangeService
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly ServiceRepository $services,
        private readonly ProductRepository $products,
        private readonly InvoiceRepository $invoices,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * What the customer can choose (null when the product offers one cycle only).
     *
     * @return array{blocker: string|null, current: string, pending: string|null, pending_amount: int|null, cycles: list<array{cycle: string, recurring: int, per_month: int, saving_bp: int}>}|null
     */
    public function options(Service $service): ?array
    {
        $product = $service->productId !== null ? $this->products->find($service->productId) : null;
        if ($product === null || !BillingCycle::isRecurring($service->billingCycle) || count(ProductPricing::cycles($product)) < 2 || !ProductPricing::offersCycle($product, $service->billingCycle)) {
            return null;
        }
        $cycles = [];
        $current = $this->listPrice($service, $product, $service->billingCycle);
        foreach (array_keys(ProductPricing::cycles($product)) as $cycle) {
            $recurring = $this->priceFor($service, $product, $cycle);
            $months = max(1, BillingCycle::months($cycle));
            $currentPerMonth = $current > 0 ? $current / max(1, BillingCycle::months($service->billingCycle)) : 0;
            $cycles[] = [
                'cycle' => $cycle,
                'recurring' => $recurring,
                'per_month' => intdiv($recurring * 2 + $months, 2 * $months),
                // Saving per month compared with the current cycle (list prices), in basis points.
                'saving_bp' => $currentPerMonth > 0 ? (int) round((1 - ($this->listPrice($service, $product, $cycle) / $months) / $currentPerMonth) * 10000) : 0,
            ];
        }

        return [
            'blocker' => $this->blocker($service),
            'current' => $service->billingCycle,
            'pending' => ($service->attributes['next_billing_cycle'] ?? null) !== null ? (string) $service->attributes['next_billing_cycle'] : null,
            'pending_amount' => isset($service->attributes['next_recurring_amount']) ? (int) $service->attributes['next_recurring_amount'] : null,
            'cycles' => $cycles,
        ];
    }

    /**
     * @throws ServiceChangeException
     */
    public function request(Service $service, string $cycle, ?int $actorId, ?string $ip): int
    {
        $amount = $this->db->transaction(function () use ($service, $cycle): int {
            // Locked like a package change: no renewal or package change in between.
            $this->db->fetchValue('SELECT `id` FROM `nx_services` WHERE `id` = ? FOR UPDATE', [$service->id]);
            $service = $this->services->find($service->id) ?? throw new ServiceChangeException('The service was not found.');
            $blocker = $this->blocker($service);
            if ($blocker !== null) {
                throw new ServiceChangeException($blocker);
            }
            $product = $service->productId !== null ? $this->products->find($service->productId) : null;
            if ($product === null || $cycle === $service->billingCycle || !ProductPricing::offersCycle($product, $cycle) || !ProductPricing::offersCycle($product, $service->billingCycle)) {
                throw new ServiceChangeException('This billing cycle is not available for this service.');
            }
            $amount = $this->priceFor($service, $product, $cycle);
            $this->services->update($service->id, ['next_billing_cycle' => $cycle, 'next_recurring_amount' => $amount]);

            return $amount;
        });
        $this->audit->log($actorId, 'service.cycle_change_requested', 'service', $service->id, ['from' => $service->billingCycle, 'to' => $cycle, 'recurring' => $amount], $ip);

        return $amount;
    }

    /**
     * @throws ServiceChangeException
     */
    public function withdraw(Service $service, ?int $actorId, ?string $ip): void
    {
        $this->db->transaction(function () use ($service): void {
            $this->db->fetchValue('SELECT `id` FROM `nx_services` WHERE `id` = ? FOR UPDATE', [$service->id]);
            $current = $this->services->find($service->id);
            if ($current === null || ($current->attributes['next_billing_cycle'] ?? null) === null) {
                throw new ServiceChangeException('There is no change of the billing cycle that can be taken back.');
            }
            $this->services->update($service->id, ['next_billing_cycle' => null, 'next_recurring_amount' => null]);
        });
        $this->audit->log($actorId, 'service.cycle_change_withdrawn', 'service', $service->id, [], $ip);
    }

    private function blocker(Service $service): ?string
    {
        if ($service->status !== Service::STATUS_ACTIVE) {
            return 'Only active services can change their billing cycle.';
        }
        if ($service->nextDueAt === null || ($service->attributes['cancel_at'] ?? null) !== null) {
            return 'The service has been cancelled; its billing cycle can no longer be changed.';
        }
        if ($this->invoices->openRenewalFor($service->id) !== null || new DateTimeImmutable($service->nextDueAt, new DateTimeZone('UTC')) <= $this->clock->now()) {
            return 'The next term has already been invoiced. Please pay the open renewal invoice first.';
        }
        $open = $this->db->fetchValue("SELECT COUNT(*) FROM `nx_service_changes` WHERE `service_id` = ? AND `status` IN ('pending_payment', 'scheduled', 'queued')", [$service->id]);
        if ((int) $open > 0) {
            return 'A package change is already in progress.';
        }

        return null;
    }

    /**
     * Renewal price of the service in another cycle of its product.
     *
     * @param array<string, mixed> $product
     */
    private function priceFor(Service $service, array $product, string $cycle): int
    {
        if ($cycle === $service->billingCycle) {
            return $service->recurringAmount;
        }
        $current = $this->listPrice($service, $product, $service->billingCycle);
        $target = $this->listPrice($service, $product, $cycle);
        if ($current <= 0) {
            return $target;
        }

        return max(0, intdiv($service->recurringAmount * $target * 2 + $current, 2 * $current));
    }

    /**
     * List price of the service's product (with its configured resources) in a cycle.
     *
     * @param array<string, mixed> $product
     */
    private function listPrice(Service $service, array $product, string $cycle): int
    {
        // Configured services: the price of their resources (values clamped into the current ranges).
        $base = ProductConfigurator::enabled($product)
            ? ProductConfigurator::resolve($product, ProductConfigurator::choiceOf($service->category, $service->config), false)['price']
            : (int) $product['price'];

        return ProductPricing::cyclePrice($product, $cycle, $base);
    }

    /** Monthly amount for display (rounded half up). */
    public static function perMonth(int $amount, string $cycle): int
    {
        $months = max(1, BillingCycle::months($cycle));

        return intdiv($amount * 2 + $months, 2 * $months);
    }

    /** Gross amount for display with the product's rate. */
    public static function gross(int $net, int $rateBp): int
    {
        return $net + Money::tax($net, $rateBp);
    }
}
