<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * Role-aware next step for the content detail screen. Does not grant permissions.
 */
final class ContentWorkflowProgress
{
    /**
     * @param array{
     *     status: string,
     *     has_revision: bool,
     *     revision_sealed: bool,
     *     access_class: ?string,
     *     has_draft_placement: bool,
     *     has_published_placement: bool,
     *     can_manage: bool,
     *     can_submit_review: bool,
     *     can_publish: bool,
     *     can_set_policy: bool,
     *     can_create_placement: bool,
     *     can_publish_placement: bool
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
        $revisionReady = $state['has_revision'];
        $inReview = 'in_review' === $status;
        $published = 'published' === $status;
        $archived = 'archived' === $status;
        $visible = $published
            && $state['revision_sealed']
            && $state['has_published_placement']
            && 'free' === $state['access_class'];

        $steps = [
            ['label' => 'İçerik taslağı', 'done' => true],
            ['label' => 'Sürüm hazırlandı', 'done' => $revisionReady],
            ['label' => 'İncelemeye gönderildi', 'done' => $inReview || $published || ($archived && $revisionReady)],
            ['label' => 'Yayınlandı', 'done' => $published],
            ['label' => 'Katalog yerleşimi', 'done' => $state['has_published_placement']],
            ['label' => 'Öğrenciye görünürlük', 'done' => $visible],
        ];

        return [
            'steps' => $steps,
            'next' => $this->next($state, $visible),
        ];
    }

    /**
     * @param array{
     *     status: string,
     *     has_revision: bool,
     *     revision_sealed: bool,
     *     access_class: ?string,
     *     has_draft_placement: bool,
     *     has_published_placement: bool,
     *     can_manage: bool,
     *     can_submit_review: bool,
     *     can_publish: bool,
     *     can_set_policy: bool,
     *     can_create_placement: bool,
     *     can_publish_placement: bool
     * } $state
     *
     * @return array{kind: string, label: string, hint: string}
     */
    private function next(array $state, bool $visible): array
    {
        $status = $state['status'];
        if ('archived' === $status) {
            return [
                'kind' => 'archived',
                'label' => 'Arşivlenmiş',
                'hint' => 'Arşivlenmiş içerik yeni yayın adımı almaz.',
            ];
        }

        if ('draft' === $status && !$state['has_revision']) {
            if ($state['can_manage']) {
                return [
                    'kind' => 'edit_revision',
                    'label' => 'Sürümü hazırla',
                    'hint' => 'Metni kaydedin. İnceleme, sürüm hazır olduğunda açılır.',
                ];
            }

            return [
                'kind' => 'waiting',
                'label' => 'Sürüm hazırlanıyor',
                'hint' => 'Bu taslağı düzenleme yetkiniz yok.',
            ];
        }

        if ('draft' === $status) {
            if ($state['can_submit_review']) {
                return [
                    'kind' => 'submit_review',
                    'label' => 'İncelemeye gönder',
                    'hint' => 'Gönderince sürüm mühürlenir ve yayın yetkilisine düşer.',
                ];
            }

            return [
                'kind' => 'waiting',
                'label' => 'İnceleme bekleniyor',
                'hint' => 'Bu taslağı incelemeye gönderme yetkiniz yok.',
            ];
        }

        if ('in_review' === $status) {
            if ($state['can_publish']) {
                return [
                    'kind' => 'publish',
                    'label' => 'Yayımla',
                    'hint' => 'Yayın, sürüm yazarından farklı bir yetkili tarafından yapılır.',
                ];
            }

            return [
                'kind' => 'waiting',
                'label' => 'Yayın bekleniyor',
                'hint' => 'Yayın yetkisi ayrıdır. Bu hesabın yayımla eylemi yoktur.',
            ];
        }

        if ('published' === $status && null === $state['access_class']) {
            if ($state['can_set_policy']) {
                return [
                    'kind' => 'set_policy',
                    'label' => 'Erişim politikasını kaydet',
                    'hint' => 'Politika yokken öğrenci gövdesi kapalı kalır. Ücretsiz işaret açık onay ister.',
                ];
            }

            return [
                'kind' => 'waiting',
                'label' => 'Erişim politikası bekleniyor',
                'hint' => 'Politika yetkisi olmayan hesap erişimi değiştiremez.',
            ];
        }

        if ('published' === $status && !$state['has_published_placement']) {
            if ($state['has_draft_placement'] && $state['can_publish_placement']) {
                return [
                    'kind' => 'publish_placement',
                    'label' => 'Yerleşimi yayımla',
                    'hint' => 'Taslak yerleşim öğrencide görünmez. Yayın ayrı onay ister.',
                ];
            }
            if (!$state['has_draft_placement'] && $state['can_create_placement']) {
                return [
                    'kind' => 'create_placement',
                    'label' => 'Taslak yerleşim oluştur',
                    'hint' => 'Başlık ve özet içerikten gelir. Kısa adres ve gerekçe kodu otomatiktir.',
                ];
            }
            if ($state['has_draft_placement']) {
                return [
                    'kind' => 'waiting',
                    'label' => 'Yerleşim yayını bekleniyor',
                    'hint' => 'Taslak yerleşimi yayımlama yetkisi bu hesapta yok.',
                ];
            }

            return [
                'kind' => 'waiting',
                'label' => 'Katalog yerleşimi bekleniyor',
                'hint' => 'Yerleşim oluşturma yetkisi bu hesapta yok.',
            ];
        }

        if ($visible) {
            return [
                'kind' => 'complete',
                'label' => 'Öğrenciye görünür',
                'hint' => 'Yayımlanmış ücretsiz içerik, mühürlü sürüm ve yayımlı yerleşim hazır.',
            ];
        }

        return [
            'kind' => 'waiting',
            'label' => 'Öğrenci gövdesi kapalı',
            'hint' => 'Ücretsiz politika, mühürlü sürüm ve yayımlı yerleşim birlikte olmadan gövde gösterilmez.',
        ];
    }
}
