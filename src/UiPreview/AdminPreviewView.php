<?php

declare(strict_types=1);

namespace App\UiPreview;

/**
 * Immutable UI preview payload for the admin operations shell (demo only).
 */
final class AdminPreviewView
{
    /**
     * @param list<array{label: string, value: string}>             $metrics
     * @param list<array{title: string, body: string}>              $notifications
     * @param list<array{id: string, label: string, current: bool}> $navItems
     * @param list<array{id: string, label: string, current: bool}> $mobileNav
     */
    public function __construct(
        public readonly string $displayName,
        public readonly string $greeting,
        public readonly array $metrics,
        public readonly array $notifications,
        public readonly array $navItems,
        public readonly array $mobileNav,
    ) {
    }

    public static function demo(): self
    {
        return new self(
            displayName: 'Ayşe Yönetici',
            greeting: 'Yönetim operasyon paneli önizlemesi.',
            metrics: [
                ['label' => 'Due webhook', 'value' => '3'],
                ['label' => 'Dead letter', 'value' => '1'],
                ['label' => 'Ödeme bekleyen', 'value' => '5'],
            ],
            notifications: [
                ['title' => 'Demo bildirim', 'body' => 'Gerçek kuyruk verisi değildir.'],
            ],
            navItems: [
                ['id' => 'dashboard', 'label' => 'Özet', 'current' => true],
                ['id' => 'system', 'label' => 'Sistem', 'current' => false],
                ['id' => 'users', 'label' => 'Kullanıcılar', 'current' => false],
                ['id' => 'institutions', 'label' => 'Kurumlar', 'current' => false],
                ['id' => 'payments', 'label' => 'Ödemeler', 'current' => false],
                ['id' => 'webhooks', 'label' => 'Webhook', 'current' => false],
                ['id' => 'reconciliations', 'label' => 'Uzlaştırma', 'current' => false],
                ['id' => 'audit', 'label' => 'Denetim', 'current' => false],
            ],
            mobileNav: [
                ['id' => 'dashboard', 'label' => 'Özet', 'current' => true],
                ['id' => 'system', 'label' => 'Sistem', 'current' => false],
                ['id' => 'users', 'label' => 'Kullanıcılar', 'current' => false],
                ['id' => 'institutions', 'label' => 'Kurumlar', 'current' => false],
                ['id' => 'payments', 'label' => 'Ödemeler', 'current' => false],
                ['id' => 'webhooks', 'label' => 'Webhook', 'current' => false],
                ['id' => 'reconciliations', 'label' => 'Uzlaştırma', 'current' => false],
                ['id' => 'audit', 'label' => 'Denetim', 'current' => false],
            ],
        );
    }
}
