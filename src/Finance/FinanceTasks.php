<?php

declare(strict_types=1);

namespace Nebrix\Finance;

use Nebrix\Finance\Accounting\AccountingSync;
use Nebrix\Settings\SettingsRepository;

/**
 * Scheduler tasks of the finance area (registered in Scheduler::tasks()).
 */
class FinanceTasks
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AccountingSync $accountingSync,
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
        ];
    }
}
