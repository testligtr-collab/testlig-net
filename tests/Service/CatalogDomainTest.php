<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\GradeLevel;
use App\Exception\CatalogException;
use App\Service\CatalogWriteService;
use App\Service\StudentCatalogQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogDomainTest extends KernelTestCase
{
    private CatalogWriteService $writer;
    private StudentCatalogQuery $query;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $writer = static::getContainer()->get(CatalogWriteService::class);
        $query = static::getContainer()->get(StudentCatalogQuery::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(CatalogWriteService::class, $writer);
        self::assertInstanceOf(StudentCatalogQuery::class, $query);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->writer = $writer;
        $this->query = $query;
        $this->em = $em;
    }

    public function testPublishLifecycleAndVisibility(): void
    {
        $subject = $this->writer->createSubject(GradeLevel::Grade5, 'Matematik', null, 1);
        self::assertNull($subject->getPublishedAt());
        self::assertSame([], $this->query->listPublishedSubjectsForGrade(GradeLevel::Grade5));

        $this->writer->publishSubject($subject->getId());
        $this->em->clear();
        $listed = $this->query->listPublishedSubjectsForGrade(GradeLevel::Grade5);
        self::assertCount(1, $listed);
        self::assertSame('matematik', $listed[0]->slug);

        $unit = $this->writer->createUnit($subject->getId(), 'Sayılar', null, 0);
        $this->writer->publishUnit($unit->getId());
        $topic = $this->writer->createTopic($unit->getId(), 'Doğal sayılar', null, 0, 30);

        $detailBeforeTopicPublish = $this->query->getPublishedUnitDetail(GradeLevel::Grade5, 'matematik', 'sayilar');
        self::assertNotNull($detailBeforeTopicPublish);
        self::assertSame([], $detailBeforeTopicPublish['topics']);

        $this->writer->publishTopic($topic->getId());
        $detail = $this->query->getPublishedUnitDetail(GradeLevel::Grade5, 'matematik', 'sayilar');
        self::assertNotNull($detail);
        self::assertCount(1, $detail['topics']);

        $this->writer->archiveSubject($subject->getId());
        self::assertSame([], $this->query->listPublishedSubjectsForGrade(GradeLevel::Grade5));
        self::assertNull($this->query->getPublishedUnitDetail(GradeLevel::Grade5, 'matematik', 'sayilar'));
    }

    public function testDuplicateSlugRejectedPerParent(): void
    {
        $a = $this->writer->createSubject(GradeLevel::Grade6, 'Fen', null, 1);
        $this->expectException(CatalogException::class);
        $this->writer->createSubject(GradeLevel::Grade6, 'Fen', null, 2);
        unset($a);
    }

    public function testSameSlugAllowedAcrossGrades(): void
    {
        $this->writer->createSubject(GradeLevel::Grade1, 'Türkçe', null, 1);
        $other = $this->writer->createSubject(GradeLevel::Grade2, 'Türkçe', null, 1);
        self::assertSame('turkce', $other->getSlug());
    }

    public function testInvalidNameRejected(): void
    {
        $this->expectException(CatalogException::class);
        $this->writer->createSubject(GradeLevel::Grade3, 'A', null, 0);
    }

    public function testDraftUnitHiddenWhenSubjectPublished(): void
    {
        $subject = $this->writer->createSubject(GradeLevel::Grade4, 'Hayat Bilgisi', null, 1);
        $this->writer->publishSubject($subject->getId());
        $this->writer->createUnit($subject->getId(), 'Okulumuz', null, 0);
        $units = $this->query->listPublishedUnitsForGradeSubject(GradeLevel::Grade4, 'hayat-bilgisi');
        self::assertNotNull($units);
        self::assertSame([], $units);
    }

    public function testArchivedCannotRepublish(): void
    {
        $subject = $this->writer->createSubject(GradeLevel::Grade7, 'Tarih', null, 1);
        $this->writer->publishSubject($subject->getId());
        $this->writer->archiveSubject($subject->getId());
        $this->expectException(CatalogException::class);
        $this->writer->publishSubject($subject->getId());
    }

    protected function tearDown(): void
    {
        try {
            $conn = $this->em->getConnection();
            $sm = $conn->createSchemaManager();
            if ($sm->tablesExist(['catalog_topics'])) {
                $conn->executeStatement('DELETE FROM catalog_topics');
            }
            if ($sm->tablesExist(['catalog_units'])) {
                $conn->executeStatement('DELETE FROM catalog_units');
            }
            if ($sm->tablesExist(['catalog_subjects'])) {
                $conn->executeStatement('DELETE FROM catalog_subjects');
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
