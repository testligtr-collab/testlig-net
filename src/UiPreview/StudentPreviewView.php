<?php

declare(strict_types=1);

namespace App\UiPreview;

/**
 * Immutable UI preview payload for the student panel (demo only — no persistence).
 */
final class StudentPreviewView
{
    /**
     * @param list<array{label: string, meta: string}>              $todayPlan
     * @param list<array{label: string, when: string}>              $upcoming
     * @param list<array{label: string, tone: string}>              $strengths
     * @param list<array{label: string, tone: string}>              $supportAreas
     * @param list<array{title: string, body: string}>              $notifications
     * @param list<array{id: string, label: string, current: bool}> $navItems
     * @param list<array{id: string, label: string, current: bool}> $mobileNav
     */
    public function __construct(
        public readonly string $displayName,
        public readonly string $greeting,
        public readonly string $primaryCtaLabel,
        public readonly string $weeklyProgressLabel,
        public readonly string $weeklyProgressValue,
        public readonly int $weeklyProgressPercent,
        public readonly string $recentAchievement,
        public readonly array $todayPlan,
        public readonly array $upcoming,
        public readonly array $strengths,
        public readonly array $supportAreas,
        public readonly array $notifications,
        public readonly array $navItems,
        public readonly array $mobileNav,
    ) {
    }

    public static function demo(): self
    {
        return new self(
            displayName: 'Ece Yılmaz',
            greeting: 'Günaydın Ece, bugünkü hedefin hazır.',
            primaryCtaLabel: 'Çalışmaya Devam Et',
            weeklyProgressLabel: 'Haftalık hedef',
            weeklyProgressValue: '13 / 20 çalışma',
            weeklyProgressPercent: 65,
            recentAchievement: 'Kesirler tekrarını tamamladın',
            todayPlan: [
                ['label' => 'Matematik · Kesirler', 'meta' => '20 soru · 25 dk'],
                ['label' => 'Fen · Madde ve ısı', 'meta' => 'Kısa tekrar · 10 dk'],
            ],
            upcoming: [
                ['label' => 'Matematik mini deneme', 'when' => 'Perşembe 10:00'],
                ['label' => 'Fen ödev teslimi', 'when' => 'Cuma'],
            ],
            strengths: [
                ['label' => 'Doğal sayılar', 'tone' => 'success'],
                ['label' => 'Okuma anlama', 'tone' => 'success'],
            ],
            supportAreas: [
                ['label' => 'Kesirlerle işlem', 'tone' => 'warn'],
                ['label' => 'Problem çözme', 'tone' => 'accent'],
            ],
            notifications: [
                ['title' => 'Çalışma planı güncellendi', 'body' => 'Bugünkü matematik hedefi hazır.'],
                ['title' => 'Öğretmen notu', 'body' => 'Deniz Öğretmen kısa bir geri bildirim bıraktı.'],
            ],
            navItems: [
                ['id' => 'home', 'label' => 'Ana Sayfa', 'current' => true],
                ['id' => 'plan', 'label' => 'Çalışma Planım', 'current' => false],
                ['id' => 'exams', 'label' => 'Sınavlarım', 'current' => false],
                ['id' => 'courses', 'label' => 'Derslerim', 'current' => false],
                ['id' => 'progress', 'label' => 'Gelişimim', 'current' => false],
                ['id' => 'more', 'label' => 'Daha Fazla', 'current' => false],
            ],
            mobileNav: [
                ['id' => 'home', 'label' => 'Ana Sayfa', 'current' => true],
                ['id' => 'plan', 'label' => 'Planım', 'current' => false],
                ['id' => 'exams', 'label' => 'Sınavlar', 'current' => false],
                ['id' => 'progress', 'label' => 'Gelişim', 'current' => false],
            ],
        );
    }
}
