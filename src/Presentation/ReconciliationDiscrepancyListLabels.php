<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Enum\PaymentProviderTransactionStatus;
use App\Enum\PaymentReconciliationItemAction;
use App\Enum\PaymentReconciliationItemOutcome;

/**
 * Turkish labels for reconciliation discrepancy outcome, action, and provider state.
 *
 * Expected (local) attempt status uses {@see PaymentAttemptListLabels}. Domain values stay unchanged.
 */
final class ReconciliationDiscrepancyListLabels
{
    /**
     * @var array<string, array{0: string, 1: 'neutral'|'success'|'warning'|'danger'|'limited'}>
     */
    private const OUTCOME = [
        PaymentReconciliationItemOutcome::Matched->value => ['Eşleşti', 'success'],
        PaymentReconciliationItemOutcome::LocalBehind->value => ['Yerel geride', 'warning'],
        PaymentReconciliationItemOutcome::ProviderBehind->value => ['Sağlayıcı geride', 'warning'],
        PaymentReconciliationItemOutcome::AmountMismatch->value => ['Tutar uyuşmazlığı', 'danger'],
        PaymentReconciliationItemOutcome::CurrencyMismatch->value => ['Para birimi uyuşmazlığı', 'danger'],
        PaymentReconciliationItemOutcome::ReferenceMismatch->value => ['Referans uyuşmazlığı', 'danger'],
        PaymentReconciliationItemOutcome::MissingAtProvider->value => ['Sağlayıcıda yok', 'warning'],
        PaymentReconciliationItemOutcome::Unsupported->value => ['Desteklenmiyor', 'limited'],
        PaymentReconciliationItemOutcome::Failed->value => ['Başarısız', 'danger'],
    ];

    /**
     * @var array<string, array{0: string, 1: 'neutral'|'success'|'warning'|'danger'|'limited'}>
     */
    private const ACTION = [
        PaymentReconciliationItemAction::None->value => ['Yok', 'neutral'],
        PaymentReconciliationItemAction::WebhookRequeued->value => ['Webhook yeniden kuyruğa alındı', 'warning'],
        PaymentReconciliationItemAction::ManualReviewRequired->value => ['Manuel inceleme gerekli', 'warning'],
    ];

    /**
     * @var array<string, array{0: string, 1: 'neutral'|'success'|'warning'|'danger'|'limited'}>
     */
    private const PROVIDER_STATE = [
        PaymentProviderTransactionStatus::Initiated->value => ['Başlatıldı', 'neutral'],
        PaymentProviderTransactionStatus::Authorized->value => ['Yetkilendirildi', 'warning'],
        PaymentProviderTransactionStatus::Captured->value => ['Tahsil edildi', 'success'],
        PaymentProviderTransactionStatus::Failed->value => ['Başarısız', 'danger'],
        PaymentProviderTransactionStatus::Cancelled->value => ['İptal', 'neutral'],
    ];

    /**
     * @return array{label: string, variant: 'neutral'|'success'|'warning'|'danger'|'limited'}
     */
    public function outcome(string $code): array
    {
        $row = self::OUTCOME[$code] ?? ['Bilinmiyor', 'neutral'];

        return [
            'label' => $row[0],
            'variant' => $row[1],
        ];
    }

    /**
     * @return array{label: string, variant: 'neutral'|'success'|'warning'|'danger'|'limited'}
     */
    public function action(string $code): array
    {
        $row = self::ACTION[$code] ?? ['Bilinmiyor', 'neutral'];

        return [
            'label' => $row[0],
            'variant' => $row[1],
        ];
    }

    /**
     * @return array{label: string, variant: 'neutral'|'success'|'warning'|'danger'|'limited'}
     */
    public function providerState(string $code): array
    {
        $row = self::PROVIDER_STATE[$code] ?? ['Bilinmiyor', 'neutral'];

        return [
            'label' => $row[0],
            'variant' => $row[1],
        ];
    }
}
