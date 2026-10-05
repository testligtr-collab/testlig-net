<?php

declare(strict_types=1);

namespace App\Tests\Question\Package;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\CurriculumTopic;
use App\Entity\CurriculumUnit;
use App\Entity\Question;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionScope;
use App\Enum\QuestionSourceType;
use App\Enum\QuestionType;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\QuestionPackageException;
use App\Question\Content\QuestionContentDocument;
use App\Question\Import\QuestionCsvParser;
use App\Question\Package\QuestionPackageConflictReason;
use App\Question\Package\QuestionPackageImportService;
use App\Question\Package\QuestionPackageTarget;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\CurriculumProgramRepository;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\SubjectRepository;
use App\Repository\UserRepository;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\QuestionManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class QuestionPackageImportServiceTest extends KernelTestCase
{
    private const PACKAGE = 'data/content/tymm-2026/grade-1/matematik/mat-1-3-3';

    private EntityManagerInterface $entityManager;
    private QuestionPackageImportService $importer;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $importer = static::getContainer()->get(QuestionPackageImportService::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(QuestionPackageImportService::class, $importer);
        $this->entityManager = $entityManager;
        $this->importer = $importer;
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        parent::tearDown();
    }

    public function testDryRunCreatesNothingAndApplyIsIdempotent(): void
    {
        $teacher = $this->teacher('qpkg-teacher@example.com');
        $this->ensureOutcome();
        $before = $this->evidence();

        $dry = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertSame(QuestionPackageTarget::mat133()->fixtureChecksum, $dry->fixtureChecksum);
        self::assertSame(1, $dry->packageFound);
        self::assertSame(1, $dry->actorAuthorized);
        self::assertSame(1, $dry->subjectFound);
        self::assertSame(1, $dry->outcomeFound);
        self::assertSame(5, $dry->rowsParsed);
        self::assertSame(5, $dry->codesMissing);
        self::assertSame(5, $dry->questionsToCreate);
        self::assertSame(5, $dry->revisionsToCreate);
        self::assertSame(5, $dry->answerKeysToCreate);
        self::assertSame(0, $dry->conflicts);
        self::assertSame(1, $dry->applyReady);
        self::assertSame(0, $dry->applied);
        self::assertSame('create', $dry->operation);
        self::assertSame($before, $this->evidence());

        try {
            $this->importer->execute(self::PACKAGE, 'apply', null, $teacher);
            self::fail('missing fingerprint');
        } catch (QuestionPackageException $exception) {
            self::assertSame('Apply requires the plan fingerprint.', $exception->getMessage());
        }
        try {
            $this->importer->execute(self::PACKAGE, 'apply', str_repeat('a', 64), $teacher);
            self::fail('wrong fingerprint');
        } catch (QuestionPackageException $exception) {
            self::assertSame('Plan fingerprint is stale.', $exception->getMessage());
        }
        self::assertSame($before, $this->evidence());

        $applied = $this->importer->execute(self::PACKAGE, 'apply', $dry->planFingerprint, $teacher);
        self::assertSame(1, $applied->applied);
        self::assertSame(5, $applied->questionsCreated);
        self::assertSame(5, $applied->revisionsCreated);
        self::assertSame(5, $applied->answerKeysCreated);
        self::assertSame(0, $applied->questionsSubmitted);
        self::assertSame(0, $applied->questionsPublished);
        self::assertSame(0, $applied->assessmentsTouched);
        self::assertSame(0, $applied->usersTouched);
        self::assertSame(5, $this->questionCount() - $before['questions']);
        $after = $this->evidence();
        self::assertSame($before['assessments'], $after['assessments']);
        self::assertSame($before['assessment_items'], $after['assessment_items']);
        self::assertSame($before['assessment_attempts'], $after['assessment_attempts']);
        self::assertSame($before['users'], $after['users']);

        $noopPlan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertSame(5, $noopPlan->codesMatching);
        self::assertSame(0, $noopPlan->questionsToCreate);
        self::assertSame(1, $noopPlan->noop);
        self::assertSame('noop', $noopPlan->operation);
        $noop = $this->importer->execute(self::PACKAGE, 'apply', $noopPlan->planFingerprint, $teacher);
        self::assertSame(0, $noop->applied);
        self::assertSame(1, $noop->noop);
        self::assertSame(0, $noop->questionsCreated);
        self::assertSame($after, $this->evidence());
        $this->assertPackageAudit('create');
        $this->assertPackageAudit('noop');
    }

    public function testPartialIdenticalAndMissingIsCreateReady(): void
    {
        $teacher = $this->teacher('qpkg-partial@example.com');
        $outcome = $this->ensureOutcome();
        $payloads = $this->payloads();
        $this->createOwnedDraft($teacher, $outcome, $payloads[0]);
        $this->createOwnedDraft($teacher, $outcome, $payloads[1]);

        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertSame(2, $plan->codesMatching);
        self::assertSame(3, $plan->codesMissing);
        self::assertSame(3, $plan->questionsToCreate);
        self::assertSame(0, $plan->conflicts);
        self::assertSame(1, $plan->applyReady);
    }

    public function testConcurrentCreateMakesApplyStale(): void
    {
        $teacher = $this->teacher('qpkg-race@example.com');
        $outcome = $this->ensureOutcome();
        $before = $this->evidence();
        $plan = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        $this->createOwnedDraft($teacher, $outcome, $this->payloads()[0]);
        try {
            $this->importer->execute(self::PACKAGE, 'apply', $plan->planFingerprint, $teacher);
            self::fail('stale');
        } catch (QuestionPackageException $exception) {
            self::assertSame('Plan fingerprint is stale.', $exception->getMessage());
        }
        self::assertSame($before['questions'] + 1, $this->evidence()['questions']);
    }

    public function testMismatchReasonsBlockApply(): void
    {
        $teacher = $this->teacher('qpkg-owner@example.com');
        $other = $this->teacher('qpkg-other@example.com');
        $outcome = $this->ensureOutcome();
        $payloads = $this->payloads();
        $this->createOwnedDraft($other, $outcome, $payloads[0]);
        $owner = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertContains(QuestionPackageConflictReason::EXISTING_OWNER_MISMATCH, $owner->conflictReasons);
        self::assertSame(0, $owner->applyReady);

        $statusTeacher = $this->teacher('qpkg-status@example.com');
        $question = $this->createOwnedDraft($statusTeacher, $outcome, $payloads[1]);
        $this->questions()->submitForReview($question, $statusTeacher, 'submit');
        $status = $this->importer->execute(self::PACKAGE, 'dry-run', null, $statusTeacher);
        self::assertContains(QuestionPackageConflictReason::EXISTING_STATUS_MISMATCH, $status->conflictReasons);

        $stemTeacher = $this->teacher('qpkg-stem@example.com');
        $changed = $payloads[2];
        $changed['stem'] = 'Farkli kok';
        $this->createOwnedDraft($stemTeacher, $outcome, $changed);
        $stem = $this->importer->execute(self::PACKAGE, 'dry-run', null, $stemTeacher);
        self::assertContains(QuestionPackageConflictReason::EXISTING_REVISION_MISMATCH, $stem->conflictReasons);

        $optionTeacher = $this->teacher('qpkg-opt@example.com');
        $changed = $payloads[3];
        $changed['options'][0] = 'Farkli secenek';
        $this->createOwnedDraft($optionTeacher, $outcome, $changed);
        $options = $this->importer->execute(self::PACKAGE, 'dry-run', null, $optionTeacher);
        self::assertContains(QuestionPackageConflictReason::EXISTING_OPTIONS_MISMATCH, $options->conflictReasons);

        $keyTeacher = $this->teacher('qpkg-key@example.com');
        $changed = $payloads[4];
        $changed['correctIndex'] = 0;
        $this->createOwnedDraft($keyTeacher, $outcome, $changed);
        $key = $this->importer->execute(self::PACKAGE, 'dry-run', null, $keyTeacher);
        self::assertContains(QuestionPackageConflictReason::EXISTING_ANSWER_KEY_MISMATCH, $key->conflictReasons);
    }

    public function testExplanationAndCatalogMismatches(): void
    {
        $teacher = $this->teacher('qpkg-exp@example.com');
        $outcome = $this->ensureOutcome();
        $payloads = $this->payloads();
        $changed = $payloads[0];
        $changed['explanation'] = 'Farkli aciklama';
        $this->createOwnedDraft($teacher, $outcome, $changed);
        $report = $this->importer->execute(self::PACKAGE, 'dry-run', null, $teacher);
        self::assertContains(QuestionPackageConflictReason::EXISTING_EXPLANATION_MISMATCH, $report->conflictReasons);

        $otherOutcome = $this->ensureExtraOutcome();
        $otherTeacher = $this->teacher('qpkg-out@example.com');
        $this->createOwnedDraft($otherTeacher, $otherOutcome, $payloads[1]);
        $outcomeReport = $this->importer->execute(self::PACKAGE, 'dry-run', null, $otherTeacher);
        self::assertContains(QuestionPackageConflictReason::EXISTING_OUTCOME_MISMATCH, $outcomeReport->conflictReasons);

        $gradeTeacher = $this->teacher('qpkg-grade@example.com');
        $this->createOwnedDraft($gradeTeacher, $outcome, $payloads[2]);
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE questions SET grade_level = 2 WHERE code = ?',
            [$payloads[2]['code']],
        );
        $this->entityManager->clear();
        $grade = $this->importer->execute(self::PACKAGE, 'dry-run', null, $this->users()->findOneByNormalizedEmail('qpkg-grade@example.com') ?? $gradeTeacher);
        self::assertContains(QuestionPackageConflictReason::EXISTING_GRADE_MISMATCH, $grade->conflictReasons);

        $subjectTeacher = $this->teacher('qpkg-sub@example.com');
        $this->createOwnedDraft($subjectTeacher, $outcome, $payloads[3]);
        $otherSubject = $this->extraSubject();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE questions SET subject_id = ? WHERE code = ?',
            [$otherSubject->getId()->toBinary(), $payloads[3]['code']],
        );
        $this->entityManager->clear();
        $subject = $this->importer->execute(self::PACKAGE, 'dry-run', null, $this->users()->findOneByNormalizedEmail('qpkg-sub@example.com') ?? $subjectTeacher);
        self::assertContains(QuestionPackageConflictReason::EXISTING_SUBJECT_MISMATCH, $subject->conflictReasons);
    }

    public function testPublishedQuestionIsNotOverwritten(): void
    {
        $author = $this->teacher('qpkg-pub-author@example.com');
        $reviewer = $this->teacher('qpkg-pub-reviewer@example.com');
        $reviewer->addGlobalRole(UserRole::Admin);
        $this->users()->save($reviewer);
        $outcome = $this->ensureOutcome();
        $question = $this->createOwnedDraft($author, $outcome, $this->payloads()[0]);
        $this->questions()->submitForReview($question, $author, 'submit');
        $this->questions()->publish($question, $reviewer, 'publish_ok');
        $report = $this->importer->execute(self::PACKAGE, 'dry-run', null, $author);
        self::assertContains(QuestionPackageConflictReason::EXISTING_STATUS_MISMATCH, $report->conflictReasons);
        self::assertSame(0, $report->applyReady);
    }

    public function testUnauthorizedActorIsBlocked(): void
    {
        $this->ensureOutcome();
        $student = $this->teacher('qpkg-student@example.com', UserRole::Student);
        $report = $this->importer->execute(self::PACKAGE, 'dry-run', null, $student);
        self::assertContains(QuestionPackageConflictReason::ACTOR_NOT_AUTHORIZED, $report->conflictReasons);
        self::assertSame(0, $report->applyReady);
        self::assertSame(0, $report->actorAuthorized);
    }

    public function testUnknownPackageIsRejected(): void
    {
        $teacher = $this->teacher('qpkg-pkg@example.com');
        $this->expectException(QuestionPackageException::class);
        $this->importer->execute('data/content/tymm-2026/grade-1/matematik/mat-1-3-2', 'verify', null, $teacher);
    }

    /**
     * @param array{
     *     code: string,
     *     grade: int,
     *     subjectCode: string,
     *     outcomeCode: string,
     *     stem: string,
     *     options: list<string>,
     *     correctIndex: int,
     *     explanation: string
     * } $payload
     */
    private function createOwnedDraft(
        User $actor,
        CurriculumLearningOutcome $outcome,
        array $payload,
        ?GradeLevel $grade = null,
        ?Subject $subject = null,
    ): Question {
        $options = [];
        foreach ($payload['options'] as $index => $text) {
            $options[] = [
                'stableKey' => 'opt_'.($index + 1),
                'content' => QuestionContentDocument::paragraph($text),
                'position' => $index + 1,
            ];
        }

        return $this->questions()->createDraftQuestion(
            $actor,
            QuestionScope::Platform,
            null,
            $subject ?? $outcome->getCurriculumProgram()->getSubject(),
            $grade ?? GradeLevel::from($payload['grade']),
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph($payload['stem']),
            QuestionContentDocument::paragraph($payload['explanation'])->toArray(),
            $options,
            ['correctStableKey' => 'opt_'.($payload['correctIndex'] + 1)],
            [['learningOutcome' => $outcome, 'isPrimary' => true]],
            QuestionDifficulty::Medium,
            'create_qpkg',
            null,
            QuestionSourceType::Original,
            null,
            $this->uuidFromCode($payload['code']),
        );
    }

    /**
     * @return list<array{
     *     code: string,
     *     grade: int,
     *     subjectCode: string,
     *     outcomeCode: string,
     *     stem: string,
     *     options: list<string>,
     *     correctIndex: int,
     *     explanation: string
     * }>
     */
    private function payloads(): array
    {
        $target = QuestionPackageTarget::mat133();
        $bytes = (string) file_get_contents(\dirname(__DIR__, 3).'/data/content/tymm-2026/grade-1/matematik/mat-1-3-3/questions.csv');
        $records = (new QuestionCsvParser())->parse($bytes);
        $payloads = [];
        foreach ($records as $record) {
            $values = $record['values'];
            $correct = strtoupper($values['correct_option']);
            $payloads[] = [
                'code' => $values['code'],
                'grade' => $target->gradeLevel,
                'subjectCode' => $target->subjectCode,
                'outcomeCode' => $target->outcomeCode,
                'stem' => $values['stem'],
                'options' => [$values['option_a'], $values['option_b'], $values['option_c'], $values['option_d']],
                'correctIndex' => \ord($correct) - \ord('A'),
                'explanation' => $values['explanation'],
            ];
        }

        return $payloads;
    }

    private function uuidFromCode(string $code): Uuid
    {
        $formatted = substr($code, 0, 8).'-'.substr($code, 8, 4).'-'.substr($code, 12, 4).'-'.substr($code, 16, 4).'-'.substr($code, 20);

        return Uuid::fromString($formatted);
    }

    /**
     * @return array<string, int>
     */
    private function evidence(): array
    {
        $connection = $this->entityManager->getConnection();
        $counts = [];
        foreach (['questions', 'question_answer_keys', 'assessments', 'assessment_items', 'assessment_attempts', 'users'] as $table) {
            $counts[$table] = (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
        }

        return $counts;
    }

    private function questionCount(): int
    {
        return $this->evidence()['questions'];
    }

    private function assertPackageAudit(string $operation): void
    {
        $events = static::getContainer()->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);
        /** @var list<\App\Entity\SecurityAuditEvent> $rows */
        $rows = $events->createQueryBuilder('event')
            ->andWhere('event.action = :action')
            ->setParameter('action', SecurityAuditAction::QuestionsBulkImported->value)
            ->getQuery()
            ->getResult();
        $found = false;
        foreach ($rows as $event) {
            $metadata = $event->getMetadata();
            $encoded = json_encode($metadata);
            self::assertIsString($encoded);
            self::assertStringNotContainsString('@', $encoded);
            if (($metadata['source'] ?? null) === 'question_package_import' && ($metadata['operation'] ?? null) === $operation) {
                self::assertSame(QuestionPackageTarget::mat133()->packageKey, $metadata['package_key'] ?? null);
                self::assertArrayNotHasKey('email', $metadata);
                self::assertArrayNotHasKey('stem', $metadata);
                $found = true;
            }
        }
        self::assertTrue($found);
    }

    private function ensureOutcome(): CurriculumLearningOutcome
    {
        $program = $this->program();
        $existing = $this->outcomes()->findOneByProgramAndCode($program, 'mat_1_3_3');
        if ($existing instanceof CurriculumLearningOutcome) {
            return $existing;
        }
        if (CurriculumStatus::Draft === $program->getStatus()) {
            return $this->outcomeManager()->create($this->freshTopic($program), $this->superAdmin(), 'mat_1_3_3', 'Kazanim', 1, 'create_lo');
        }

        return $this->attachOutcome($program, 'mat_1_3_3', 'qpkg_unit_133', 92);
    }

    private function ensureExtraOutcome(): CurriculumLearningOutcome
    {
        $program = $this->program();
        $existing = $this->outcomes()->findOneByProgramAndCode($program, 'mat_1_3_1');
        if ($existing instanceof CurriculumLearningOutcome) {
            return $existing;
        }

        return $this->attachOutcome($program, 'qpkg_other', 'qpkg_unit_b', 91);
    }

    private function attachOutcome(CurriculumProgram $program, string $code, string $unitCode, int $position): CurriculumLearningOutcome
    {
        $now = new \DateTimeImmutable('now');
        $unit = CurriculumUnit::create($program, $unitCode, 'Paket', 'paket', $position, null, $now);
        $this->entityManager->persist($unit);
        $this->entityManager->flush();
        $topic = CurriculumTopic::createRoot($unit, $unitCode.'_t', 'Konu', 'konu', 1, null, $now);
        $this->entityManager->persist($topic);
        $this->entityManager->flush();
        $outcome = CurriculumLearningOutcome::create($topic, $program, $code, 'Kazanim', 'kazanim', 1, $now);
        $this->entityManager->persist($outcome);
        $this->entityManager->flush();

        return $outcome;
    }

    private function extraSubject(): Subject
    {
        $existing = $this->subjects()->findOneByCode('qpkgfizik');
        if ($existing instanceof Subject) {
            return $existing;
        }

        return $this->subjectManager()->create($this->superAdmin(), 'qpkgfizik', 'Fizik', 'create_s');
    }

    private function freshTopic(CurriculumProgram $program): CurriculumTopic
    {
        $unit = $this->units()->create($program, $this->superAdmin(), 'qpkg_draft_unit', 'Unit', 2, 'create_u');

        return $this->topics()->createRoot($unit, $this->superAdmin(), 'qpkg_draft_topic', 'Topic', 1, 'create_t');
    }

    private function program(): CurriculumProgram
    {
        $subject = $this->subject();
        $existing = $this->programs()->findOneByIdentity($subject, GradeLevel::Grade1, 'mat_grade1_tymm', 'TYMM-2026');
        if ($existing instanceof CurriculumProgram) {
            return $existing;
        }
        $program = $this->programManager()->createDraft(
            $subject,
            $this->superAdmin(),
            GradeLevel::Grade1,
            'mat_grade1_tymm',
            'Matematik 1',
            'TYMM-2026',
            'create_p',
        );
        $this->programManager()->publish($program, $this->superAdmin(), 'publish_p');

        return $program;
    }

    private function subject(): Subject
    {
        $existing = $this->subjects()->findOneByCode('matematik');
        if ($existing instanceof Subject) {
            return $existing;
        }

        return $this->subjectManager()->create($this->superAdmin(), 'matematik', 'Matematik', 'create_s');
    }

    private function teacher(string $email, UserRole $role = UserRole::Teacher): User
    {
        $user = $this->users()->findOneByNormalizedEmail(mb_strtolower($email, 'UTF-8'));
        if (!$user instanceof User) {
            $user = $this->factory()->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', $role);
            $user->markEmailVerified(new \DateTimeImmutable('now'));
            $user->transitionTo(UserStatus::Active);
            $this->users()->save($user);
        }

        return $user;
    }

    private function superAdmin(): User
    {
        $user = $this->users()->findOneActiveVerifiedSuperAdmin();
        if ($user instanceof User) {
            return $user;
        }
        $user = $this->factory()->createAndPersist('qpkg-sa@example.com', 'Guclu-Parola-123!', 'A', 'U', UserRole::Student);
        $user->addGlobalRole(UserRole::SuperAdmin);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users()->save($user);

        return $user;
    }

    private function questions(): QuestionManager
    {
        $manager = static::getContainer()->get(QuestionManager::class);
        self::assertInstanceOf(QuestionManager::class, $manager);

        return $manager;
    }

    private function users(): UserRepository
    {
        $repository = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }

    private function factory(): UserFactory
    {
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);

        return $factory;
    }

    private function subjects(): SubjectRepository
    {
        $repository = static::getContainer()->get(SubjectRepository::class);
        self::assertInstanceOf(SubjectRepository::class, $repository);

        return $repository;
    }

    private function subjectManager(): SubjectManager
    {
        $manager = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $manager);

        return $manager;
    }

    private function programs(): CurriculumProgramRepository
    {
        $repository = static::getContainer()->get(CurriculumProgramRepository::class);
        self::assertInstanceOf(CurriculumProgramRepository::class, $repository);

        return $repository;
    }

    private function programManager(): CurriculumProgramManager
    {
        $manager = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $manager);

        return $manager;
    }

    private function units(): CurriculumUnitManager
    {
        $manager = static::getContainer()->get(CurriculumUnitManager::class);
        self::assertInstanceOf(CurriculumUnitManager::class, $manager);

        return $manager;
    }

    private function topics(): CurriculumTopicManager
    {
        $manager = static::getContainer()->get(CurriculumTopicManager::class);
        self::assertInstanceOf(CurriculumTopicManager::class, $manager);

        return $manager;
    }

    private function outcomes(): CurriculumLearningOutcomeRepository
    {
        $repository = static::getContainer()->get(CurriculumLearningOutcomeRepository::class);
        self::assertInstanceOf(CurriculumLearningOutcomeRepository::class, $repository);

        return $repository;
    }

    private function outcomeManager(): CurriculumLearningOutcomeManager
    {
        $manager = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $manager);

        return $manager;
    }
}
