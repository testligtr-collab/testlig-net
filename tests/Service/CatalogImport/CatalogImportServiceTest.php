<?php

declare(strict_types=1);

namespace App\Tests\Service\CatalogImport;

use App\Enum\CatalogPublicationStatus;
use App\Enum\GradeLevel;
use App\Exception\CatalogException;
use App\Repository\CatalogSubjectRepository;
use App\Repository\CatalogTopicRepository;
use App\Repository\CatalogUnitRepository;
use App\Service\CatalogImport\CatalogImportService;
use App\Service\CatalogImport\CatalogImportYamlLoader;
use App\Service\CatalogWriteService;
use App\Service\StudentCatalogQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogImportServiceTest extends KernelTestCase
{
    private CatalogImportService $import;
    private CatalogImportYamlLoader $loader;
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
        $import = static::getContainer()->get(CatalogImportService::class);
        $loader = static::getContainer()->get(CatalogImportYamlLoader::class);
        $subjects = static::getContainer()->get(CatalogSubjectRepository::class);
        $units = static::getContainer()->get(CatalogUnitRepository::class);
        $topics = static::getContainer()->get(CatalogTopicRepository::class);
        $query = static::getContainer()->get(StudentCatalogQuery::class);
        $writer = static::getContainer()->get(CatalogWriteService::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(CatalogImportService::class, $import);
        self::assertInstanceOf(CatalogImportYamlLoader::class, $loader);
        self::assertInstanceOf(CatalogSubjectRepository::class, $subjects);
        self::assertInstanceOf(CatalogUnitRepository::class, $units);
        self::assertInstanceOf(CatalogTopicRepository::class, $topics);
        self::assertInstanceOf(StudentCatalogQuery::class, $query);
        self::assertInstanceOf(CatalogWriteService::class, $writer);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->import = $import;
        $this->loader = $loader;
        $this->subjects = $subjects;
        $this->units = $units;
        $this->topics = $topics;
        $this->query = $query;
        $this->writer = $writer;
        $this->em = $em;
        $this->fixturePath = \dirname(__DIR__, 3).\DIRECTORY_SEPARATOR.'data'.\DIRECTORY_SEPARATOR.'catalog'.\DIRECTORY_SEPARATOR.'meb'.\DIRECTORY_SEPARATOR.'tymm-2026'.\DIRECTORY_SEPARATOR.'grade-1-matematik.yaml';
        self::assertFileExists($this->fixturePath);
    }

    public function testFixtureLoadsExpectedTree(): void
    {
        $doc = $this->loader->loadFile($this->fixturePath);
        self::assertSame('Matematik', $doc->subjectName);
        self::assertSame(1, $doc->gradeLevel->value);
        self::assertCount(7, $doc->units);
        self::assertSame(19, $doc->topicCount());
        self::assertSame('MAT.1.3', $doc->units[0]->sourceCode);
        self::assertSame(1, $doc->units[0]->occurrence);
        self::assertSame('MAT.1.1', $doc->units[1]->sourceCode);
        self::assertSame(1, $doc->units[1]->occurrence);
        self::assertSame(2, $doc->units[2]->occurrence);
        self::assertSame(3, $doc->units[4]->occurrence);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $before = \count($this->subjects->findAll());
        $result = $this->import->import($this->fixturePath, apply: false, updateExisting: false);
        self::assertFalse($result->applied);
        self::assertTrue($result->dryRun);
        self::assertSame(1 + 7 + 19, $result->created);
        self::assertCount($before, $this->subjects->findAll());
    }

    public function testApplyCreatesDraftOnlyAndStudentSeesNothing(): void
    {
        $result = $this->import->import($this->fixturePath, apply: true, updateExisting: false);
        self::assertTrue($result->applied);
        self::assertSame(27, $result->created);
        self::assertSame(0, $result->updated);

        $subject = $this->subjects->findOneBySourceIdentity('TYMM-2026', 'MAT', 1);
        self::assertNotNull($subject);
        self::assertSame(CatalogPublicationStatus::Draft, $subject->getStatus());
        self::assertSame('TYMM-2026', $subject->getSourceVersion());
        self::assertNotNull($subject->getSourceUrl());

        $units = $this->units->findBySubjectOrdered($subject);
        self::assertCount(7, $units);
        foreach ($units as $unit) {
            self::assertSame(CatalogPublicationStatus::Draft, $unit->getStatus());
            self::assertNotNull($unit->getSourceCode());
            $topics = $this->topics->findByUnitOrdered($unit);
            self::assertNotEmpty($topics);
            foreach ($topics as $topic) {
                self::assertSame(CatalogPublicationStatus::Draft, $topic->getStatus());
                self::assertNull($topic->getEstimatedMinutes());
                self::assertNotNull($topic->getSourceCode());
            }
        }

        self::assertSame([], $this->query->listPublishedSubjectsForGrade(GradeLevel::Grade1));
    }

    public function testSecondApplyIsIdempotent(): void
    {
        $this->import->import($this->fixturePath, apply: true, updateExisting: false);
        $result = $this->import->import($this->fixturePath, apply: true, updateExisting: false);
        self::assertSame(0, $result->created);
        self::assertSame(27, $result->skipped);
        self::assertCount(1, $this->subjects->findAll());
        $subject = $this->subjects->findOneBySourceIdentity('TYMM-2026', 'MAT', 1);
        self::assertNotNull($subject);
        self::assertCount(7, $this->units->findBySubjectOrdered($subject));
    }

    public function testUpdateExistingOnlyWithFlag(): void
    {
        $this->import->import($this->fixturePath, apply: true, updateExisting: false);
        $subject = $this->subjects->findOneBySourceIdentity('TYMM-2026', 'MAT', 1);
        self::assertNotNull($subject);
        $subject->updateDetails('Matematik X', $subject->getSlug(), null, $subject->getPosition(), new \DateTimeImmutable());
        $this->em->flush();
        $this->em->clear();

        $skip = $this->import->import($this->fixturePath, apply: true, updateExisting: false);
        self::assertSame(0, $skip->updated);
        $subject = $this->subjects->findOneBySourceIdentity('TYMM-2026', 'MAT', 1);
        self::assertNotNull($subject);
        self::assertSame('Matematik X', $subject->getName());

        $upd = $this->import->import($this->fixturePath, apply: true, updateExisting: true);
        self::assertGreaterThanOrEqual(1, $upd->updated);
        $subject = $this->subjects->findOneBySourceIdentity('TYMM-2026', 'MAT', 1);
        self::assertNotNull($subject);
        self::assertSame('Matematik', $subject->getName());
    }

    public function testMalformedYamlRejected(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'catyml');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, "schema_version: 1\nextra: true\nsource: {}\nsubject: {}\n");
        try {
            $this->expectException(CatalogException::class);
            $this->loader->loadFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testDuplicateUnitIdentityRejected(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'catyml');
        self::assertNotFalse($tmp);
        $yaml = <<<'YAML'
schema_version: 1
source:
  program_id: "2339"
  version: "TYMM-2026"
  program_url: "https://mufredat.meb.gov.tr/ProgramDetay.aspx?PID=2339"
  pdf_url: "https://mufredat.meb.gov.tr/Dosyalar/20268149102521-Matematik%20(1-4)%20DÖP.pdf"
subject:
  name: Matematik
  grade_level: 1
  position: 0
  source_code: MAT
  units:
    - name: "Sayılar ve Nicelikler (1)"
      source_code: "MAT.1.1"
      occurrence: 1
      position: 1
      topics:
        - name: "Rakamlar ve Sayılar"
          position: 1
    - name: "Sayılar ve Nicelikler (2)"
      source_code: "MAT.1.1"
      occurrence: 1
      position: 2
      topics:
        - name: "Uzunluk ve Kütle Ölçme"
          position: 1
YAML;
        file_put_contents($tmp, $yaml);
        try {
            $this->expectException(CatalogException::class);
            $this->loader->loadFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testConflictRollsBackTransaction(): void
    {
        $this->writer->createSubject(GradeLevel::Grade1, 'Matematik', null, 0);
        $this->em->clear();
        try {
            $this->import->import($this->fixturePath, apply: true, updateExisting: false);
            self::fail('Expected conflict');
        } catch (CatalogException) {
        }
        $this->em->clear();
        self::assertNull($this->subjects->findOneBySourceIdentity('TYMM-2026', 'MAT', 1));
        self::assertCount(1, $this->subjects->findAll());
    }

    public function testManualAdminCreateStillWorksWithoutSource(): void
    {
        $subject = $this->writer->createSubject(GradeLevel::Grade2, 'Fen Bilimleri', null, 1);
        self::assertNull($subject->getSourceCode());
        self::assertNull($subject->getSourceVersion());
        self::assertSame(1, $subject->getSourceOccurrence());
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
