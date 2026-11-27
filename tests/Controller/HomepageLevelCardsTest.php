<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Enum\GradeLevel;
use App\Service\CatalogWriteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * Homepage education-level cards lead to the published grades of their own level, not to the unfiltered catalog.
 */
final class HomepageLevelCardsTest extends WebTestCase
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

    public function testEmptyCatalogShowsPreparationOnEveryLevelAndNoCatalogLink(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        self::assertSame([], $crawler->filter('#seviyeler a')->each(static fn (Crawler $node): string => (string) $node->attr('href')));
        self::assertCount(4, $crawler->filter('#seviyeler article.hp-vcard'));
        self::assertCount(4, $crawler->filter('#seviyeler .hp-chip[role="status"]'));
        foreach ($crawler->filter('#seviyeler .hp-chip') as $chip) {
            self::assertSame('İçerikler hazırlanıyor', trim($chip->textContent));
        }

        // Unfiltered catalog is unchanged.
        $client->request('GET', '/dersler');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Dersleri keşfet');
    }

    public function testIlkokulReachesRealGradeOneMathematicsAndOtherLevelsStayInPreparation(): void
    {
        $client = static::createClient();
        $writer = $this->writer($client);

        $this->publishSubjectWithUnit($writer, GradeLevel::Grade1, 'Hlc Matematik', 'hlc-mat', 'hlc-unite');
        // None of these may create a usable level.
        $writer->createSubject(GradeLevel::Grade5, 'Hlc Ortaokul Taslak', null, 1, 'hlc-ortaokul-taslak');
        $unitless = $writer->createSubject(GradeLevel::Grade6, 'Hlc Unitesiz', null, 1, 'hlc-unitesiz');
        $writer->publishSubject($unitless->getId());
        $archived = $this->publishSubjectWithUnit($writer, GradeLevel::Grade9, 'Hlc Lise Arsiv', 'hlc-lise-arsiv', 'hlc-lise-arsiv-u');
        $writer->archiveSubject($archived);

        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $ilkokul = $crawler->filter('#ilkokul');
        self::assertSame('/dersler/1', $ilkokul->filter('a.hp-vcard__action')->attr('href'));
        self::assertStringContainsString('1. sınıf derslerini gör', $ilkokul->filter('a.hp-vcard__action')->text());
        self::assertStringContainsString('Şu an yayımlanmış sınıf: 1.', $ilkokul->text());
        self::assertCount(0, $ilkokul->filter('.hp-chip'));

        foreach (['ortaokul', 'lise', 'universite-hazirlik'] as $id) {
            $card = $crawler->filter('#'.$id);
            self::assertCount(0, $card->filter('a, button'), $id);
            self::assertStringContainsString('İçerikler hazırlanıyor', $card->filter('.hp-chip[role="status"]')->text(), $id);
        }
        self::assertSame(['/dersler/1'], $crawler->filter('#seviyeler a')->each(static fn (Crawler $node): string => (string) $node->attr('href')));
        self::assertStringNotContainsString('Hlc Ortaokul Taslak', (string) $client->getResponse()->getContent());

        // Real visitor path: card target -> Matematik -> unit.
        $client->request('GET', '/dersler/1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main, .catalog-wrap', 'Hlc Matematik');
        $client->request('GET', '/dersler/1/hlc-mat');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/dersler/1/hlc-mat/hlc-unite');
        self::assertResponseIsSuccessful();
    }

    public function testSecondLevelFixtureGetsItsOwnTargetAndSeveralGradesUseTheStageFilter(): void
    {
        $client = static::createClient();
        $writer = $this->writer($client);

        $this->publishSubjectWithUnit($writer, GradeLevel::Grade1, 'Hlc Bir', 'hlc-bir', 'hlc-bir-u');
        $this->publishSubjectWithUnit($writer, GradeLevel::Grade3, 'Hlc Uc', 'hlc-uc', 'hlc-uc-u');
        $this->publishSubjectWithUnit($writer, GradeLevel::Grade6, 'Hlc Alti', 'hlc-alti', 'hlc-alti-u');
        // Published subject without a published unit: listed on /dersler, but not a usable level grade.
        $unitless = $writer->createSubject(GradeLevel::Grade2, 'Hlc Unitesiz Iki', null, 1, 'hlc-unitesiz-iki');
        $writer->publishSubject($unitless->getId());

        $crawler = $client->request('GET', '/');
        self::assertSame('/dersler?kademe=ilkokul', $crawler->filter('#ilkokul a.hp-vcard__action')->attr('href'));
        self::assertStringContainsString('Şu an yayımlanmış sınıflar: 1, 3.', $crawler->filter('#ilkokul')->text());
        self::assertSame('/dersler/6', $crawler->filter('#ortaokul a.hp-vcard__action')->attr('href'));
        self::assertCount(0, $crawler->filter('#lise a'));
        self::assertCount(0, $crawler->filter('#universite-hazirlik a'));

        $crawler = $client->request('GET', '/dersler?kademe=ilkokul');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'İlkokul dersleri');
        self::assertSame(['/dersler/1', '/dersler/3'], $crawler->filter('.catalog-grid a')->each(static fn (Crawler $node): string => (string) $node->attr('href')));
        self::assertSame('Tüm sınıfları gör', trim($crawler->filter('.catalog-wrap > p > a')->text()));
        self::assertStringNotContainsString('6. sınıf', $crawler->filter('.catalog-grid')->text());
        self::assertStringNotContainsString('2. sınıf', $crawler->filter('.catalog-grid')->text());
        self::assertMatchesRegularExpression('#^https?://[^/?]+/dersler$#', (string) $crawler->filter('link[rel="canonical"]')->attr('href'));

        $crawler = $client->request('GET', '/dersler?kademe=ortaokul');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ortaokul dersleri');
        self::assertStringContainsString('6. sınıf', $crawler->filter('.catalog-grid')->text());
        self::assertStringNotContainsString('1. sınıf', $crawler->filter('.catalog-grid')->text());

        // A known level without published content is a controlled preparation page, never the full catalog.
        $crawler = $client->request('GET', '/dersler?kademe=lise');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Lise dersleri');
        self::assertSelectorTextContains('.catalog-empty', 'Lise için içerikler hazırlanıyor');
        self::assertCount(0, $crawler->filter('.catalog-grid'));
    }

    public function testInvalidLevelFilterIsAControlled404AndExistingUrlsStayBackwardCompatible(): void
    {
        $client = static::createClient();
        $writer = $this->writer($client);
        $this->publishSubjectWithUnit($writer, GradeLevel::Grade1, 'Hlc Uyum', 'hlc-uyum', 'hlc-uyum-u');

        foreach (['/dersler?kademe=', '/dersler?kademe=universite-hazirlik', '/dersler?kademe=ILKOKUL', '/dersler?kademe=x', '/dersler?kademe[]=ilkokul'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }

        // Unknown unrelated parameters and no parameter keep the unfiltered catalog.
        foreach (['/dersler', '/dersler?foo=bar'] as $url) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Dersleri keşfet');
            self::assertSame(['/dersler/1'], $crawler->filter('.catalog-grid a')->each(static fn (Crawler $node): string => (string) $node->attr('href')));
        }
        foreach (['/dersler/1', '/dersler/1/hlc-uyum', '/dersler/1/hlc-uyum/hlc-uyum-u'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
        }
    }

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
}
