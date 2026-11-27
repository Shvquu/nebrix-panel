<?php

declare(strict_types=1);

namespace Nebrix\Controller\Customer;

use Nebrix\Billing\Money;
use Nebrix\Billing\Tax\TaxService;
use Nebrix\Catalog\BillingCycle;
use Nebrix\Catalog\ProductCategory;
use Nebrix\Customer\SupportPin;
use Nebrix\Database\Connection;
use Nebrix\Discord\DiscordService;
use Nebrix\Http\Controller;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Repository\ProductRepository;
use Nebrix\Security\RateLimiter;
use Nebrix\Service\Service;
use Nebrix\Service\ServiceRepository;
use Nebrix\Settings\SettingsRepository;

/**
 * Customer portal start page: profile card, services by product group,
 * key figures (support PIN, products, monthly costs) and the Discord link.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly SettingsRepository $settings,
        private readonly Connection $db,
        private readonly ProductRepository $products,
        private readonly TaxService $taxes,
        private readonly SupportPin $supportPin,
        private readonly DiscordService $discord,
        private readonly RateLimiter $limiter,
        private readonly \Nebrix\Status\StatusService $status,
    ) {
    }

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $services = array_values(array_filter(
            $this->services->forUser($user->id),
            static fn (Service $s): bool => !in_array($s->status, [Service::STATUS_TERMINATED, Service::STATUS_CANCELLED], true),
        ));
        $unpaid = $this->db->fetchAll("SELECT `id`, `number`, `type`, `total`, `currency`, `due_at` FROM `nx_invoices` WHERE `user_id` = ? AND `status` = 'unpaid' ORDER BY `due_at`", [$user->id]);
        $currency = $this->settings->string('general.default_currency') ?: 'EUR';

        // Services by portal group; order buttons for the categories that can be ordered.
        $groups = ProductCategory::groups();
        $byGroup = array_fill_keys(array_keys($groups), []);
        foreach ($services as $service) {
            $byGroup[ProductCategory::groupOf($service->category)][] = $service;
        }
        $orderable = [];
        foreach ($this->products->orderable($user->customerGroupId) as $product) {
            $orderable[ProductCategory::groupOf((string) $product['category'])][(string) $product['category']] = ProductCategory::labels()[(string) $product['category']] ?? (string) $product['category'];
        }

        // Monthly costs: recurring amounts incl. the customer's VAT, per month.
        $tax = $this->taxes->decide($user);
        $monthly = 0;
        foreach ($services as $service) {
            $months = BillingCycle::months($service->billingCycle);
            if ($months > 0 && in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true) && ($service->attributes['cancel_at'] ?? null) === null) {
                $gross = $service->recurringAmount + Money::tax($service->recurringAmount, $tax->rate($service->taxRateBp));
                $monthly += intdiv($gross + intdiv($months, 2), $months);
            }
        }

        return $this->render($request, 'customer/dashboard.twig', [
            'status_notices' => $this->status->banner(),
            'groups' => $groups,
            'by_group' => $byGroup,
            'orderable' => $orderable,
            'product_count' => count($services),
            'monthly_costs' => $monthly,
            'unpaid' => $unpaid,
            'unpaid_total' => array_sum(array_map(static fn (array $i): int => (int) $i['total'], $unpaid)),
            'open_tickets' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM `nx_tickets` WHERE `user_id` = ? AND `status` <> 'closed'", [$user->id]),
            'notice' => $this->settings->string('branding.dashboard_welcome'),
            'currency' => $currency,
            'support_pin' => $this->supportPin->forUser($user),
            'discord_enabled' => $this->discord->isEnabled(),
            'discord' => $this->discord->link($user->id),
            'discord_invite' => $this->discord->inviteUrl(),
        ]);
    }

    public function regenerateSupportPin(Request $request): Response
    {
        $user = $this->user($request);
        if (!$this->limiter->attempt('support-pin-new:' . $user->id, 5, 3600)) {
            $this->flash($request, 'error', 'Please wait a moment before requesting a new support PIN.');

            return $this->redirect('/dashboard');
        }
        $this->supportPin->regenerate($user);
        $this->audit($request, 'account.support_pin_renewed', 'user', $user->id);
        $this->flash($request, 'success', 'A new support PIN has been created.');

        return $this->redirect('/dashboard');
    }
}
