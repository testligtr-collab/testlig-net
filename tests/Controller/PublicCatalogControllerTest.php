<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\CatalogSourceAttribution;
use App\Enum\GradeLevel;
use App\Service\CatalogWriteService;
use App\Service\PublicCatalogQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicCatalogControllerTest extends WebTestCase
{
    public function testPublishedTreeIsPublicAndClosedRecordsStayOpaque(): void
    {
        $client = static::createClient();
        $writer = $client->getContainer()->get(CatalogWriteService::class);
        $catalog = $client->getContainer()->get(PublicCatalogQuery::class);
        self::assertInstanceOf(CatalogWriteService::class, $writer);
        self::assertInstanceOf(PublicCatalogQuery::class, $catalog);

        $subject = $writer->createSubject(GradeLevel::Grade1, 'Pcv Matematik', 'Kisa ders aciklamasi', 1, 'pcv-mat');
        $writer->publishSubject($subject->getId());
        $topicsMade = 0;
        for ($unitNumber = 1; $unitNumber <= 7; ++$unitNumber) {
            $source = 1 === $unitNumber
                ? new CatalogSourceAttribution('PCVU1', 'v1', 'https://ttkb.meb.gov.tr/pcv', 1)
                : null;
            $unit = $writer->createUnit($subject->getId(), 'Unite '.$unitNumber, null, $unitNumber, 'pcv-unite-'.$unitNumber, $source);
            $writer->publishUnit($unit->getId());
            $perUnit = $unitNumber <= 5 ? 3 : 2;
            for ($topicNumber = 1; $topicNumber <= $perUnit; ++$topicNumber) {
                $topic = $writer->createTopic($unit->getId(), 'Konu '.$unitNumber.'-'.$topicNumber, 'Kisa konu ozeti', $topicNumber, null, 'pcv-konu-'.$unitNumber.'-'.$topicNumber);
                $writer->publishTopic($topic->getId());
                ++$topicsMade;
            }
        }
        self::assertSame(19, $topicsMade);
        $draftUnit = $writer->createUnit($subject->getId(), 'Taslak Gizli Unite', null, 20, 'taslak-unite');
        $writer->createTopic($draftUnit->getId(), 'Taslak Gizli Konu', null, 1, null, 'taslak-konu');
        $writer->createSubject(GradeLevel::Grade1, 'Taslak Gizli Ders', null, 2, 'taslak-gizli');
        $archived = $writer->createSubject(GradeLevel::Grade8, 'Arsiv Gizli Ders', null, 1, 'arsiv-ders');
        $writer->publishSubject($archived->getId());
        $archivedUnit = $writer->createUnit($archived->getId(), 'Arsiv Gizli Unite', null, 1, 'arsiv-unite');
        $writer->publishUnit($archivedUnit->getId());
        $writer->archiveSubject($archived->getId());

        $catalog->grade(GradeLevel::Grade1);
        self::assertSame(3, $catalog->statements());
        $catalog->subject(GradeLevel::Grade1, 'pcv-mat');
        self::assertSame(3, $catalog->statements());
        $catalog->unit(GradeLevel::Grade1, 'pcv-mat', 'pcv-unite-1');
        self::assertSame(2, $catalog->statements());
        $catalog->sitemapEntries();
        self::assertSame(2, $catalog->statements());
        $catalog->grades();
        self::assertSame(1, $catalog->statements());

        $client->request('GET', '/dersler');
        self::assertResponseIsSuccessful();
        self::assertNull($client->getResponse()->headers->get('set-cookie'));
        self::assertNull($client->getResponse()->headers->get('x-robots-tag'));
        self::assertStringContainsString("default-src 'self'", (string) $client->getResponse()->headers->get('content-security-policy'));
        $home = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Dersleri keşfet', $home);
        self::assertStringContainsString('1. sınıf', $home);
        self::assertStringNotContainsString('8. sınıf', $home);
        self::assertStringNotContainsString('Taslak Gizli', $home);
        self::assertStringNotContainsString($subject->getId()->toRfc4122(), $home);
        self::assertSame(1, substr_count($home, '<h1'));

        $client->request('GET', '/dersler/1?utm=kampanya');
        self::assertResponseIsSuccessful();
        self::assertNull($client->getResponse()->headers->get('x-robots-tag'));
        $gradeHtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Pcv Matematik', $gradeHtml);
        self::assertStringContainsString('Kisa ders aciklamasi', $gradeHtml);
        self::assertStringContainsString('7 ünite', $gradeHtml);
        self::assertStringContainsString('19 konu', $gradeHtml);
        self::assertStringContainsString('rel="canonical"', $gradeHtml);
        self::assertStringNotContainsString('utm=kampanya', $gradeHtml);
        self::assertStringNotContainsString('Taslak Gizli', $gradeHtml);
        self::assertStringNotContainsString('storageKey', $gradeHtml);
        self::assertStringNotContainsString('correctStableKey', $gradeHtml);

        $client->request('GET', '/dersler/1/pcv-mat');
        self::assertResponseIsSuccessful();
        $subjectHtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('https://ttkb.meb.gov.tr/pcv', $subjectHtml);
        self::assertStringContainsString('rel="noopener noreferrer"', $subjectHtml);
        self::assertStringContainsString('pcv-unite-1', $subjectHtml);
        self::assertStringNotContainsString('Taslak Gizli Unite', $subjectHtml);
        self::assertStringNotContainsString('Toplama anlatimi', $subjectHtml);

        $client->request('GET', '/dersler/1/pcv-mat/pcv-unite-1');
        self::assertResponseIsSuccessful();
        $unitHtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Konu 1-1', $unitHtml);
        self::assertStringContainsString('Kisa konu ozeti', $unitHtml);
        self::assertStringContainsString('Ücretsiz hesap oluştur', $unitHtml);
        self::assertStringContainsString('Öğrenciysen ders içeriğine giriş yaparak ulaşabilirsin.', $unitHtml);
        self::assertStringNotContainsString('/ogrenci/dersler', $unitHtml);
        self::assertStringNotContainsString('Taslak Gizli Konu', $unitHtml);
        self::assertStringNotContainsString('youtube', $unitHtml);
        self::assertStringNotContainsString('.pdf', $unitHtml);

        foreach ([
            '/dersler/1/taslak-gizli',
            '/dersler/1/pcv-mat/taslak-unite',
            '/dersler/8/arsiv-ders',
            '/dersler/8/arsiv-ders/arsiv-unite',
            '/dersler/2',
        ] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
            self::assertStringContainsString('noindex', (string) $client->getResponse()->headers->get('x-robots-tag'));
            $body = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('Taslak Gizli', $body);
            self::assertStringNotContainsString('Arsiv Gizli', $body);
            self::assertStringNotContainsString('yayımlanmamış', $body);
        }

        $client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/xml', (string) $client->getResponse()->headers->get('content-type'));
        $xml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('/dersler/1/pcv-mat', $xml);
        self::assertStringContainsString('/dersler/1/pcv-mat/pcv-unite-1', $xml);
        self::assertStringContainsString('/gizlilik', $xml);
        self::assertStringNotContainsString('taslak-gizli', $xml);
        self::assertStringNotContainsString('taslak-unite', $xml);
        self::assertStringNotContainsString('arsiv-ders', $xml);
        self::assertStringNotContainsString('/giris', $xml);
        self::assertStringNotContainsString('/ogrenci', $xml);
        self::assertStringNotContainsString('/yonetim', $xml);

        $client->request('GET', '/robots.txt');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sitemap:', (string) $client->getResponse()->getContent());

        $client->request('GET', '/giris');
        self::assertStringContainsString('noindex', (string) $client->getResponse()->headers->get('x-robots-tag'));
    }

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $conn = $em->getConnection();
            $schema = $conn->createSchemaManager();
            foreach (['catalog_topic_lessons', 'catalog_topics', 'catalog_units', 'catalog_subjects'] as $table) {
                if ($schema->tablesExist([$table])) {
                    $conn->executeStatement('DELETE FROM '.$table);
                }
            }
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }
}
