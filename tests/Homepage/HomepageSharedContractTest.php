<?php

declare(strict_types=1);

namespace App\Tests\Homepage;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Shared contract between live `/` and the dev/test homepage preview.
 */
final class HomepageSharedContractTest extends WebTestCase
{
    public function testLiveHomeAndPreviewShareApprovedSectionContract(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $homeHtml = (string) $client->getResponse()->getContent();
        $home = new Crawler($homeHtml);

        $client->request('GET', '/onizleme/anasayfa-yeni');
        self::assertResponseIsSuccessful();
        $previewHtml = (string) $client->getResponse()->getContent();
        $preview = new Crawler($previewHtml);

        foreach ([$homeHtml, $previewHtml] as $html) {
            self::assertStringContainsString('Öğrenmek için', $html);
            self::assertStringContainsString('hero-world-clean.png', $html);
            self::assertStringContainsString('hp-hero', $html);
            self::assertStringContainsString('homepage-v2', $html);
            self::assertStringNotContainsString('Bugünkü öğrenme özeti', $html);
            self::assertStringNotContainsString('class="hero"', $html);
            self::assertStringNotContainsString('hero-preview', $html);
            self::assertStringNotContainsString('href="#"', $html);
            self::assertStringContainsString('Örnek arayüz — veriler temsilidir.', $html);
        }

        $expectedSectionIds = ['siniflar', 'seviyeler', 'vitrin', 'icerikler', 'ogretmenler', 'kurumlar', 'her-ekran', 'haberler'];
        foreach ([$home, $preview] as $crawler) {
            $sectionIds = $crawler->filter('section[id]')->each(
                static fn (Crawler $node): string => (string) $node->attr('id'),
            );
            self::assertSame($expectedSectionIds, $sectionIds);
            self::assertSame(1, $crawler->filter('h1')->count());
            self::assertSame(1, $crawler->filter('section.hp-hero')->count());
            self::assertGreaterThan(0, $crawler->filter('[data-controller="grade-books"]')->count());
            self::assertGreaterThan(0, $crawler->filter('a[href="/giris"]')->count());
            self::assertGreaterThan(0, $crawler->filter('a[href="/kayit"]')->count());
        }

        self::assertStringContainsString('homepage/_main.html.twig', (string) file_get_contents(\dirname(__DIR__, 2).'/templates/home/index.html.twig'));
        self::assertStringContainsString('homepage/_main.html.twig', (string) file_get_contents(\dirname(__DIR__, 2).'/templates/ui_preview/homepage_new.html.twig'));
    }
}
