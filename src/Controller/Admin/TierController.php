<?php

declare(strict_types=1);

namespace Nebrix\Controller\Admin;

use InvalidArgumentException;
use Nebrix\Billing\Money;
use Nebrix\Finance\Tiers\TierService;
use Nebrix\Http\Controller;
use Nebrix\Http\HttpException;
use Nebrix\Http\Request;
use Nebrix\Http\Response;
use Nebrix\Repository\CustomerGroupRepository;
use Nebrix\Security\ValidationException;
use Nebrix\Settings\SettingsRepository;

/**
 * Finance → Reseller tiers (permission customer_groups.manage): rules,
 * overview and manual evaluation. Changes are audit-logged.
 */
final class TierController extends Controller
{
    public function __construct(
        private readonly TierService $tiers,
        private readonly CustomerGroupRepository $groups,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render($request, 'admin/finance/tiers.twig', [
            'rules' => $this->tiers->rules(),
            'groups' => $this->groups->options(),
            'overview' => $this->tiers->overview(),
            'enabled' => $this->tiers->enabled(),
            'currency' => $this->currency(),
            'period' => $this->settings->int('tiers.downgrade_period_days'),
        ]);
    }

    public function store(Request $request): Response
    {
        $id = $this->save($request, null);
        $this->audit($request, 'tier.rule_created', 'tier_rule', $id);
        $this->flash($request, 'success', 'The tier rule has been saved.');

        return $this->redirect('/admin/tiers');
    }

    public function update(Request $request, int $id): Response
    {
        $this->tiers->rule($id) ?? throw HttpException::notFound();
        $this->save($request, $id);
        $this->audit($request, 'tier.rule_updated', 'tier_rule', $id);
        $this->flash($request, 'success', 'The tier rule has been saved.');

        return $this->redirect('/admin/tiers');
    }

    public function delete(Request $request, int $id): Response
    {
        $rule = $this->tiers->rule($id) ?? throw HttpException::notFound();
        $this->tiers->deleteRule($id);
        $this->audit($request, 'tier.rule_deleted', 'tier_rule', $id, ['name' => $rule['name']]);
        $this->flash($request, 'success', 'The tier rule has been deleted.');

        return $this->redirect('/admin/tiers');
    }

    public function evaluate(Request $request): Response
    {
        if (!$this->tiers->enabled()) {
            $this->flash($request, 'error', 'Automatic tiers are switched off in the finance settings.');

            return $this->redirect('/admin/tiers');
        }
        $result = $this->tiers->evaluate();
        $this->audit($request, 'tier.evaluated', 'tier_rule', null, $result);
        $this->flash($request, 'success', 'The tiers have been evaluated. Changes are listed below and in the audit log.');

        return $this->redirect('/admin/tiers');
    }

    private function save(Request $request, ?int $id): int
    {
        $data = $this->validate($request, [
            'name' => 'required|string|max:100',
            'customer_group_id' => 'required|int|min:1',
            'metric' => 'required|in:' . implode(',', TierService::METRICS),
            'threshold' => 'required|regex:/^\d{1,9}([.,]\d{1,2})?$/',
            'tier_rank' => 'required|int|min:1|max:100',
            'is_active' => 'bool',
        ]);
        $threshold = (string) $data['threshold'];
        if ($data['metric'] === TierService::SERVICES) {
            if (preg_match('/^\d{1,6}$/', $threshold) !== 1) {
                throw ValidationException::withMessages(['threshold' => 'Enter a whole number of services.']);
            }
            $value = (int) $threshold;
        } else {
            $value = Money::parse($threshold, $this->currency());
        }
        try {
            return $this->tiers->saveRule($id, [
                'name' => (string) $data['name'],
                'customer_group_id' => (int) $data['customer_group_id'],
                'metric' => (string) $data['metric'],
                'threshold' => $value,
                'tier_rank' => (int) $data['tier_rank'],
                'is_active' => (bool) $data['is_active'],
            ], $this->user($request)->id);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['customer_group_id' => $exception->getMessage()]);
        }
    }

    private function currency(): string
    {
        return strtoupper($this->settings->string('general.default_currency') ?: 'EUR');
    }
}
