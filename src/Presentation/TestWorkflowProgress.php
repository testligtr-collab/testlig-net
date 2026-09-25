<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * Role-aware next step for the test editor. Does not grant permissions.
 */
final class TestWorkflowProgress
{
    /**
     * @param array{
     *     status: string,
     *     can_edit: bool,
     *     can_submit: bool,
     *     can_return: bool,
     *     can_publish: bool,
     *     can_archive: bool,
     *     is_revision_author: bool
     * } $state
     *
     * @return array{
     *     steps: list<array{label: string, done: bool}>,
     *     next: array{kind: string, label: string, hint: string}
     * }
     */
    public function summarize(array $state): array
    {
        $status = $state['status'];
        $draft = 'draft' === $status;
        $inReview = 'in_review' === $status;
        $published = 'published' === $status;
        $archived = 'archived' === $status;

        $steps = [
            ['label' => 'Taslak', 'done' => true],
            ['label' => 'İncelemede', 'done' => $inReview || $published || $archived],
            ['label' => 'Yayında', 'done' => $published],
            ['label' => 'Arşiv', 'done' => $archived],
        ];

        if ($archived) {
            $next = ['kind' => 'none', 'label' => 'Arşivde', 'hint' => 'Arşivlenmiş test yeniden açılmaz.'];
        } elseif ($draft && $state['can_submit']) {
            $next = ['kind' => 'submit', 'label' => 'İncelemeye gönder', 'hint' => 'Gönderdikten sonra bu sürümü siz yayınlayamazsınız.'];
        } elseif ($draft && $state['can_edit']) {
            $next = ['kind' => 'edit', 'label' => 'Taslağı tamamla', 'hint' => 'Soruları kaydedin.'];
        } elseif ($inReview && $state['is_revision_author']) {
            $next = ['kind' => 'wait', 'label' => 'İnceleme bekleniyor', 'hint' => 'Kendi hazırladığınız testi yayınlayamazsınız.'];
        } elseif ($inReview && $state['can_publish']) {
            $next = ['kind' => 'publish', 'label' => 'Yayınla', 'hint' => 'Yayınlanan test içeriği değiştirilemez.'];
        } elseif ($inReview && $state['can_return']) {
            $next = ['kind' => 'return', 'label' => 'Taslağa döndür', 'hint' => 'Yazar düzeltip yeniden gönderebilir.'];
        } elseif ($published && $state['can_archive']) {
            $next = ['kind' => 'archive', 'label' => 'Arşivle', 'hint' => 'Arşiv geri alınamaz.'];
        } else {
            $next = ['kind' => 'wait', 'label' => 'Bekleyin', 'hint' => 'Bu adım başka bir yetkiliye ait.'];
        }

        return [
            'steps' => $steps,
            'next' => $next,
        ];
    }
}
