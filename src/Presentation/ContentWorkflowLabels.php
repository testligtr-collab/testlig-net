<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * Presentation labels for content workflow screens. Domain values stay unchanged.
 */
final class ContentWorkflowLabels
{
    /**
     * @var array<string, string>
     */
    private const STATUS = [
        'draft' => 'Taslak',
        'in_review' => 'İncelemede',
        'published' => 'Yayında',
        'archived' => 'Arşivlenmiş',
    ];

    /**
     * @var array<string, string>
     */
    private const TYPE = [
        'topic_explanation' => 'Konu anlatımı',
        'video' => 'Video',
        'audio' => 'Ses',
        'document' => 'Belge',
        'worksheet' => 'Çalışma kağıdı',
        'presentation' => 'Sunum',
        'animation' => 'Animasyon',
        'simulation' => 'Simülasyon',
        'educational_game' => 'Eğitici oyun',
        'interactive' => 'Etkileşimli',
        'external_link' => 'Dış bağlantı',
    ];

    /**
     * @var array<string, string>
     */
    private const SCOPE = [
        'platform' => 'Platform geneli',
        'institution' => 'Kurum',
    ];

    /**
     * @var array<string, string>
     */
    private const ACCESS = [
        'free' => 'Ücretsiz',
        'entitlement_required' => 'Yetki gerekli',
    ];

    /**
     * @var array<string, string>
     */
    private const CALLOUT = [
        'info' => 'Bilgi',
        'warning' => 'Uyarı',
        'tip' => 'İpucu',
        'note' => 'Not',
    ];

    public function label(string $kind, string $value): string
    {
        $map = match ($kind) {
            'status' => self::STATUS,
            'type' => self::TYPE,
            'scope' => self::SCOPE,
            'access' => self::ACCESS,
            'callout' => self::CALLOUT,
            default => [],
        };

        return $map[$value] ?? $value;
    }
}
