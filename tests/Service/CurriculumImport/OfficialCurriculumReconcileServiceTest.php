<?php

declare(strict_types=1);

namespace App\Tests\Service\CurriculumImport;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\CurriculumImportException;
use App\Exception\CurriculumUnitException;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\CurriculumProgramRepository;
use App\Repository\SubjectRepository;
use App\Repository\UserRepository;
use App\Service\CurriculumImport\OfficialCurriculumReconcileDocument;
use App\Service\CurriculumImport\OfficialCurriculumReconcileService;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;

final class OfficialCurriculumReconcileServiceTest extends KernelTestCase
{
    private OfficialCurriculumReconcileService $reconcile;
    private EntityManagerInterface $entityManager;
    private string $fixture;

    protected function setUp(): void
    {
        self::bootKernel();
        $reconcile = static::getContainer()->get(OfficialCurriculumReconcileService::class);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(OfficialCurriculumReconcileService::class, $reconcile);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->reconcile = $reconcile;
        $this->entityManager = $entityManager;
        $this->entityManager->getConnection()->beginTransaction();
        $this->fixture = \dirname(__DIR__, 3)
            .\DIRECTORY_SEPARATOR.'data'
            .\DIRECTORY_SEPARATOR.'curriculum'
            .\DIRECTORY_SEPARATOR.'meb'
            .\DIRECTORY_SEPARATOR.'tymm-2026'
            .\DIRECTORY_SEPARATOR.'grade-1-matematik.yaml';
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    public function testDryRunDoesNotWriteAndApplyPreservesThePilot(): void
    {
        [$actor, $subject] = $this->seedActorAndSubject();
        $before = $this->evidence();
        $pilot = $this->seedPublishedPilot($actor, $subject);
        $dry = $this->reconcile->reconcile($this->fixture, false);
        self::assertFalse($dry->applied);
        self::assertSame(1, $dry->programFound);
        self::assertSame(7, $dry->themesExpected);
        self::assertSame(1, $dry->themesFound);
        self::assertSame(6, $dry->themesCreate);
        self::assertSame(1, $dry->themesReorder);
        self::assertSame(19, $dry->outcomesExpected);
        self::assertSame(18, $dry->outcomesCreate);
        self::assertSame(1, $dry->outcomesSkip);
        self::assertSame(1, $dry->pilotPreserved);
        self::assertSame(0, $dry->themesConflict);
        self::assertSame(1, $dry->applyReady);
        self::assertSame($before, $this->evidence());

        $applied = $this->reconcile->reconcile($this->fixture, true, $dry->planFingerprint);
        self::assertTrue($applied->applied);
        self::assertFalse($applied->noop);
        self::assertSame(18, $applied->outcomesCreate);
        self::assertSame($before, $this->evidence());

        $program = $this->programs()->findOneByIdentity($subject, GradeLevel::Grade1, 'mat_grade1_tymm', 'TYMM-2026');
        self::assertInstanceOf(CurriculumProgram::class, $program);
        self::assertSame(CurriculumStatus::Published, $program->getStatus());
        $unit = $this->containerUnit($program, 'mat_1_3_occ1');
        self::assertNotNull($unit);
        self::assertTrue($pilot['unitId']->equals($unit->getId()));
        self::assertSame(5, $unit->getPosition());
        $outcome = $this->outcomes()->findOneByProgramAndCode($program, 'mat_1_3_1');
        self::assertInstanceOf(CurriculumLearningOutcome::class, $outcome);
        self::assertTrue($pilot['outcomeId']->equals($outcome->getId()));
        self::assertSame(OfficialCurriculumReconcileDocument::PILOT_DESCRIPTION, $outcome->getDescription());
        self::assertCount(19, $this->outcomes()->findByProgram($program));

        $again = $this->reconcile->reconcile($this->fixture, false);
        self::assertSame(0, $again->outcomesCreate);
        self::assertSame(0, $again->themesCreate);
        self::assertSame(0, $again->themesReorder);
        self::assertSame(19, $again->outcomesSkip);
        $second = $this->reconcile->reconcile($this->fixture, true, $again->planFingerprint);
        self::assertTrue($second->noop);
        self::assertFalse($second->applied);
        self::assertCount(19, $this->outcomes()->findByProgram($program));
    }

    public function testPublishedManagerStillRejectsStructuralWrites(): void
    {
        [$actor, $subject] = $this->seedActorAndSubject();
        $this->seedPublishedPilot($actor, $subject);
        $program = $this->programs()->findOneByIdentity($subject, GradeLevel::Grade1, 'mat_grade1_tymm', 'TYMM-2026');
        self::assertInstanceOf(CurriculumProgram::class, $program);
        $units = static::getContainer()->get(CurriculumUnitManager::class);
        self::assertInstanceOf(CurriculumUnitManager::class, $units);
        $this->expectException(CurriculumUnitException::class);
        $units->create($program, $actor, 'mat_1_1_occ1', 'Sayılar ve Nicelikler (1)', 2, 'reconcile_manager_guard');
    }

    public function testTextMismatchAndUnexpectedOutcomeBlockApply(): void
    {
        [$actor, $subject] = $this->seedActorAndSubject();
        $pilot = $this->seedPublishedPilot($actor, $subject);
        $program = $this->programs()->findOneByIdentity($subject, GradeLevel::Grade1, 'mat_grade1_tymm', 'TYMM-2026');
        self::assertInstanceOf(CurriculumProgram::class, $program);
        $outcome = $this->outcomes()->findOneByProgramAndCode($program, 'mat_1_3_1');
        self::assertInstanceOf(CurriculumLearningOutcome::class, $outcome);
        $outcome->rename('Başka bir metin olmamalı', mb_strtolower('Başka bir metin olmamalı', 'UTF-8'), new \DateTimeImmutable('now'));
        $this->entityManager->flush();
        $dry = $this->reconcile->reconcile($this->fixture, false);
        self::assertSame(0, $dry->applyReady);
        self::assertGreaterThan(0, $dry->outcomesConflict);
        $this->expectException(CurriculumImportException::class);
        try {
            $this->reconcile->reconcile($this->fixture, true, $dry->planFingerprint);
        } finally {
            $program = $this->programs()->findOneByIdentity($subject, GradeLevel::Grade1, 'mat_grade1_tymm', 'TYMM-2026');
            self::assertInstanceOf(CurriculumProgram::class, $program);
            $outcome = $this->outcomes()->findOneByProgramAndCode($program, 'mat_1_3_1');
            self::assertInstanceOf(CurriculumLearningOutcome::class, $outcome);
            self::assertTrue($pilot['outcomeId']->equals($outcome->getId()));
            self::assertSame('Başka bir metin olmamalı', $outcome->getDescription());
            self::assertCount(1, $this->outcomes()->findByProgram($program));
        }
    }

    public function testRetiredProgramIsRejected(): void
    {
        [$actor, $subject] = $this->seedActorAndSubject();
        $this->seedPublishedPilot($actor, $subject);
        $program = $this->programs()->findOneByIdentity($subject, GradeLevel::Grade1, 'mat_grade1_tymm', 'TYMM-2026');
        self::assertInstanceOf(CurriculumProgram::class, $program);
        $program->retire(new \DateTimeImmutable('now'));
        $this->entityManager->flush();
        $this->expectException(CurriculumImportException::class);
        $this->reconcile->reconcile($this->fixture, false);
    }

    public function testUnexpectedOutcomeBlocksApply(): void
    {
        [$actor, $subject] = $this->seedActorAndSubject();
        $this->seedPublishedPilot($actor, $subject);
        $program = $this->programs()->findOneByIdentity($subject, GradeLevel::Grade1, 'mat_grade1_tymm', 'TYMM-2026');
        self::assertInstanceOf(CurriculumProgram::class, $program);
        $topic = $this->outcomes()->findOneByProgramAndCode($program, 'mat_1_3_1')?->getTopic();
        self::assertNotNull($topic);
        $now = new \DateTimeImmutable('now');
        $extra = CurriculumLearningOutcome::create($topic, $program, 'mat_9_9_9', 'Fazla kayıt', 'fazla kayıt', 2, $now);
        $this->entityManager->persist($extra);
        $this->entityManager->flush();
        $dry = $this->reconcile->reconcile($this->fixture, false);
        self::assertGreaterThan(0, $dry->unexpectedRecords);
        self::assertSame(0, $dry->applyReady);
        try {
            $this->reconcile->reconcile($this->fixture, true, $dry->planFingerprint);
            self::fail('Apply should reject an unexpected outcome.');
        } catch (CurriculumImportException) {
            self::assertCount(2, $this->outcomes()->findByProgram($program));
        }
    }

    public function testBusyLockLeavesTheProgramUntouched(): void
    {
        [$actor, $subject] = $this->seedActorAndSubject();
        $this->seedPublishedPilot($actor, $subject);
        $locks = static::getContainer()->get(LockFactory::class);
        self::assertInstanceOf(LockFactory::class, $locks);
        $lock = $locks->createLock('app.curriculum.official_reconcile', 30.0);
        self::assertTrue($lock->acquire());
        try {
            $this->expectException(CurriculumImportException::class);
            $this->reconcile->reconcile($this->fixture, false);
        } finally {
            $lock->release();
        }
        $program = $this->programs()->findOneByIdentity($subject, GradeLevel::Grade1, 'mat_grade1_tymm', 'TYMM-2026');
        self::assertInstanceOf(CurriculumProgram::class, $program);
        self::assertCount(1, $this->outcomes()->findByProgram($program));
    }

    /**
     * @return array{0: User, 1: Subject}
     */
    private function seedActorAndSubject(): array
    {
        $users = static::getContainer()->get(UserRepository::class);
        $factory = static::getContainer()->get(UserFactory::class);
        $subjects = static::getContainer()->get(SubjectRepository::class);
        $subjectManager = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertInstanceOf(UserFactory::class, $factory);
        self::assertInstanceOf(SubjectRepository::class, $subjects);
        self::assertInstanceOf(SubjectManager::class, $subjectManager);

        $actor = $users->findOneActiveVerifiedSuperAdmin();
        if (!$actor instanceof User) {
            $actor = $factory->createAndPersist('ocr-sa@example.com', 'Guclu-Parola-123!', 'A', 'U', UserRole::Student);
            $actor->addGlobalRole(UserRole::SuperAdmin);
            $actor->markEmailVerified(new \DateTimeImmutable('now'));
            $actor->transitionTo(UserStatus::Active);
            $users->save($actor);
        }
        $subject = $subjects->findOneByCode('matematik');
        if (!$subject instanceof Subject) {
            $subject = $subjectManager->create($actor, 'matematik', 'Matematik', 'ocr_create_math');
        }

        return [$actor, $subject];
    }

    /**
     * @return array{unitId: \Symfony\Component\Uid\Uuid, outcomeId: \Symfony\Component\Uid\Uuid}
     */
    private function seedPublishedPilot(User $actor, Subject $subject): array
    {
        $existing = $this->programs()->findOneByIdentity($subject, GradeLevel::Grade1, 'mat_grade1_tymm', 'TYMM-2026');
        if ($existing instanceof CurriculumProgram && CurriculumStatus::Published === $existing->getStatus()) {
            $unit = $this->containerUnit($existing, 'mat_1_3_occ1');
            $outcome = $this->outcomes()->findOneByProgramAndCode($existing, 'mat_1_3_1');
            self::assertNotNull($unit);
            self::assertInstanceOf(CurriculumLearningOutcome::class, $outcome);

            return ['unitId' => $unit->getId(), 'outcomeId' => $outcome->getId()];
        }

        $programs = static::getContainer()->get(CurriculumProgramManager::class);
        $units = static::getContainer()->get(CurriculumUnitManager::class);
        $topics = static::getContainer()->get(CurriculumTopicManager::class);
        $outcomes = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $programs);
        self::assertInstanceOf(CurriculumUnitManager::class, $units);
        self::assertInstanceOf(CurriculumTopicManager::class, $topics);
        self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $outcomes);

