<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentReconciliationRunStatus;

/**
 * Turkish list labels for reconciliation run status and environment.
 *
 * Domain enum / URL values stay unchanged.
 */
final class ReconciliationRunListLabels
{
    /**
     * @var array<string, array{0: string, 1: 'neutral'|'success'|'warning'|'danger'|'limited'}>
     */
    private const STATUS = [
        PaymentReconciliationRunStatus::Running->value => ['Devam ediyor', 'warning'],
        PaymentReconciliationRunStatus::Completed->value => ['Tamamlandı', 'success'],
        PaymentReconciliationRunStatus::CompletedWithDiscrepancies->value => ['Tutarsızlıklarla tamamlandı', 'warning'],
        PaymentReconciliationRunStatus::Failed->value => ['Başarısız', 'danger'],
    ];

    /**
     * @var array<string, string>
     */
    private const ENVIRONMENT = [
        PaymentProviderEnvironment::Sandbox->value => 'Test (sandbox)',
        PaymentProviderEnvironment::Production->value => 'Canlı',
    ];

    /**
     * @return array{label: string, variant: 'neutral'|'success'|'warning'|'danger'|'limited'}
     */
    public function status(string $code): array
    {
        $row = self::STATUS[$code] ?? ['Bilinmiyor', 'neutral'];

        return [
            'label' => $row[0],
            'variant' => $row[1],
        ];
    }

    public function environment(string $code): string
    {
        return self::ENVIRONMENT[$code] ?? $code;
    }
}
