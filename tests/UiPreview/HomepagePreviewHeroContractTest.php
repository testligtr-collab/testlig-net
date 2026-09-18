<?php

declare(strict_types=1);

namespace App\Tests\UiPreview;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class HomepagePreviewHeroContractTest extends WebTestCase
{
    public function testLiveHomeRemainsUnchangedWhilePreviewUsesDedicatedHero(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $homeHtml = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Öğren, çöz, gelişimini gör.', $homeHtml);
        self::assertStringContainsString('Bugünkü öğrenme özeti', $homeHtml);
        self::assertStringContainsString('Testlig nasıl yardımcı olur?', $homeHtml);
        self::assertStringNotContainsString('Öğrenmek için', $homeHtml);
        self::assertStringNotContainsString('hero-world.png', $homeHtml);
        self::assertStringNotContainsString('Sınıfını seç, içerikleri keşfet.', $homeHtml);
        self::assertStringNotContainsString('Öğrenmenin pek çok yolu var.', $homeHtml);

        $homeHero = $this->extractSection($homeHtml, 'hero');
        self::assertStringContainsString('hero-preview', $homeHero);
        self::assertStringNotContainsString('hp-hero', $homeHero);

        $client->request('GET', '/onizleme/anasayfa-yeni');
        self::assertResponseIsSuccessful();
        $previewHtml = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Öğrenmek için', $previewHtml);
        self::assertStringContainsString('ihtiyacın olan', $previewHtml);
        self::assertStringContainsString('her şey burada.', $previewHtml);
        self::assertStringContainsString(
            'Dersler, etkinlikler, testler ve sana özel öneriler tek bir öğrenme alanında.',
            $previewHtml,
        );
        self::assertStringContainsString('hero-world.png', $previewHtml);
        self::assertStringContainsString('hp-hero', $previewHtml);
        self::assertStringNotContainsString('Bugünkü öğrenme özeti', $previewHtml);
        self::assertStringNotContainsString('class="hero"', $previewHtml);

        $previewCrawler = new Crawler($previewHtml);
        $hero = $previewCrawler->filter('section.hp-hero');
        self::assertCount(1, $hero);
        self::assertGreaterThan(0, $hero->filter('a:contains("Ücretsiz Başla")')->count());
        self::assertGreaterThan(0, $hero->filter('a[href="#icerikler"]:contains("İçerikleri Keşfet")')->count());
        self::assertSame(0, $hero->filter('form')->count());
        self::assertSame(0, $hero->filter('input[type="password"]')->count());
        self::assertSame(0, $hero->filter('[class*="carousel"], [class*="slider"]')->count());
    }

