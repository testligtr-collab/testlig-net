<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * Turkish presentation labels for admin status chips. Domain enum values stay unchanged.
 */
final class AdminStatusLabels
{
    /**
     * @var array<string, array{0: string, 1: 'neutral'|'success'|'warning'|'danger'|'limited'}>
     */
    private const LABELS = [
        'draft' => ['Taslak', 'neutral'],
        'in_review' => ['İncelemede', 'warning'],
        'published' => ['Yayında', 'success'],
        'archived' => ['Arşivlenmiş', 'neutral'],
        'active' => ['Aktif', 'success'],
        'suspended' => ['Askıda', 'warning'],
        'pending_verification' => ['Doğrulama bekliyor', 'warning'],
        'success' => ['Başarılı', 'success'],
        'matched' => ['Başarılı', 'success'],
        'warning' => ['Uyarı', 'warning'],
        'discrepancy' => ['Uyarı', 'warning'],
        'unavailable' => ['Kullanılamıyor', 'danger'],
        'failed' => ['Kullanılamıyor', 'danger'],
        'limited' => ['Sınırlı görünüm', 'limited'],
        '' => ['Bilinmiyor', 'neutral'],
    ];

    /**
     * @return array{label: string, variant: 'neutral'|'success'|'warning'|'danger'|'limited'}
     */
    public function present(string $code): array
    {
        $row = self::LABELS[$code] ?? self::LABELS[''];

        return [
            'label' => $row[0],
            'variant' => $row[1],
        ];
    }
}
