<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Enum\GradeLevel;
use App\Service\CatalogWriteService;
use App\Service\PublicCatalogQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * The homepage "Sınıfını seç" section is driven by the same published catalog rows as /dersler.
 */
final class HomepageGradeCatalogTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearCatalog();
    }

    protected function tearDown(): void
    {
        $this->clearCatalog();
        parent::tearDown();
    }

    public function testGradesLinkToTheirOwnCatalogOnlyWhenPublishedContentExists(): void
    {
        $client = static::createClient();
        $writer = $this->writer($client);

        $this->publishSubjectWithUnit($writer, GradeLevel::Grade1, 'Hgc Matematik', 'hgc-mat', 'hgc-unite-1');

        // None of these may make a grade "ready" on the anonymous homepage.
        $draftUnitOnly = $writer->createSubject(GradeLevel::Grade2, 'Hgc Sadece Taslak Unite', null, 1, 'hgc-taslak-unite');
        $writer->publishSubject($draftUnitOnly->getId());
        $writer->createUnit($draftUnitOnly->getId(), 'Hgc Taslak Unite', null, 1, 'hgc-taslak-u');
        $writer->createSubject(GradeLevel::Grade3, 'Hgc Taslak Ders', null, 1, 'hgc-taslak-ders');
        $archived = $this->publishSubjectWithUnit($writer, GradeLevel::Grade4, 'Hgc Arsiv Ders', 'hgc-arsiv', 'hgc-arsiv-u');
        $writer->archiveSubject($archived);
        $unitless = $writer->createSubject(GradeLevel::Grade5, 'Hgc Uniteleri Yok', null, 1, 'hgc-unitesiz');
        $writer->publishSubject($unitless->getId());

        $catalog = $client->getContainer()->get(PublicCatalogQuery::class);
        self::assertInstanceOf(PublicCatalogQuery::class, $catalog);
        $summaries = $catalog->gradesWithContent();
        self::assertSame(1, $catalog->statements());
        self::assertSame([1], array_keys($summaries));
        self::assertSame(1, $summaries[1]->subjectCount);
        self::assertSame(1, $summaries[1]->unitCount);

        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertCount(8, $crawler->filter('#siniflar [role="tab"]'));
        self::assertCount(8, $crawler->filter('#siniflar [role="tabpanel"]'));
        self::assertSame('true', $crawler->filter('#tab-1')->attr('aria-selected'));
        self::assertSame('0', $crawler->filter('#tab-1')->attr('tabindex'));
        foreach (range(2, 8) as $grade) {
            self::assertSame('false', $crawler->filter('#tab-'.$grade)->attr('aria-selected'));
            self::assertSame('-1', $crawler->filter('#tab-'.$grade)->attr('tabindex'));
        }
        self::assertSame('grade-panel-1', $crawler->filter('#tab-1')->attr('aria-controls'));
        self::assertSame('tab-1', $crawler->filter('#grade-panel-1')->attr('aria-labelledby'));
        self::assertNull($crawler->filter('#grade-panel-1')->attr('hidden'));
        self::assertNotNull($crawler->filter('#grade-panel-2')->attr('hidden'));

        $ready = $crawler->filter('#grade-panel-1');
        self::assertSame('true', $ready->attr('data-grade-ready'));
        self::assertSame('/dersler/1', $ready->filter('a.btn--cta')->attr('href'));
        self::assertSame('1. sınıf derslerini gör', trim($ready->filter('a.btn--cta')->text()));
        self::assertStringContainsString('1 ders · 1 ünite', $ready->text());
        self::assertStringContainsString('öğrenci hesabıyla açılır', $ready->text());
        self::assertStringNotContainsStringIgnoringCase('ücretsiz', $ready->text());

        foreach (range(2, 8) as $grade) {
            $panel = $crawler->filter('#grade-panel-'.$grade);
            self::assertSame('false', $panel->attr('data-grade-ready'), 'grade '.$grade);
            self::assertCount(0, $panel->filter('a, button'), 'grade '.$grade.' must not offer a catalog CTA');
            self::assertStringContainsString('İçerikler hazırlanıyor', $panel->text());
            self::assertStringContainsString('hazırlanıyor', (string) $crawler->filter('#tab-'.$grade)->attr('aria-label'));
        }

        self::assertSame(['/dersler/1'], $this->panelHrefs($crawler));
        self::assertSame(0, $crawler->filter('a[href="#"]')->count());
        self::assertStringNotContainsString('örnek gösterimdir', $html);
        self::assertStringNotContainsString('Hgc Sadece Taslak', $html);
        self::assertStringNotContainsString('Hgc Taslak', $html);
        self::assertStringNotContainsString('Hgc Arsiv', $html);
        self::assertStringNotContainsString('Hgc Uniteleri', $html);
        self::assertStringContainsString('Yayımlanmış dersi olan sınıflar', $crawler->filter('#siniflar .hp-note')->text());

        // Visitor path: homepage CTA -> grade catalog -> Matematik -> unit.
        $client->request('GET', '/dersler/1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', '1. sınıf dersleri');
        self::assertSelectorTextContains('main, .catalog-wrap', 'Hgc Matematik');
        $client->request('GET', '/dersler/1/hgc-mat');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Hgc Matematik');
        $client->request('GET', '/dersler/1/hgc-mat/hgc-unite-1');
        self::assertResponseIsSuccessful();

        // Draft and archived subjects stay opaque in the catalog itself.
        foreach (['/dersler/3', '/dersler/4'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
        }
        // A published subject without any published unit has a catalog page, but the homepage does not
        // advertise that grade as ready (asserted above): no course content to open yet.
        foreach (['/dersler/2', '/dersler/5'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
        }
    }

    public function testSecondGradeOpensWithItsOwnTargetAndSelectionStaysOnLowestReadyGrade(): void
    {
        $client = static::createClient();
        $writer = $this->writer($client);

        $this->publishSubjectWithUnit($writer, GradeLevel::Grade6, 'Hgc Alti Matematik', 'hgc-alti-mat', 'hgc-alti-u');
        $crawler = $client->request('GET', '/');
        self::assertSame('true', $crawler->filter('#tab-6')->attr('aria-selected'));
        self::assertSame('0', $crawler->filter('#tab-6')->attr('tabindex'));
        self::assertSame('false', $crawler->filter('#tab-1')->attr('aria-selected'));
        self::assertSame(['/dersler/6'], $this->panelHrefs($crawler));

        $this->publishSubjectWithUnit($writer, GradeLevel::Grade1, 'Hgc Bir Matematik', 'hgc-bir-mat', 'hgc-bir-u');
        $this->publishSubjectWithUnit($writer, GradeLevel::Grade8, 'Hgc Sekiz Matematik', 'hgc-sekiz-mat', 'hgc-sekiz-u');
        $crawler = $client->request('GET', '/');
        self::assertSame('true', $crawler->filter('#tab-1')->attr('aria-selected'));
        self::assertSame('false', $crawler->filter('#tab-6')->attr('aria-selected'));
        self::assertSame(['/dersler/1', '/dersler/6', '/dersler/8'], $this->panelHrefs($crawler));
        self::assertSame('/dersler/8', $crawler->filter('#grade-panel-8 a.btn--cta')->attr('href'));
        self::assertStringContainsString('8. sınıf LGS', (string) $crawler->filter('#tab-8')->attr('aria-label'));

        foreach (['/dersler/1', '/dersler/6', '/dersler/8'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
        }
        foreach ([2, 3, 4, 5, 7] as $grade) {
            self::assertSame('false', $crawler->filter('#grade-panel-'.$grade)->attr('data-grade-ready'));
        }
    }

    public function testEmptyCatalogShowsPreparationStateWithoutAnyCatalogLink(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        self::assertCount(8, $crawler->filter('#siniflar [role="tab"]'));
        self::assertSame('true', $crawler->filter('#tab-1')->attr('aria-selected'));
        self::assertSame([], $this->panelHrefs($crawler));
        foreach (range(1, 8) as $grade) {
            self::assertSame('false', $crawler->filter('#grade-panel-'.$grade)->attr('data-grade-ready'));
            self::assertCount(0, $crawler->filter('#grade-panel-'.$grade.' a, #grade-panel-'.$grade.' button'));
        }
        self::assertStringContainsString('Henüz yayımlanmış ders yok', $crawler->filter('#siniflar .hp-note')->text());
        self::assertSame(1, $crawler->filter('#grade-panel-1:not([hidden])')->count());
        self::assertSame(7, $crawler->filter('#siniflar [role="tabpanel"][hidden]')->count());

        // The unfiltered catalog behaves as before for visitors.
        $client->request('GET', '/dersler');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Dersleri keşfet');
    }

    public function testHomepageMenuAndDrawerStillSharePublicNavigation(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['/', '/dersler', '/haberler'],
            $crawler->filter('nav[aria-label="Ana menü"] a')->each(static fn (Crawler $node): string => (string) $node->attr('href')),
        );
        self::assertSame(
            ['/', '/dersler', '/haberler'],
            $crawler->filter('nav[aria-label="Mobil ana menü"] a')->each(static fn (Crawler $node): string => (string) $node->attr('href')),
        );
        self::assertStringNotContainsString('#sinavlar', (string) $client->getResponse()->getContent());
    }

    /**
     * The suite shares one database; leave the catalog empty before and after so other tests keep their assumptions.
     */
    private function clearCatalog(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $conn = $em->getConnection();
        $schema = $conn->createSchemaManager();
        foreach (['catalog_topic_lessons', 'catalog_topics', 'catalog_units', 'catalog_subjects'] as $table) {
            if ($schema->tablesExist([$table])) {
                $conn->executeStatement('DELETE FROM '.$table);
            }
        }
        self::ensureKernelShutdown();
    }

    private function writer(KernelBrowser $client): CatalogWriteService
    {
        $writer = $client->getContainer()->get(CatalogWriteService::class);
        self::assertInstanceOf(CatalogWriteService::class, $writer);

        return $writer;
    }

    private function publishSubjectWithUnit(CatalogWriteService $writer, GradeLevel $grade, string $name, string $slug, string $unitSlug): Uuid
    {
        $subject = $writer->createSubject($grade, $name, null, 1, $slug);
        $writer->publishSubject($subject->getId());
        $unit = $writer->createUnit($subject->getId(), $name.' Ünite', null, 1, $unitSlug);
        $writer->publishUnit($unit->getId());

        return $subject->getId();
    }

    /**
     * @return list<string>
     */
    private function panelHrefs(Crawler $crawler): array
    {
        return $crawler->filter('#siniflar .hp-grade-panel a')->each(static fn (Crawler $node): string => (string) $node->attr('href'));
    }
}
