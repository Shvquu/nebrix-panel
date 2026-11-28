<?php

declare(strict_types=1);

namespace Nebrix\Finance;

use Nebrix\Finance\Accounting\AccountingSync;
use Nebrix\Finance\Partner\PartnerService;
use Nebrix\Finance\Tiers\TierService;
use Nebrix\Settings\SettingsRepository;

/**
 * Scheduler tasks of the finance area (registered in Scheduler::tasks()).
 */
class FinanceTasks
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AccountingSync $accountingSync,
        private readonly PartnerService $partners,
        private readonly TierService $tiers,
    ) {
    }

    /**
     * @return array<string, array{0: int, 1: callable(): string}> task => [interval minutes, callback]
     */
    public function tasks(): array
    {
        return [
            'accounting.lexoffice_sync' => [60, function (): string {
                if (!$this->settings->bool('lexoffice.auto_sync') || !$this->accountingSync->enabled()) {
                    return 'automatic transfer disabled';
                }
                $result = $this->accountingSync->run();

                return sprintf('%d document(s) transferred to lexoffice, %d failed', $result['synced'], $result['failed']);
            }],
            'partner.commissions' => [60, function (): string {
                $result = $this->partners->process();

                return sprintf('%d commission(s) accrued, %d reversed, %d now payable', $result['accrued'], $result['reversed'], $result['payable']);
            }],
            'tiers.evaluate' => [1440, function (): string {
                if (!$this->tiers->enabled()) {
                    return 'automatic tiers disabled';
                }
                $result = $this->tiers->evaluate();

                return sprintf('%d customer(s) upgraded, %d downgraded, %d downgrade(s) held back', $result['upgraded'], $result['downgraded'], $result['held']);
            }],
        ];
    }
}
