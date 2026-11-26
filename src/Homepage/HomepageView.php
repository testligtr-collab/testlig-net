<?php

declare(strict_types=1);

namespace App\Homepage;

use App\Dto\PublicCatalogGradeSummary;

/**
 * Immutable presentation payload for the approved public homepage (no DB).
 * Shared by live `/` and the dev/test preview route.
 */
final class HomepageView
{
    /**
     * @param list<array{id: string, panelId: string, grade: int, label: string, color: string, selected: bool, subjectCount: int, unitCount: int, ready: bool}>               $gradeBooks
     * @param list<array{id: string, title: string, body: string, image: string, accent: string, tint: string, actionLabel: string, actionHref: string|null, status?: string}> $levelCards
     * @param list<array{id: string, title: string, body: string, image: string, accent: string, tint: string, label: string}>                                                 $showcaseCards
     * @param list<array{title: string, body: string, image: string, imageAlt: string, linkLabel: string, linkHref: string, imageCaption?: string}>                            $learningPath
     * @param list<array{title: string, items: list<string>, image: string, imageAlt: string, variant: string}>                                                                $audiences
     * @param list<string>                                                                                                                                                     $heroBenefits
     * @param list<string>                                                                                                                                                     $deviceTags
     * @param list<string>                                                                                                                                                     $trustItems
     */
    public function __construct(
        public readonly string $heroTitleHtml,
        public readonly string $heroLead,
        public readonly string $heroImage,
        public readonly string $heroImageAlt,
        public readonly array $heroBenefits,
        public readonly array $gradeBooks,
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
        public readonly string $gradeSelectionNote,
        public readonly string $showcaseStatus,
        public readonly string $newsStatus,
        public readonly string $devicesRoadmapNote,
    ) {
    }

