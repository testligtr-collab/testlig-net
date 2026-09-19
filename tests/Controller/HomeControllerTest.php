<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class HomeControllerTest extends WebTestCase
{
    public function testHomePageReturnsHttpOkWithApprovedDesign(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Öğrenmek için');
        self::assertSelectorExists('a.skip-link');
        self::assertSelectorExists('header.site-header');
        self::assertSelectorExists('section.hp-hero');
        self::assertSelectorExists('[data-controller="grade-books"]');
        self::assertStringContainsString('hero-world-clean.png', $html);
        self::assertStringNotContainsString('Bugünkü öğrenme özeti', $html);
        self::assertStringNotContainsString('hero-preview', $html);
        self::assertStringNotContainsString('Öğren, çöz, gelişimini gör.', $html);
        self::assertStringNotContainsString('href="#"', $html);
        self::assertSame(0, $crawler->filter('a[href="#"]')->count());

        self::assertGreaterThan(0, $crawler->filter('a[href="/giris"]:contains("Giriş Yap")')->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="/kayit"]:contains("Kayıt Ol")')->count());

        $sectionIds = $crawler->filter('section[id]')->each(
            static fn (Crawler $node): string => (string) $node->attr('id'),
        );
        self::assertSame($sectionIds, array_values(array_unique($sectionIds)));
        self::assertSame(
            ['siniflar', 'seviyeler', 'vitrin', 'icerikler', 'ogretmenler', 'kurumlar', 'her-ekran', 'haberler'],
            $sectionIds,
        );
    }
}
