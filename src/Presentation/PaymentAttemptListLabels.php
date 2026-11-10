<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * Turkish list labels for payment attempt status and provider environment.
 *
 * Domain enum values stay unchanged. Kept separate from {@see AdminStatusLabels}
 * so payment "failed" does not collide with system-health wording.
 */
final class PaymentAttemptListLabels
{
    /**
     * @var array<string, array{0: string, 1: 'neutral'|'success'|'warning'|'danger'|'limited'}>
     */
    private const STATUS = [
        'initiated' => ['Başlatıldı', 'neutral'],
        'authorized' => ['Yetkilendirildi', 'warning'],
        'captured' => ['Tahsil edildi', 'success'],
        'failed' => ['Başarısız', 'danger'],
        'cancelled' => ['İptal', 'neutral'],
    ];

    /**
     * @var array<string, string>
     */
    private const ENVIRONMENT = [
        'sandbox' => 'Test (sandbox)',
        'production' => 'Canlı',
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
