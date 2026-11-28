<?php

declare(strict_types=1);

namespace Nebrix\Finance\Tiers;

use InvalidArgumentException;
use Nebrix\Audit\AuditLogger;
use Nebrix\Core\Clock;
use Nebrix\Database\Connection;
use Nebrix\Settings\SettingsRepository;

/**
 * Reseller tiers: rules place customers in customer groups (e.g. Bronze,
 * Silver, Gold with group discounts) by their paid net revenue of the last
 * 12 months or their number of active services.
 *
 * The evaluation (daily scheduler task or "evaluate now") gives every active
 * customer the group of the best rule they meet. Only customers without a
 * group or in a group used by an active tier rule are changed – groups
 * assigned by hand for other reasons stay. Upgrades apply at once; a
 * downgrade happens at most once per configured period. Every change is
 * audit-logged.
 */
class TierService
{
    public const REVENUE = 'revenue_12m';
    public const SERVICES = 'active_services';
    public const METRICS = [self::REVENUE, self::SERVICES];

    public function __construct(
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('tiers.enabled');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rules(bool $activeOnly = false): array
    {
        return $this->db->fetchAll(
            'SELECT r.*, g.`name` AS group_name, g.`discount_bp`, (SELECT COUNT(*) FROM `nx_customer_tiers` t WHERE t.`rule_id` = r.`id`) AS members
               FROM `nx_tier_rules` r JOIN `nx_customer_groups` g ON g.`id` = r.`customer_group_id`
              ' . ($activeOnly ? 'WHERE r.`is_active` = 1' : '') . '
           ORDER BY r.`tier_rank` DESC, r.`id`',
        );
    }

    /**
     * @param array{name: string, customer_group_id: int, metric: string, threshold: int, tier_rank: int, is_active: bool} $data
     */
    public function saveRule(?int $id, array $data, int $actorId): int
    {
        if (!in_array($data['metric'], self::METRICS, true)) {
            throw new InvalidArgumentException('Unknown metric.');
        }
        if ($this->db->fetchValue('SELECT `id` FROM `nx_customer_groups` WHERE `id` = ?', [$data['customer_group_id']]) === null) {
            throw new InvalidArgumentException('Choose an existing customer group.');
        }
        $row = [
            'name' => mb_substr(trim($data['name']), 0, 100),
            'customer_group_id' => $data['customer_group_id'],
            'metric' => $data['metric'],
            'threshold' => max(0, $data['threshold']),
            'tier_rank' => max(1, $data['tier_rank']),
            'is_active' => $data['is_active'] ? 1 : 0,
            'updated_at' => $this->clock->nowString(),
        ];
        if ($id === null) {
            return $this->db->insert('nx_tier_rules', $row + ['created_by' => $actorId, 'created_at' => $this->clock->nowString()]);
        }
        $this->db->update('nx_tier_rules', $row, ['id' => $id]);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function rule(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `nx_tier_rules` WHERE `id` = ?', [$id]);
    }

    /** Removes a rule; customers keep their group until the next evaluation moves them. */
    public function deleteRule(int $id): void
    {
        $this->db->delete('nx_tier_rules', ['id' => $id]);
    }

    /**
     * Evaluates all active customers.
     *
     * @return array{upgraded: int, downgraded: int, held: int, unchanged: int, skipped: int}
     */
    public function evaluate(): array
    {
        $result = ['upgraded' => 0, 'downgraded' => 0, 'held' => 0, 'unchanged' => 0, 'skipped' => 0];
        $rules = $this->rules(true);
        if ($rules === []) {
            return $result;
        }
        $tierGroups = array_map('intval', array_column($rules, 'customer_group_id'));
        $revenue = $this->revenueByCustomer();
        $services = $this->activeServicesByCustomer();
        $now = $this->clock->nowString();
        $period = max(1, $this->settings->int('tiers.downgrade_period_days') ?: 90);
        $downgradeBefore = $this->clock->now()->modify('-' . $period . ' days')->format('Y-m-d H:i:s');

        $customers = $this->db->fetchAll(
            "SELECT u.`id`, u.`customer_group_id`, t.`rule_id`, t.`customer_group_id` AS tier_group_id, t.`last_downgrade_at`
               FROM `nx_users` u
               JOIN `nx_roles` r ON r.`id` = u.`role_id` AND r.`slug` = 'customer'
          LEFT JOIN `nx_customer_tiers` t ON t.`user_id` = u.`id`
              WHERE u.`status` = 'active'
           ORDER BY u.`id`",
        );
        foreach ($customers as $customer) {
            $userId = (int) $customer['id'];
            $group = $customer['customer_group_id'] !== null ? (int) $customer['customer_group_id'] : null;
            if ($group !== null && !in_array($group, $tierGroups, true)) {
                $result['skipped']++; // a group assigned by hand for another reason
                continue;
            }
            $metrics = [self::REVENUE => $revenue[$userId] ?? 0, self::SERVICES => $services[$userId] ?? 0];
            $target = null;
            foreach ($rules as $rule) {
                if ($metrics[(string) $rule['metric']] >= (int) $rule['threshold']) {
                    $target = $rule; // rules are ordered by rank, best first
                    break;
                }
            }
            $current = $this->currentRule($rules, $customer, $group);
            $currentRank = $current !== null ? (int) $current['tier_rank'] : 0;
            $targetRank = $target !== null ? (int) $target['tier_rank'] : 0;
            $targetGroup = $target !== null ? (int) $target['customer_group_id'] : null;
            $state = ['revenue_12m' => $metrics[self::REVENUE], 'active_services' => $metrics[self::SERVICES], 'evaluated_at' => $now];

            if ($targetRank === $currentRank && $targetGroup === $group) {
                $this->saveState($userId, $state + ['rule_id' => $current['id'] ?? null, 'customer_group_id' => $group]);
                $result['unchanged']++;
                continue;
            }
            $downgrade = $targetRank < $currentRank;
            if ($downgrade && $customer['last_downgrade_at'] !== null && (string) $customer['last_downgrade_at'] > $downgradeBefore) {
                $this->saveState($userId, $state);
                $result['held']++; // at most one downgrade per period
                continue;
            }
            // Conditional: a group changed by staff in the meantime is not overwritten.
            $changed = $this->db->run(
                'UPDATE `nx_users` SET `customer_group_id` = ?, `updated_at` = ? WHERE `id` = ? AND `customer_group_id` <=> ?',
                [$targetGroup, $now, $userId, $group],
            )->rowCount() > 0;
            if (!$changed) {
                $result['skipped']++;
                continue;
            }
            $this->saveState($userId, $state + ['rule_id' => $target['id'] ?? null, 'customer_group_id' => $targetGroup, 'changed_at' => $now] + ($downgrade ? ['last_downgrade_at' => $now] : []));
            $this->audit->log(null, $downgrade ? 'tier.downgraded' : 'tier.upgraded', 'user', $userId, [
                'from_group' => $group,
                'to_group' => $targetGroup,
                'rule' => $target['name'] ?? null,
                'revenue_12m' => $metrics[self::REVENUE],
                'active_services' => $metrics[self::SERVICES],
            ]);
            $result[$downgrade ? 'downgraded' : 'upgraded']++;
        }

        return $result;
    }

    /**
     * The tier the customer holds now: the rule recorded by the last
     * evaluation if the customer is still in its group, otherwise the best
     * rule of the customer's current group.
     *
     * @param list<array<string, mixed>> $rules
     * @param array<string, mixed> $customer
     * @return array<string, mixed>|null
     */
    private function currentRule(array $rules, array $customer, ?int $group): ?array
    {
        if ($group === null) {
            return null;
        }
        foreach ($rules as $rule) {
            if ($customer['rule_id'] !== null && (int) $rule['id'] === (int) $customer['rule_id'] && (int) $rule['customer_group_id'] === $group) {
                return $rule;
            }
        }
        foreach ($rules as $rule) {
            if ((int) $rule['customer_group_id'] === $group) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(int $userId, array $state): void
    {
        if ($this->db->fetchValue('SELECT `user_id` FROM `nx_customer_tiers` WHERE `user_id` = ?', [$userId]) === null) {
            $this->db->insert('nx_customer_tiers', $state + ['user_id' => $userId]);

            return;
        }
        $this->db->update('nx_customer_tiers', $state, ['user_id' => $userId]);
    }

    /**
     * Paid net revenue of the last 12 months per customer (refunds deducted
     * proportionally; order, renewal and upgrade invoices in the default currency).
     *
     * @return array<int, int>
     */
    private function revenueByCustomer(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT `user_id`, SUM(`subtotal` - IF(`total` > 0, FLOOR(`subtotal` * `amount_refunded` / `total`), 0)) AS revenue
               FROM `nx_invoices`
              WHERE `type` IN ('order', 'renewal', 'upgrade') AND `status` IN ('paid', 'refunded') AND `paid_at` >= ? AND `currency` = ?
           GROUP BY `user_id`",
            [$this->clock->now()->modify('-12 months')->format('Y-m-d H:i:s'), strtoupper($this->settings->string('general.default_currency') ?: 'EUR')],
        );

        return array_map('intval', array_column($rows, 'revenue', 'user_id'));
    }

    /**
     * @return array<int, int>
     */
    private function activeServicesByCustomer(): array
    {
        $rows = $this->db->fetchAll("SELECT `user_id`, COUNT(*) AS n FROM `nx_services` WHERE `status` = 'active' GROUP BY `user_id`");

        return array_map('intval', array_column($rows, 'n', 'user_id'));
    }

    /**
     * Customers with a tier state for the admin overview.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(int $limit = 200): array
    {
        return $this->db->fetchAll(
            'SELECT t.*, u.`email`, u.`first_name`, u.`last_name`, u.`company`, g.`name` AS group_name, r.`name` AS rule_name
               FROM `nx_customer_tiers` t
               JOIN `nx_users` u ON u.`id` = t.`user_id`
          LEFT JOIN `nx_customer_groups` g ON g.`id` = u.`customer_group_id`
          LEFT JOIN `nx_tier_rules` r ON r.`id` = t.`rule_id`
              WHERE t.`rule_id` IS NOT NULL OR t.`changed_at` IS NOT NULL
           ORDER BY t.`changed_at` DESC, t.`user_id` LIMIT ?',
            [$limit],
        );
    }
}
