<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentReconciliationRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case CompletedWithDiscrepancies = 'completed_with_discrepancies';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::CompletedWithDiscrepancies, self::Failed => true,
            self::Running => false,
        };
    }
}
