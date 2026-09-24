<?php

declare(strict_types=1);

namespace App\Tests\Service\CurriculumImport;

use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumContentStatus;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\CurriculumImportException;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\CurriculumProgramRepository;
use App\Repository\SubjectRepository;
use App\Repository\UserRepository;
use App\Service\CurriculumImport\CurriculumPilotImportService;
use App\Service\CurriculumImport\CurriculumPilotImportYamlLoader;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CurriculumPilotImportServiceTest extends KernelTestCase
{
    private CurriculumPilotImportService $import;
    private CurriculumPilotImportYamlLoader $loader;
    private SubjectRepository $subjects;
    private CurriculumProgramRepository $programs;
    private CurriculumLearningOutcomeRepository $outcomes;
    private SubjectManager $subjectManager;
    private UserFactory $userFactory;
    private UserRepository $users;
    private string $fixturePath;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $import = $c->get(CurriculumPilotImportService::class);
        $loader = $c->get(CurriculumPilotImportYamlLoader::class);
        $subjects = $c->get(SubjectRepository::class);
        $programs = $c->get(CurriculumProgramRepository::class);
        $outcomes = $c->get(CurriculumLearningOutcomeRepository::class);
        $subjectManager = $c->get(SubjectManager::class);
        $userFactory = $c->get(UserFactory::class);
        $users = $c->get(UserRepository::class);
        self::assertInstanceOf(CurriculumPilotImportService::class, $import);
        self::assertInstanceOf(CurriculumPilotImportYamlLoader::class, $loader);
        self::assertInstanceOf(SubjectRepository::class, $subjects);
        self::assertInstanceOf(CurriculumProgramRepository::class, $programs);
        self::assertInstanceOf(CurriculumLearningOutcomeRepository::class, $outcomes);
        self::assertInstanceOf(SubjectManager::class, $subjectManager);
        self::assertInstanceOf(UserFactory::class, $userFactory);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->import = $import;
        $this->loader = $loader;
        $this->subjects = $subjects;
        $this->programs = $programs;
        $this->outcomes = $outcomes;
        $this->subjectManager = $subjectManager;
        $this->userFactory = $userFactory;
        $this->users = $users;
        $this->fixturePath = \dirname(__DIR__, 3)
            .\DIRECTORY_SEPARATOR.'data'
            .\DIRECTORY_SEPARATOR.'curriculum'
            .\DIRECTORY_SEPARATOR.'meb'
            .\DIRECTORY_SEPARATOR.'tymm-2026'
            .\DIRECTORY_SEPARATOR.'grade-1-matematik-uzamsal-iliskiler.yaml';
        self::assertFileExists($this->fixturePath);
    }

    public function testFixtureLoadsOfficialOutcome(): void
    {
        $doc = $this->loader->loadFile($this->fixturePath);
        self::assertSame('matematik', $doc->subjectCode);
        self::assertSame('MAT.1.3.1', $doc->outcomeOfficialCode);
        self::assertSame('mat_1_3_1', $doc->outcomeCode);
        self::assertSame('Hedefe ulaşmak için mesafeleri ve yönleri içeren yönergeleri çözümleyebilme', $doc->outcomeDescription);
        self::assertSame('TYMM-2026', $doc->programVersion);
        self::assertSame(1, $doc->gradeLevel->value);
        self::assertTrue($doc->publish);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $sa = $this->ensureSuperAdmin('cpi-dry-sa@example.com');
        $this->ensureMathSubject($sa);
        $beforePrograms = \count($this->programs->findAll());
        $beforeOutcomes = \count($this->outcomes->findAll());

        $result = $this->import->import($this->fixturePath, apply: false);
        self::assertTrue($result->dryRun);
        self::assertFalse($result->applied);
        self::assertSame(0, $result->errors);
        self::assertGreaterThanOrEqual(1, $result->created + $result->skipped);
        self::assertCount($beforePrograms, $this->programs->findAll());
        self::assertCount($beforeOutcomes, $this->outcomes->findAll());
    }

    public function testApplyCreatesPublishedChainAndSecondApplySkips(): void
    {
        $sa = $this->ensureSuperAdmin('cpi-apply-sa@example.com');
        $subject = $this->ensureMathSubject($sa);

        $first = $this->import->import($this->fixturePath, apply: true);
        self::assertTrue($first->applied);
        self::assertSame(4, $first->created);
        self::assertTrue($first->published);

        $program = $this->programs->findOneByIdentity(
            $subject,
            GradeLevel::Grade1,
            'mat_grade1_tymm',
            'TYMM-2026',
        );
        self::assertNotNull($program);
        self::assertSame(CurriculumStatus::Published, $program->getStatus());

        $outcomes = $this->outcomes->findActiveOrderedForSubject($subject);
        self::assertNotEmpty($outcomes);
        $matched = false;
        foreach ($outcomes as $outcome) {
            if ('mat_1_3_1' === $outcome->getCode()) {
                $matched = true;
                self::assertSame(CurriculumContentStatus::Active, $outcome->getStatus());
                self::assertStringContainsString('mesafeleri ve yönleri', $outcome->getDescription());
            }
        }
        self::assertTrue($matched);

        $second = $this->import->import($this->fixturePath, apply: true);
        self::assertTrue($second->applied);
        self::assertSame(0, $second->created);
        self::assertGreaterThanOrEqual(4, $second->skipped);
        self::assertSame(0, $second->conflicts);
        self::assertSame(0, $second->errors);
    }

    public function testMissingSubjectErrors(): void
    {
        $this->ensureSuperAdmin('cpi-miss-sa@example.com');
        $tmp = tempnam(sys_get_temp_dir(), 'cpi');
        self::assertNotFalse($tmp);
        $yaml = file_get_contents($this->fixturePath);
        self::assertNotFalse($yaml);
        $yaml = str_replace('subject_code: matematik', 'subject_code: nonexistent_subj', $yaml);
        file_put_contents($tmp, $yaml);

        try {
            $this->expectException(CurriculumImportException::class);
            $this->import->import($tmp, apply: false);
        } finally {
            @unlink($tmp);
        }
    }

    public function testWrongSourceVersionRejected(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cpi');
        self::assertNotFalse($tmp);
        $yaml = <<<'YAML'
schema_version: 1
source:
  program_id: "2339"
  version: "TYMM-2018"
  program_url: "https://mufredat.meb.gov.tr/ProgramDetay.aspx?PID=2339"
subject_code: matematik
program:
  code: mat_grade1_tymm
  name: "İlkokul Matematik 1"
  version: "TYMM-2026"
  grade_level: 1
unit:
  code: mat_1_3_occ1
  title: "Nesnelerin Geometrisi (1)"
  official_theme_code: "MAT.1.3"
  position: 1
topic:
  code: uzamsal_iliskiler
  title: "Uzamsal İlişkiler"
  position: 1
outcome:
  code: mat_1_3_1
  official_code: "MAT.1.3.1"
  description: "Hedefe ulaşmak için mesafeleri ve yönleri içeren yönergeleri çözümleyebilme"
  position: 1
publish: true
YAML;
        file_put_contents($tmp, $yaml);

        try {
            $this->expectException(CurriculumImportException::class);
            $this->loader->loadFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function ensureSuperAdmin(string $email): User
    {
        $existing = $this->users->findOneActiveVerifiedSuperAdmin();
        if ($existing instanceof User) {
            return $existing;
        }

        $user = $this->userFactory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', UserRole::Student);
        $user->addGlobalRole(UserRole::SuperAdmin);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    private function ensureMathSubject(User $sa): Subject
    {
        $existing = $this->subjects->findOneByCode('matematik');
        if ($existing instanceof Subject) {
            return $existing;
        }

        return $this->subjectManager->create($sa, 'matematik', 'Matematik', 'cpi_create_math');
    }
}
