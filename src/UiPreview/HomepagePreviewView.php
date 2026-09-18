<?php

declare(strict_types=1);

namespace App\UiPreview;

/**
 * Immutable demo payload for the approved homepage design preview (no DB).
 */
final class HomepagePreviewView
{
    /**
     * @param list<array{label: string, href: string, current?: bool}>                                                                                                         $navItems
     * @param list<array{id: string, label: string, color: string, selected?: bool}>                                                                                           $gradeBooks
     * @param list<array{title: string, body: string, symbol: string, accent?: string}>                                                                                        $gradeLearningTypes
     * @param list<array{id: string, title: string, body: string, image: string, accent: string, tint: string, actionLabel: string, actionHref: string|null, status?: string}> $levelCards
     * @param list<array{id: string, title: string, body: string, image: string, accent: string, tint: string, label: string}>                                                 $showcaseCards
     * @param list<array{title: string, body: string, image: string, imageAlt: string, linkLabel: string, linkHref: string}>                                                   $learningPath
     * @param list<array{title: string, items: list<string>, image: string, imageAlt: string, variant: string}>                                                                $audiences
     * @param list<string>                                                                                                                                                     $heroBenefits
     * @param list<string>                                                                                                                                                     $deviceTags
     * @param list<string>                                                                                                                                                     $trustItems
     */
    public function __construct(
        public readonly array $navItems,
        public readonly string $heroTitleHtml,
        public readonly string $heroLead,
        public readonly string $heroImage,
        public readonly string $heroImageAlt,
        public readonly array $heroBenefits,
        public readonly array $gradeBooks,
        public readonly string $gradePanelTitle,
        public readonly string $gradePanelLead,
        public readonly array $gradeLearningTypes,
        public readonly array $levelCards,
        public readonly array $showcaseCards,
        public readonly array $learningPath,
        public readonly array $audiences,
        public readonly string $institutionTitle,
        public readonly string $institutionBody,
        public readonly string $institutionImage,
        public readonly string $institutionImageAlt,
        public readonly string $devicesTitle,
        public readonly string $devicesBody,
        public readonly string $devicesImage,
        public readonly string $devicesImageAlt,
        public readonly array $deviceTags,
        public readonly array $trustItems,
        public readonly string $closingTitle,
        public readonly string $closingLead,
        public readonly string $newsTitle,
        public readonly string $newsBody,
        public readonly string $sectionDemoNote,
        public readonly string $showcaseStatus,
        public readonly string $newsStatus,
        public readonly string $devicesRoadmapNote,
    ) {
    }

