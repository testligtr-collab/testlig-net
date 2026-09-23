<?php

declare(strict_types=1);

namespace App\Tests\Service\CatalogPublish;

use App\Dto\CatalogSourceAttribution;
use App\Enum\CatalogPublicationStatus;
use App\Enum\GradeLevel;
use App\Exception\CatalogException;
use App\Repository\CatalogSubjectRepository;
use App\Repository\CatalogTopicRepository;
use App\Repository\CatalogUnitRepository;
use App\Service\CatalogImport\CatalogImportService;
use App\Service\CatalogPublish\CatalogPublishTreeService;
use App\Service\CatalogWriteService;
use App\Service\StudentCatalogQuery;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;

final class CatalogPublishTreeServiceTest extends KernelTestCase
{
    private CatalogPublishTreeService $publisher;
    private CatalogImportService $import;
    private CatalogSubjectRepository $subjects;
    private CatalogUnitRepository $units;
    private CatalogTopicRepository $topics;
    private StudentCatalogQuery $query;
    private CatalogWriteService $writer;
    private EntityManagerInterface $em;
    private string $fixturePath;

    protected function setUp(): void
    {
        self::bootKernel();
        $publisher = static::getContainer()->get(CatalogPublishTreeService::class);
        $import = static::getContainer()->get(CatalogImportService::class);
        $subjects = static::getContainer()->get(CatalogSubjectRepository::class);
        $units = static::getContainer()->get(CatalogUnitRepository::class);
        $topics = static::getContainer()->get(CatalogTopicRepository::class);
        $query = static::getContainer()->get(StudentCatalogQuery::class);
        $writer = static::getContainer()->get(CatalogWriteService::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(CatalogPublishTreeService::class, $publisher);
        self::assertInstanceOf(CatalogImportService::class, $import);
        $this->publisher = $publisher;
        $this->import = $import;
        $this->subjects = $subjects;
        $this->units = $units;
        $this->topics = $topics;
        $this->query = $query;
        $this->writer = $writer;
        $this->em = $em;
        $this->fixturePath = \dirname(__DIR__, 3).\DIRECTORY_SEPARATOR.'data'.\DIRECTORY_SEPARATOR.'catalog'.\DIRECTORY_SEPARATOR.'meb'.\DIRECTORY_SEPARATOR.'tymm-2026'.\DIRECTORY_SEPARATOR.'grade-1-matematik.yaml';
    }

    public function testDryRunDoesNotWrite(): void
    {
        $this->import->import($this->fixturePath, apply: true, updateExisting: false);
        $result = $this->publisher->publish('TYMM-2026', 'MAT', 1, 7, 19, apply: false);
        self::assertTrue($result->dryRun);
        self::assertFalse($result->applied);
        self::assertSame(1, $result->subjectsToPublish);
        self::assertSame(7, $result->unitsToPublish);
        self::assertSame(19, $result->topicsToPublish);

        $subject = $this->subjects->findOneBySourceIdentity('TYMM-2026', 'MAT', 1);
        self::assertNotNull($subject);
        self::assertSame(CatalogPublicationStatus::Draft, $subject->getStatus());
        self::assertSame([], $this->query->listPublishedSubjectsForGrade(GradeLevel::Grade1));
    }

    public function testWrongExpectedCountRejected(): void
    {
        $this->import->import($this->fixturePath, apply: true, updateExisting: false);
        $this->expectException(CatalogException::class);
        $this->publisher->publish('TYMM-2026', 'MAT', 1, 7, 18, apply: false);
    }

    public function testApplyPublishesOnlyTargetTreeAndStudentSeesIt(): void
    {
        $this->import->import($this->fixturePath, apply: true, updateExisting: false);
        $other = $this->writer->createSubject(
            GradeLevel::Grade1,
            'Yan Ders',
            null,
            9,
            null,
            new CatalogSourceAttribution('OTHER', 'OTHER-2026', 'https://example.com/other', 1),
        );
        self::assertSame(CatalogPublicationStatus::Draft, $other->getStatus());

        $result = $this->publisher->publish('TYMM-2026', 'MAT', 1, 7, 19, apply: true);
        self::assertTrue($result->applied);
        self::assertSame(27, $result->published);

        $subject = $this->subjects->findOneBySourceIdentity('TYMM-2026', 'MAT', 1);
        self::assertNotNull($subject);
        self::assertSame(CatalogPublicationStatus::Published, $subject->getStatus());
        foreach ($this->units->findBySubjectOrdered($subject) as $unit) {
            self::assertSame(CatalogPublicationStatus::Published, $unit->getStatus());
            foreach ($this->topics->findByUnitOrdered($unit) as $topic) {
                self::assertSame(CatalogPublicationStatus::Published, $topic->getStatus());
            }
        }

        $this->em->clear();
        $other = $this->subjects->findOneBySourceIdentity('OTHER-2026', 'OTHER', 1);
        self::assertNotNull($other);
        self::assertSame(CatalogPublicationStatus::Draft, $other->getStatus());

        $listed = $this->query->listPublishedSubjectsForGrade(GradeLevel::Grade1);
        self::assertCount(1, $listed);
        self::assertSame('matematik', $listed[0]->slug);
        $units = $this->query->listPublishedUnitsForGradeSubject(GradeLevel::Grade1, 'matematik');
        self::assertNotNull($units);
        self::assertCount(7, $units);
        $first = $units[0];
        $detail = $this->query->getPublishedUnitDetail(GradeLevel::Grade1, 'matematik', $first->slug);
        self::assertNotNull($detail);
        self::assertNotEmpty($detail['topics']);
        $topicTotal = 0;
        foreach ($units as $unitItem) {
            $d = $this->query->getPublishedUnitDetail(GradeLevel::Grade1, 'matematik', $unitItem->slug);
            self::assertNotNull($d);
            $topicTotal += \count($d['topics']);
        }
        self::assertSame(19, $topicTotal);
    }

    public function testSecondApplyIsSafeNoop(): void
    {
        $this->import->import($this->fixturePath, apply: true, updateExisting: false);
        $this->publisher->publish('TYMM-2026', 'MAT', 1, 7, 19, apply: true);
        $again = $this->publisher->publish('TYMM-2026', 'MAT', 1, 7, 19, apply: true);
        self::assertTrue($again->noop);
        self::assertFalse($again->applied);
        self::assertSame(0, $again->published);
        self::assertSame(27, $again->skipped);
    }

    public function testArchivedUnitRejected(): void
    {
        $this->import->import($this->fixturePath, apply: true, updateExisting: false);
        $subject = $this->subjects->findOneBySourceIdentity('TYMM-2026', 'MAT', 1);
        self::assertNotNull($subject);
        $unit = $this->units->findBySubjectOrdered($subject)[0];
        $this->writer->archiveUnit($unit->getId());
        $this->expectException(CatalogException::class);
        $this->publisher->publish('TYMM-2026', 'MAT', 1, 7, 19, apply: true);
    }

    public function testMidTransactionFailureRollsBack(): void
    {
        $this->import->import($this->fixturePath, apply: true, updateExisting: false);

        $clock = static::getContainer()->get(ClockInterface::class);
        $locks = static::getContainer()->get(LockFactory::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        self::assertInstanceOf(LockFactory::class, $locks);
        $failing = new CatalogPublishTreeService(
            $this->em,
            $this->subjects,
            $this->units,
            $this->topics,
            $clock,
            $locks,
            static function (): void {
                throw CatalogException::invalidInput('induced failure');
            },
        );

        try {
            $failing->publish('TYMM-2026', 'MAT', 1, 7, 19, apply: true);
            self::fail('Expected CatalogException');
        } catch (CatalogException $e) {
            self::assertSame('induced failure', $e->getMessage());
        }

        $this->em->clear();
        $subject = $this->subjects->findOneBySourceIdentity('TYMM-2026', 'MAT', 1);
        self::assertNotNull($subject);
        self::assertSame(CatalogPublicationStatus::Draft, $subject->getStatus());
        foreach ($this->units->findBySubjectOrdered($subject) as $unit) {
            self::assertSame(CatalogPublicationStatus::Draft, $unit->getStatus());
            foreach ($this->topics->findByUnitOrdered($unit) as $topic) {
                self::assertSame(CatalogPublicationStatus::Draft, $topic->getStatus());
            }
        }
        self::assertSame([], $this->query->listPublishedSubjectsForGrade(GradeLevel::Grade1));
    }

    public function testStudentCannotSeeDraftTree(): void
    {
        $this->import->import($this->fixturePath, apply: true, updateExisting: false);
        self::assertSame([], $this->query->listPublishedSubjectsForGrade(GradeLevel::Grade1));
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
