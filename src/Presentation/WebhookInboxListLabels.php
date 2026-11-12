<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentWebhookInboxStatus;
use App\Service\Admin\AdminWebhookQueueQuery;

/**
 * Turkish list labels for webhook inbox tabs, status, event type and environment.
 *
 * Domain enum / URL tab values stay unchanged. Separate from payment attempt labels
 * so inbox "failed" event types do not collide with attempt-status wording elsewhere.
 */
final class WebhookInboxListLabels
{
    /**
     * @var array<string, string>
     */
    private const TAB = [
        AdminWebhookQueueQuery::TAB_DUE => 'İşlenecek',
        AdminWebhookQueueQuery::TAB_RETRY_PENDING => 'Yeniden deneme bekliyor',
        AdminWebhookQueueQuery::TAB_PROCESSING => 'İşleniyor',
        AdminWebhookQueueQuery::TAB_STALE_LEASE => 'Süresi dolmuş kilitleme',
        AdminWebhookQueueQuery::TAB_DEAD_LETTER => 'Ölü mektup',
        AdminWebhookQueueQuery::TAB_REJECTED => 'Reddedildi',
        AdminWebhookQueueQuery::TAB_PROCESSED => 'İşlendi',
    ];

    /**
     * @var array<string, array{0: string, 1: 'neutral'|'success'|'warning'|'danger'|'limited'}>
     */
    private const STATUS = [
        PaymentWebhookInboxStatus::Received->value => ['Alındı', 'neutral'],
        PaymentWebhookInboxStatus::Processing->value => ['İşleniyor', 'warning'],
        PaymentWebhookInboxStatus::RetryPending->value => ['Yeniden deneme bekliyor', 'warning'],
        PaymentWebhookInboxStatus::Processed->value => ['İşlendi', 'success'],
        PaymentWebhookInboxStatus::Rejected->value => ['Reddedildi', 'danger'],
        PaymentWebhookInboxStatus::DeadLetter->value => ['Ölü mektup', 'danger'],
    ];

    /**
     * @var array<string, string>
     */
    private const EVENT_TYPE = [
        PaymentEventType::Authorized->value => 'Yetkilendirildi',
        PaymentEventType::Captured->value => 'Tahsil edildi',
        PaymentEventType::Failed->value => 'Başarısız',
        PaymentEventType::Cancelled->value => 'İptal',
        PaymentEventType::RefundRequested->value => 'İade talebi',
        PaymentEventType::RefundSucceeded->value => 'İade tamamlandı',
        PaymentEventType::RefundFailed->value => 'İade başarısız',
    ];

    /**
     * @var array<string, string>
     */
    private const ENVIRONMENT = [
        PaymentProviderEnvironment::Sandbox->value => 'Test (sandbox)',
        PaymentProviderEnvironment::Production->value => 'Canlı',
    ];

    public function tab(string $code): string
    {
        return self::TAB[$code] ?? $code;
    }

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

    public function eventType(string $code): string
    {
        return self::EVENT_TYPE[$code] ?? $code;
    }

    public function environment(string $code): string
    {
        return self::ENVIRONMENT[$code] ?? $code;
    }

    public function staleLeaseNote(): string
    {
        return 'Kilitleme süresi doldu';
    }
}