    /**
     * @param array<int, PublicCatalogGradeSummary> $gradeSummaries published catalog totals keyed by grade (1-12);
     *                                                              empty when nothing is published or for the static preview
     */
    public static function demo(array $gradeSummaries = []): self
    {
        $gradeBooks = self::gradeBooks($gradeSummaries);
        $anyReady = [] !== array_filter($gradeBooks, static fn (array $book): bool => $book['ready']);

        return new self(
            heroTitleHtml: 'Öğrenmek için<br>ihtiyacın olan<br>her şey burada.',
            heroLead: 'Dersler, etkinlikler, testler ve sana özel öneriler tek bir öğrenme alanında.',
            heroImage: '/images/homepage-preview/hero-world-clean.png',
            heroImageAlt: 'Fen, matematik, Türkçe ve atölye alanlarından oluşan renkli öğrenme dünyası',
            heroBenefits: [
                'Zengin içerik',
                'Kişiye özel öneriler',
                'Her ekranda',
                'Güvenli öğrenme',
            ],
            gradeBooks: $gradeBooks,
            levelCards: [
                [
                    'id' => 'ilkokul',
                    'title' => 'İlkokul',
                    'body' => '1–4. sınıflar · Merak ederek öğren, temel becerilerini geliştir.',
                    'image' => '/images/homepage-preview/level-ilkokul.png',
                    'accent' => '#b8770a',
                    'tint' => '#fff0ce',
                    'actionLabel' => 'Sınıfları keşfet',
                    'actionHref' => '/dersler',
                ],
                [
                    'id' => 'ortaokul',
                    'title' => 'Ortaokul',
                    'body' => '5–8. sınıflar · Konuları pekiştir, sınavlara adım adım hazırlan.',
                    'image' => '/images/homepage-preview/level-ortaokul.png',
                    'accent' => '#0878e1',
                    'tint' => '#e4f2ff',
                    'actionLabel' => 'Sınıfları keşfet',
                    'actionHref' => '/dersler',
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
                    'status' => 'İçerikler hazırlanıyor',
                ],
                [
                    'id' => 'universite-hazirlik',
                    'title' => 'Üniversite Hazırlık',
                    'body' => 'TYT · AYT · YDT · Hedefine güvenle ilerlemek için hazırlık alanı.',
                    'image' => '/images/homepage-preview/level-universite.png',
                    'accent' => '#3f3d9e',
                    'tint' => '#e8e7ff',
                    'actionLabel' => 'Alanı incele',
                    'actionHref' => null,
                    'status' => 'İçerikler hazırlanıyor',
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
                    'label' => 'VİTRİN',
                ],
                [
                    'id' => 'videolar',
                    'title' => 'Videolar',
                    'body' => 'Anlaşılır anlatımlarla konuları izle, kendi hızında öğren.',
                    'image' => '/images/homepage-preview/content-videolar.png',
                    'accent' => '#d3526c',
                    'tint' => '#ffe9ee',
                    'label' => 'VİTRİN',
                ],
                [
                    'id' => 'simulasyonlar',
                    'title' => 'Simülasyonlar',
                    'body' => 'Deneyerek keşfet, soyut kavramları görünür hâle getir.',
                    'image' => '/images/homepage-preview/content-simulasyonlar.png',
                    'accent' => '#7850c9',
                    'tint' => '#efe8ff',
                    'label' => 'VİTRİN',
                ],
                [
                    'id' => 'testler',
                    'title' => 'Testler',
                    'body' => 'Öğrendiklerini pekiştir, güçlü ve eksik konularını fark et.',
                    'image' => '/images/homepage-preview/content-testler.png',
                    'accent' => '#0878e1',
                    'tint' => '#e4f2ff',
                    'label' => 'VİTRİN',
                ],
                [
                    'id' => 'soru-cevap',
                    'title' => 'Soru-Cevap',
                    'body' => 'Merak ettiğini sor, farklı çözüm yollarını birlikte keşfet.',
                    'image' => '/images/homepage-preview/content-soru-cevap.png',
                    'accent' => '#138e80',
                    'tint' => '#def6ed',
                    'label' => 'VİTRİN',
                ],
                [
                    'id' => 'oyunlar',
                    'title' => 'Eğitici Oyunlar',
                    'body' => 'Düşün, çöz ve keşfet. Öğrenmeye eğlenceli bir mola ver.',
                    'image' => '/images/homepage-preview/content-oyunlar.png',
                    'accent' => '#c36d19',
                    'tint' => '#ffeddc',
                    'label' => 'VİTRİN',
                ],
            ],
            learningPath: [
                [
                    'title' => 'Keşfet',
                    'body' => 'Merak uyandıran, etkileşimli içeriklerle konuları keşfet.',
                    'image' => '/images/homepage-preview/path-discover.png',
                    'imageAlt' => 'Deney yaparak öğrenen öğrenci illüstrasyonu',
                    'linkLabel' => 'Sınıfları incele',
                    'linkHref' => '/dersler',
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
                    'imageAlt' => 'Örnek öğrenci ilerleme raporu illüstrasyonu; yüzde ve ders değerleri temsilidir',
                    'imageCaption' => 'Örnek arayüz — veriler temsilidir.',
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
            institutionImageAlt: 'Temsili kurum yönetim paneli; paneldeki sayılar gerçek veri değildir',
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
            newsBody: 'Platform duyuruları ve eğitim rehberleri burada yer alacak.',
            gradeSelectionNote: $anyReady
                ? 'Yayımlanmış dersi olan sınıflar ders kataloğuna bağlanır; diğer sınıflar hazırlanıyor olarak görünür. Ders içeriği öğrenci hesabıyla açılır.'
                : 'Henüz yayımlanmış ders yok. İçerikler hazır oldukça sınıflar ders kataloğuna bağlanacak.',
            showcaseStatus: 'Bu alanlar vitrin gösterimidir; içerik servisleri henüz bağlı değildir.',
            newsStatus: 'Hazırlanıyor',
            devicesRoadmapNote: 'iOS ve Android uygulamaları yol haritamızda.',
        );
    }

    /**
     * Grades 1-8 keep the existing book colours and labels; whether a book links to the
     * catalog comes only from the published catalog totals passed in.
     *
     * @param array<int, PublicCatalogGradeSummary> $gradeSummaries
     *
     * @return list<array{id: string, panelId: string, grade: int, label: string, color: string, selected: bool, subjectCount: int, unitCount: int, ready: bool}>
     */
    private static function gradeBooks(array $gradeSummaries): array
    {
        $colors = [1 => '#ef77b5', 2 => '#edba2d', 3 => '#22bfa5', 4 => '#2595ff', 5 => '#9870e7', 6 => '#ff9147', 7 => '#21aaa3', 8 => '#7553d5'];
        $selectedGrade = 1;
        foreach (array_keys($colors) as $grade) {
            if (isset($gradeSummaries[$grade]) && $gradeSummaries[$grade]->subjectCount > 0) {
                $selectedGrade = $grade;
                break;
            }
        }

        $books = [];
        foreach ($colors as $grade => $color) {
            $summary = $gradeSummaries[$grade] ?? null;
            $subjects = $summary->subjectCount ?? 0;
            $books[] = [
                'id' => 'tab-'.$grade,
                'panelId' => 'grade-panel-'.$grade,
                'grade' => $grade,
                'label' => 8 === $grade ? '8 · LGS' : (string) $grade,
                'color' => $color,
                'selected' => $grade === $selectedGrade,
                'subjectCount' => $subjects,
                'unitCount' => $summary->unitCount ?? 0,
                'ready' => $subjects > 0,
            ];
        }

        return $books;
    }
}