    public static function demo(): self
    {
        return new self(
            navItems: [
                ['label' => 'Sınıflar', 'href' => '#siniflar'],
                ['label' => 'Haberler', 'href' => '#haberler'],
                ['label' => 'Simülasyonlar', 'href' => '#simulasyonlar'],
                ['label' => 'Dokümanlar', 'href' => '#dokumanlar'],
                ['label' => 'Testler', 'href' => '#testler'],
                ['label' => 'Videolar', 'href' => '#videolar'],
                ['label' => 'Soru-Cevap', 'href' => '#soru-cevap'],
                ['label' => 'Oyunlar', 'href' => '#oyunlar'],
            ],
            heroTitleHtml: 'Öğrenmek için<br>ihtiyacın olan<br>her şey burada.',
            heroLead: 'Dersler, etkinlikler, testler ve sana özel öneriler tek bir öğrenme alanında.',
            heroImage: '/images/homepage-preview/hero-world.png',
            heroImageAlt: 'Fen, matematik, Türkçe ve atölye alanlarından oluşan renkli öğrenme dünyası',
            heroBenefits: [
                'Zengin içerik',
                'Kişiye özel öneriler',
                'Her ekranda',
                'Güvenli öğrenme',
            ],
            gradeBooks: [
                ['id' => 'tab-1', 'label' => '1', 'color' => '#ef77b5'],
                ['id' => 'tab-2', 'label' => '2', 'color' => '#edba2d'],
                ['id' => 'tab-3', 'label' => '3', 'color' => '#22bfa5'],
                ['id' => 'tab-4', 'label' => '4', 'color' => '#2595ff', 'selected' => true],
                ['id' => 'tab-5', 'label' => '5', 'color' => '#9870e7'],
                ['id' => 'tab-6', 'label' => '6', 'color' => '#ff9147'],
                ['id' => 'tab-7', 'label' => '7', 'color' => '#21aaa3'],
                ['id' => 'tab-8', 'label' => '8 · LGS', 'color' => '#7553d5'],
            ],
            gradePanelTitle: '4. Sınıf',
            gradePanelLead: 'Merak et, öğren, kendini geliştir!',
            gradeLearningTypes: [
                ['title' => 'Konu Anlatımları', 'body' => 'Anlaşılır, eğlenceli ve sade içerikler', 'symbol' => '▣'],
                ['title' => 'Etkileşimli Çalışmalar', 'body' => 'Uygulayarak öğren, kalıcı hâle getir', 'symbol' => '✦', 'accent' => '#21b49a'],
                ['title' => 'Testler', 'body' => 'Bilgini ölç, öğrenmeye devam et', 'symbol' => '▤'],
                ['title' => 'Deneme Sınavları', 'body' => 'Kendini sınavlara hazırla', 'symbol' => '★', 'accent' => '#f5ac17'],
            ],
            levelCards: [
                [
                    'id' => 'ilkokul',
                    'title' => 'İlkokul',
                    'body' => '1–4. sınıflar · Merak ederek öğren, temel becerilerini geliştir.',
                    'image' => '/images/homepage-preview/level-ilkokul.png',
                    'accent' => '#b8770a',
                    'tint' => '#fff0ce',
                    'actionLabel' => 'Sınıfları keşfet',
                    'actionHref' => '#siniflar',
                ],
                [
                    'id' => 'ortaokul',
                    'title' => 'Ortaokul',
                    'body' => '5–8. sınıflar · Konuları pekiştir, sınavlara adım adım hazırlan.',
                    'image' => '/images/homepage-preview/level-ortaokul.png',
                    'accent' => '#0878e1',
                    'tint' => '#e4f2ff',
                    'actionLabel' => 'Sınıfları keşfet',
                    'actionHref' => '#siniflar',
                ],
                [
                    'id' => 'lise',
                    'title' => 'Lise',
                    'body' => '9–12. sınıflar · Bilgini derinleştir, hedeflerine güvenle ilerle.',
                    'image' => '/images/homepage-preview/level-lise.png',
                    'accent' => '#7850c9',
                    'tint' => '#efe8ff',
                    'actionLabel' => 'Alanı incele',
                    'actionHref' => null,
                    'status' => 'Hazırlanıyor',
                ],
            ],
            showcaseCards: [
                [
                    'id' => 'dokumanlar',
                    'title' => 'Dokümanlar',
                    'body' => 'Konu özetleri, çalışma kâğıtları ve tekrar kaynakları.',
                    'image' => '/images/homepage-preview/content-dokumanlar.png',
                    'accent' => '#b8770a',
                    'tint' => '#fff0ce',
                    'label' => 'KEŞFET',
                ],
                [
                    'id' => 'videolar',
                    'title' => 'Videolar',
                    'body' => 'Anlaşılır anlatımlarla konuları izle, kendi hızında öğren.',
                    'image' => '/images/homepage-preview/content-videolar.png',
                    'accent' => '#d3526c',
                    'tint' => '#ffe9ee',
                    'label' => 'KEŞFET',
                ],
                [
                    'id' => 'simulasyonlar',
                    'title' => 'Simülasyonlar',
                    'body' => 'Deneyerek keşfet, soyut kavramları görünür hâle getir.',
                    'image' => '/images/homepage-preview/content-simulasyonlar.png',
                    'accent' => '#7850c9',
                    'tint' => '#efe8ff',
                    'label' => 'KEŞFET',
                ],
                [
                    'id' => 'testler',
                    'title' => 'Testler',
                    'body' => 'Öğrendiklerini pekiştir, güçlü ve eksik konularını fark et.',
                    'image' => '/images/homepage-preview/content-testler.png',
                    'accent' => '#0878e1',
                    'tint' => '#e4f2ff',
                    'label' => 'KEŞFET',
                ],
                [
                    'id' => 'soru-cevap',
                    'title' => 'Soru-Cevap',
                    'body' => 'Merak ettiğini sor, farklı çözüm yollarını birlikte keşfet.',
                    'image' => '/images/homepage-preview/content-soru-cevap.png',
                    'accent' => '#138e80',
                    'tint' => '#def6ed',
                    'label' => 'KEŞFET',
                ],
                [
                    'id' => 'oyunlar',
                    'title' => 'Eğitici Oyunlar',
                    'body' => 'Düşün, çöz ve keşfet. Öğrenmeye eğlenceli bir mola ver.',
                    'image' => '/images/homepage-preview/content-oyunlar.png',
                    'accent' => '#c36d19',
                    'tint' => '#ffeddc',
                    'label' => 'KEŞFET',
                ],
            ],
            learningPath: [
                [
                    'title' => 'Keşfet',
                    'body' => 'Merak uyandıran, etkileşimli içeriklerle konuları keşfet.',
                    'image' => '/images/homepage-preview/path-discover.png',
                    'imageAlt' => 'Deney yaparak öğrenen öğrenci illüstrasyonu',
                    'linkLabel' => 'Sınıfları incele',
                    'linkHref' => '#siniflar',
                ],
                [
                    'title' => 'Uygula',
                    'body' => 'Sorularla öğrendiklerini pekiştir. Geri bildirimlerle hatalarından öğren.',
                    'image' => '/images/homepage-preview/path-practice.png',
                    'imageAlt' => 'Etkileşimli soru çözme ekranı tasarım örneği',
                    'linkLabel' => 'Öğrenme alanlarını gör',
                    'linkHref' => '#vitrin',
                ],
                [
                    'title' => 'Güçlen',
                    'body' => 'Gelişimini takip et, ihtiyaç duyduğun konulara odaklan.',
                    'image' => '/images/homepage-preview/path-progress.png',
                    'imageAlt' => 'Örnek öğrenci ilerleme raporu illüstrasyonu; sayılar temsilidir',
                    'linkLabel' => 'Rehberliği keşfet',
                    'linkHref' => '#ogretmenler',
                ],
            ],
            audiences: [
                [
                    'title' => 'Öğretmenler için',
                    'items' => [
                        'Sınıfa etkinlik atama',
                        'Öğrenci ilerlemesini takip etme',
                        'Anlaşılır gelişim raporları',
                    ],
                    'image' => '/images/homepage-preview/audience-teacher.png',
                    'imageAlt' => 'Öğretmen sınıf raporu tasarım örneği',
                    'variant' => 'teacher',
                ],
                [
                    'title' => 'Veliler için',
                    'items' => [
                        'Haftalık öğrenme özeti',
                        'Net öneriler ve yönlendirmeler',
                        'Çocuğun gelişimine destek',
                    ],
                    'image' => '/images/homepage-preview/audience-parent.png',
                    'imageAlt' => 'Örnek veli haftalık özet ekranı',
                    'variant' => 'parent',
                ],
            ],
            institutionTitle: 'Okullar için güçlü ve kolay yönetim.',
            institutionBody: 'Sınıflar, öğrenciler ve raporlar tek bir ekranda. Eğitim sürecini birlikte yönetin.',
            institutionImage: '/images/homepage-preview/institution-panel.png',
            institutionImageAlt: 'Temsili kurum yönetim paneli; istatistikler gerçek veri değildir',
            devicesTitle: 'Evde, okulda, her ekranda.',
            devicesBody: 'Testlig deneyimini bilgisayar, tablet ve telefon ekranlarında keşfet.',
            devicesImage: '/images/homepage-preview/devices-trio.png',
            devicesImageAlt: 'Telefon, tablet ve bilgisayar için Testlig tasarım örnekleri',
            deviceTags: ['Telefon', 'Tablet', 'Bilgisayar'],
            trustItems: [
                'Öğrenme odaklı',
                'Öğrenci, öğretmen ve veli',
                'Her ekrana uygun tasarım',
            ],
            closingTitle: 'Testlig’le öğrenmeye bugün başla.',
            closingLead: 'Daha parlak bir gelecek, seninle mümkün.',
            newsTitle: 'Haberler ve öğrenme rehberleri',
            newsBody: 'Platform duyuruları ve eğitim rehberleri için ayrılmış vitrin alanı.',
            sectionDemoNote: 'Tasarım önizlemesi — sınıf seçimi örnek gösterimdir; içerik filtrelemez.',
            showcaseStatus: 'Bu alanlar tasarım önizlemesidir; içerik servisleri henüz bağlı değildir.',
            newsStatus: 'İçerikler hazırlanıyor — gerçek haber akışı bağlı değildir.',
            devicesRoadmapNote: 'iOS ve Android uygulamaları yol haritamızda.',
        );
    }
}