        $program = $programs->createDraft(
            $subject,
            $actor,
            GradeLevel::Grade1,
            'mat_grade1_tymm',
            'İlkokul Matematik 1 (TYMM-2026)',
            'TYMM-2026',
            'ocr_program',
        );
        $unit = $units->create($program, $actor, 'mat_1_3_occ1', 'Nesnelerin Geometrisi (1)', 1, 'ocr_unit');
        $topic = $topics->createRoot($unit, $actor, 'uzamsal_iliskiler', 'Uzamsal İlişkiler', 1, 'ocr_topic');
        $outcome = $outcomes->create(
            $topic,
            $actor,
            'mat_1_3_1',
            OfficialCurriculumReconcileDocument::PILOT_DESCRIPTION,
            1,
            'ocr_outcome',
        );
        $programs->publish($program, $actor, 'ocr_publish');

        return ['unitId' => $unit->getId(), 'outcomeId' => $outcome->getId()];
    }

    private function programs(): CurriculumProgramRepository
    {
        $programs = static::getContainer()->get(CurriculumProgramRepository::class);
        self::assertInstanceOf(CurriculumProgramRepository::class, $programs);

        return $programs;
    }

    private function outcomes(): CurriculumLearningOutcomeRepository
    {
        $outcomes = static::getContainer()->get(CurriculumLearningOutcomeRepository::class);
        self::assertInstanceOf(CurriculumLearningOutcomeRepository::class, $outcomes);

        return $outcomes;
    }

    private function containerUnit(CurriculumProgram $program, string $code): ?\App\Entity\CurriculumUnit
    {
        $units = static::getContainer()->get(\App\Repository\CurriculumUnitRepository::class);
        self::assertInstanceOf(\App\Repository\CurriculumUnitRepository::class, $units);

        return $units->findOneByProgramAndCode($program, $code);
    }

    /**
     * @return array{questions: int, assessments: int, catalog: int, attempts: int}
     */
    private function evidence(): array
    {
        $connection = $this->entityManager->getConnection();

        return [
            'questions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM questions'),
            'assessments' => (int) $connection->fetchOne('SELECT COUNT(*) FROM assessments'),
            'catalog' => (int) $connection->fetchOne('SELECT COUNT(*) FROM catalog_topics'),
            'attempts' => (int) $connection->fetchOne('SELECT COUNT(*) FROM assessment_attempts'),
        ];
    }
}