    public function testPreviewSectionsNavAndSafetyContracts(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/onizleme/anasayfa-yeni');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertSelectorTextContains('#siniflar', 'Sınıfını seç, içerikleri keşfet.');
        self::assertSelectorTextContains('#seviyeler', 'Her seviyede yeni bir keşif.');
        self::assertSelectorTextContains('#vitrin', 'Öğrenmenin pek çok yolu var.');
        self::assertSelectorTextContains('#icerikler', 'İzlemekle kalma, öğrenmenin içine gir.');
        self::assertSelectorTextContains('#ogretmenler', 'Birlikte daha güçlü.');
        self::assertSelectorTextContains('#kurumlar', 'Okullar için güçlü ve kolay yönetim.');
        self::assertSelectorTextContains('#her-ekran', 'Evde, okulda, her ekranda.');
        self::assertSelectorTextContains('#haberler', 'Haberler ve öğrenme rehberleri');
        self::assertSelectorExists('#dokumanlar');
        self::assertSelectorExists('#videolar');
        self::assertSelectorExists('#simulasyonlar');
        self::assertSelectorExists('#testler');
        self::assertSelectorExists('#soru-cevap');
        self::assertSelectorExists('#oyunlar');

        $navLabels = $crawler->filter('nav[aria-label="Ana menü"] a')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
        self::assertSame([
            'Sınıflar',
            'Haberler',
            'Simülasyonlar',
            'Dokümanlar',
            'Testler',
            'Videolar',
            'Soru-Cevap',
            'Oyunlar',
        ], $navLabels);

        self::assertSame(0, $crawler->filter('a[href="#"]')->count());
        self::assertStringNotContainsString('href="#"', $html);
        self::assertDoesNotMatchRegularExpression('/type="email"|newsletter|mailchimp/i', $html);
        self::assertStringNotContainsStringIgnoringCase('google ads', $html);
        self::assertStringNotContainsStringIgnoringCase('googlesyndication', $html);
        self::assertStringNotContainsString('cdn.', mb_strtolower($html));
        self::assertStringNotContainsString('fonts.googleapis', mb_strtolower($html));
        self::assertDoesNotMatchRegularExpression('/data:image\\//i', $html);
        self::assertStringNotContainsString('OKUL PLATFORMU', mb_strtoupper($html));
        self::assertStringNotContainsString('mobile-app.png', $html);
        self::assertStringNotContainsStringIgnoringCase('mobil uygulamamız yayında', $html);
        self::assertStringNotContainsString('apps.apple.com', mb_strtolower($html));
        self::assertStringNotContainsString('play.google.com', mb_strtolower($html));
        self::assertStringNotContainsString('app-store-badge', $html);
        self::assertStringNotContainsString('google-play-badge', $html);

        self::assertGreaterThanOrEqual(1, substr_count($html, 'İçerikler hazırlanıyor'));
        self::assertGreaterThanOrEqual(6, substr_count($html, 'Hazırlanıyor'));
        self::assertLessThan(8, substr_count(mb_strtolower($html), 'yakında'));
        self::assertStringContainsString('© 2026 Testlig', $html);
        self::assertStringContainsString('role="status"', $html);
        self::assertSelectorExists('.hp-section--devices');
        self::assertSelectorExists('a.skip-link');
        self::assertSelectorExists('[data-controller="grade-books"]');
    }

    public function testCanonicalPublicPreviewAssetsAndNoDuplicateTree(): void
    {
        $root = \dirname(__DIR__, 2);
        self::assertDirectoryExists($root.'/public/images/homepage-preview');
        self::assertDirectoryDoesNotExist($root.'/assets/images/homepage-preview');
        self::assertDirectoryDoesNotExist($root.'/assets/images');

        foreach ([
            'hero-world.png',
            'level-ilkokul.png',
            'level-ortaokul.png',
            'level-lise.png',
            'content-dokumanlar.png',
            'content-videolar.png',
            'content-simulasyonlar.png',
            'content-testler.png',
            'content-soru-cevap.png',
            'content-oyunlar.png',
            'path-discover.png',
            'path-practice.png',
            'path-progress.png',
            'audience-teacher.png',
            'audience-parent.png',
            'institution-panel.png',
            'devices-trio.png',
        ] as $file) {
            self::assertFileExists($root.'/public/images/homepage-preview/'.$file);
        }

        self::assertFileDoesNotExist($root.'/public/images/homepage-preview/mobile-app.png');
        self::assertFileDoesNotExist($root.'/public/images/homepage-preview/logo.png');
        self::assertFileDoesNotExist($root.'/public/images/homepage-preview/favicon.png');
        self::assertFileDoesNotExist($root.'/public/images/homepage-preview/google-ads.png');

        $css = (string) file_get_contents($root.'/assets/styles/app.css');
        self::assertStringContainsString('.homepage-preview-v2', $css);
        self::assertStringNotContainsString('overflow-x: hidden', $css);
        self::assertStringNotContainsString('overflow-x:hidden', $css);
        self::assertDoesNotMatchRegularExpression('/^\\s*(body|header|footer|\\.btn|\\.card|\\.section|img)\\s*\\{/m', $this->previewCssBlock($css));
    }

    private function extractSection(string $html, string $class): string
    {
        if (!preg_match('/<section class="'.preg_quote($class, '/').'"[^>]*>.*?<\\/section>/s', $html, $matches)) {
            self::fail(\sprintf('Section .%s not found.', $class));
        }

        return $matches[0];
    }

    private function previewCssBlock(string $css): string
    {
        $pos = strpos($css, 'Homepage approved design preview');
        self::assertNotFalse($pos);

        return substr($css, $pos);
    }
}
