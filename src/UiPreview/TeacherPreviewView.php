<?php

declare(strict_types=1);

namespace App\UiPreview;

/**
 * Immutable UI preview payload for the teacher panel (demo only — no persistence).
 */
final class TeacherPreviewView
{
    /**
     * @param list<array{label: string, meta: string}>              $todayTasks
     * @param list<array{label: string}>                            $quickActions
     * @param list<array{label: string, value: string}>             $classSummary
     * @param list<array{label: string, meta: string}>              $pendingReviews
     * @param list<array{label: string, value: string}>             $outcomePreview
     * @param list<array{label: string, when: string}>              $calendar
     * @param list<array{title: string, body: string}>              $notifications
     * @param list<array{id: string, label: string, current: bool}> $navItems
     * @param list<array{id: string, label: string, current: bool}> $mobileNav
     */
    public function __construct(
        public readonly string $displayName,
        public readonly string $greeting,
        public readonly string $privacyNote,
        public readonly array $todayTasks,
        public readonly array $quickActions,
        public readonly array $classSummary,
        public readonly array $pendingReviews,
        public readonly array $outcomePreview,
        public readonly array $calendar,
        public readonly array $notifications,
        public readonly array $navItems,
        public readonly array $mobileNav,
    ) {
    }

    public static function demo(): self
    {
        return new self(
            displayName: 'Deniz Öğretmen',
            greeting: 'Günaydın Deniz Öğretmen.',
            privacyNote: 'Yeterli katılım oluştuğunda sınıf analizi gösterilir.',
            todayTasks: [
                ['label' => '8-A matematik ödevini gözden geçir', 'meta' => '6 yanıt bekliyor'],
                ['label' => 'Cuma deneme taslağını tamamla', 'meta' => 'Taslak · 12 soru'],
            ],
            quickActions: [
                ['label' => 'Sınav oluştur'],
                ['label' => 'Ödev ata'],
                ['label' => 'Canlı ders planla'],
            ],
            classSummary: [
                ['label' => 'Aktif sınıf', 'value' => '8-A'],
                ['label' => 'Bugün tamamlanan', 'value' => '18 çalışma'],
                ['label' => 'Ortalama katılım', 'value' => 'Demo'],
            ],
            pendingReviews: [
                ['label' => 'Açık uçlu soru · Ece Yılmaz', 'meta' => 'Bekliyor'],
                ['label' => 'Proje kontrolü · 8-A', 'meta' => '3 öğrenci'],
            ],
            outcomePreview: [
                ['label' => 'Kesirler', 'value' => 'Güçlü eğilim'],
                ['label' => 'Problem çözme', 'value' => 'Destek alanı'],
            ],
            calendar: [
                ['label' => '8-A Matematik', 'when' => 'Bugün 11:20'],
                ['label' => 'Mini deneme', 'when' => 'Perşembe'],
                ['label' => 'Canlı ders (önizleme)', 'when' => 'Cuma 14:00'],
            ],
            notifications: [
                ['title' => 'Değerlendirme bekleyen yanıt', 'body' => 'Açık uçlu soru için 1 yeni yanıt var.'],
                ['title' => 'Takvim', 'body' => 'Perşembe mini denemesi planlandı.'],
            ],
            navItems: [
                ['id' => 'overview', 'label' => 'Genel Bakış', 'current' => true],
                ['id' => 'classes', 'label' => 'Sınıflarım', 'current' => false],
                ['id' => 'content', 'label' => 'İçerikler', 'current' => false],
                ['id' => 'assignments', 'label' => 'Sınav ve Ödev', 'current' => false],
                ['id' => 'analytics', 'label' => 'Analizler', 'current' => false],
                ['id' => 'calendar', 'label' => 'Takvim', 'current' => false],
            ],
            mobileNav: [
                ['id' => 'overview', 'label' => 'Özet', 'current' => true],
                ['id' => 'classes', 'label' => 'Sınıflar', 'current' => false],
                ['id' => 'assignments', 'label' => 'Ödev', 'current' => false],
                ['id' => 'calendar', 'label' => 'Takvim', 'current' => false],
            ],
        );
    }
}
