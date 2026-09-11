<?php

declare(strict_types=1);

namespace App\UiPreview;

/**
 * Immutable UI preview payload for the parent panel (demo only — no persistence).
 */
final class ParentPreviewView
{
    /**
     * @param list<array{label: string, value: string}>             $weekSummary
     * @param list<array{label: string, meta: string}>              $completed
     * @param list<array{label: string, when: string}>              $upcoming
     * @param list<array{label: string, value: string}>             $growth
     * @param list<array{title: string, body: string}>              $notifications
     * @param list<array{id: string, label: string, current: bool}> $navItems
     * @param list<array{id: string, label: string, current: bool}> $mobileNav
     */
    public function __construct(
        public readonly string $childName,
        public readonly string $title,
        public readonly string $teacherFeedback,
        public readonly string $supportTip,
        public readonly array $weekSummary,
        public readonly array $completed,
        public readonly array $upcoming,
        public readonly array $growth,
        public readonly array $notifications,
        public readonly array $navItems,
        public readonly array $mobileNav,
    ) {
    }

    public static function demo(): self
    {
        return new self(
            childName: 'Ece Yılmaz',
            title: 'Ece’nin haftası',
            teacherFeedback: 'Deniz Öğretmen: Ece bu hafta düzenli çalıştı. Kesirlerde kısa tekrar faydalı olur.',
            supportTip: 'Akşam 20 dakikalık sakin bir tekrar, yarınki çalışma planını kolaylaştırır.',
            weekSummary: [
                ['label' => 'Tamamlanan çalışma', 'value' => '13 oturum'],
                ['label' => 'Ortalama süre', 'value' => '22 dk'],
                ['label' => 'Genel durum', 'value' => 'Düzenli ilerleme'],
            ],
            completed: [
                ['label' => 'Matematik çalışması', 'meta' => 'Salı'],
                ['label' => 'Fen tekrarı', 'meta' => 'Çarşamba'],
            ],
            upcoming: [
                ['label' => 'Matematik mini deneme', 'when' => 'Perşembe'],
                ['label' => 'Fen ödevi', 'when' => 'Cuma'],
            ],
            growth: [
                ['label' => 'Matematik', 'value' => 'Gelişiyor'],
                ['label' => 'Fen', 'value' => 'Dengeli'],
                ['label' => 'Türkçe', 'value' => 'Güçlü'],
            ],
            notifications: [
                ['title' => 'Haftalık özet hazır', 'body' => 'Ece’nin bu haftaki çalışması görüntülenebilir.'],
                ['title' => 'Yaklaşan görev', 'body' => 'Perşembe mini denemesi planlandı.'],
            ],
            navItems: [
                ['id' => 'overview', 'label' => 'Genel Bakış', 'current' => true],
                ['id' => 'growth', 'label' => 'Çocuğumun Gelişimi', 'current' => false],
                ['id' => 'results', 'label' => 'Sınav Sonuçları', 'current' => false],
                ['id' => 'tasks', 'label' => 'Görevler', 'current' => false],
                ['id' => 'calendar', 'label' => 'Takvim', 'current' => false],
                ['id' => 'notifications', 'label' => 'Bildirimler', 'current' => false],
            ],
            mobileNav: [
                ['id' => 'overview', 'label' => 'Özet', 'current' => true],
                ['id' => 'growth', 'label' => 'Gelişim', 'current' => false],
                ['id' => 'tasks', 'label' => 'Görevler', 'current' => false],
                ['id' => 'notifications', 'label' => 'Bildirim', 'current' => false],
            ],
        );
    }
}
