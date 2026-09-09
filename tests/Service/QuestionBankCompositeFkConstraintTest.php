<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionScope;
use App\Enum\QuestionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Question\Content\QuestionContentDocument;
use App\Repository\QuestionRevisionRepository;
use App\Repository\UserRepository;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\QuestionManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

final class QuestionBankCompositeFkConstraintTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebind();
        $this->cleanup();
    }

    public function testInformationSchemaHasCompositeForeignKeys(): void
    {
        $schema = $this->em->getConnection()->createSchemaManager()->introspectSchema();
        self::assertTrue($schema->getTable('curriculum_learning_outcomes')->hasForeignKey('FK_CLO_TOPIC_UNIT'));
        self::assertTrue($schema->getTable('curriculum_learning_outcomes')->hasForeignKey('FK_CLO_UNIT_PROGRAM'));
        self::assertTrue($schema->getTable('question_revision_alignments')->hasForeignKey('FK_QRA_PROGRAM_SUBJECT'));
        self::assertTrue($schema->getTable('question_revision_alignments')->hasForeignKey('FK_QRA_OUTCOME_TOPIC_PROGRAM'));
        self::assertTrue($schema->getTable('question_revision_primary_alignment_guards')->hasForeignKey('FK_QRPAG_ALIGNMENT_REVISION_PRIMARY'));

        $checks = $this->em->getConnection()->fetchFirstColumn(
            "SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_question_scope_institution'",
        );
        self::assertContains('chk_question_scope_institution', $checks);
    }

    public function testDuplicateRevisionNumberRejected(): void
    {
        $indexes = $this->em->getConnection()->createSchemaManager()->listTableIndexes('question_revisions');
        self::assertArrayHasKey('uniq_question_revision_number', $indexes);

        [$sa, $subject, $program, $lo] = $this->platformCurriculum('cfk_dup');
        $question = $this->createSingleChoice($sa, $subject, $lo, 'opt_a', 'cfk_dup_q');
        /** @var QuestionRevisionRepository $revisions */
        $revisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $revision = $revisions->findForQuestionNumber($question, 1);
        self::assertInstanceOf(QuestionRevision::class, $revision);

        try {
            $this->em->getConnection()->insert('question_revisions', [
                'id' => (new UuidV7())->toBinary(),
                'question_id' => $question->getId()->toBinary(),
                'revision_number' => 1,
                'type' => QuestionType::SingleChoice->value,
                'stem_content' => json_encode(QuestionContentDocument::paragraph('Dup')->toArray(), \JSON_THROW_ON_ERROR),
                'explanation_content' => null,
                'difficulty' => QuestionDifficulty::Easy->value,
                'estimated_seconds' => null,
                'source_type' => 'original',
                'source_reference' => null,
                'created_by_id' => $sa->getId()->toBinary(),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'content_hash' => str_repeat('c', 64),
                'schema_version' => 1,
            ]);
            self::fail('duplicate revision_number must be rejected');
        } catch (\Throwable $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testPrimaryAlignmentGuardUnique(): void
    {
        $indexes = $this->em->getConnection()->createSchemaManager()->listTableIndexes('question_revision_primary_alignment_guards');
        self::assertArrayHasKey('primary', $indexes);
        self::assertArrayHasKey('uniq_qrpag_alignment', $indexes);
    }

    public function testRealDbalRejectsProgramSubjectCompositeFkMismatch(): void
    {
        [$sa, $subject, $program, $lo] = $this->platformCurriculum('cfk_ps');
        $otherSubject = $this->subjects()->create($sa, 'other_cfk_ps', 'Other', 'create_other');
        $question = $this->createSingleChoice($sa, $subject, $lo, 'opt_a', 'cfk_ps_q');
        /** @var QuestionRevisionRepository $revisions */
        $revisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $revision = $revisions->findForQuestionNumber($question, 1);
        self::assertInstanceOf(QuestionRevision::class, $revision);

        try {
            $this->em->getConnection()->insert('question_revision_alignments', [
                'id' => (new UuidV7())->toBinary(),
                'revision_id' => $revision->getId()->toBinary(),
                'curriculum_program_id' => $program->getId()->toBinary(),
                'subject_id' => $otherSubject->getId()->toBinary(),
                'curriculum_topic_id' => $lo->getTopic()->getId()->toBinary(),
                'learning_outcome_id' => $lo->getId()->toBinary(),
                'is_primary' => 0,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            self::fail('FK_QRA_PROGRAM_SUBJECT must reject subject mismatch');
        } catch (\Throwable $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testRealDbalRejectsOutcomeTopicProgramCompositeFkMismatch(): void
    {
        [$sa, $subject, $program, $lo] = $this->platformCurriculum('cfk_otp');
        $otherDraft = $this->programs()->createDraft($subject, $sa, GradeLevel::Grade9, 'math_cfk_otp2', 'Math2', '1.0', 'prog2');
        $otherUnit = $this->units()->create($otherDraft, $sa, 'u2', 'Unit2', 1, 'create_u2');
        $otherTopic = $this->topics()->createRoot($otherUnit, $sa, 't2', 'Topic2', 1, 'create_t2');
        $question = $this->createSingleChoice($sa, $subject, $lo, 'opt_a', 'cfk_otp_q');
        /** @var QuestionRevisionRepository $revisions */
        $revisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $revision = $revisions->findForQuestionNumber($question, 1);
        self::assertInstanceOf(QuestionRevision::class, $revision);

        try {
            $this->em->getConnection()->insert('question_revision_alignments', [
                'id' => (new UuidV7())->toBinary(),
                'revision_id' => $revision->getId()->toBinary(),
                'curriculum_program_id' => $program->getId()->toBinary(),
                'subject_id' => $subject->getId()->toBinary(),
                'curriculum_topic_id' => $otherTopic->getId()->toBinary(),
                'learning_outcome_id' => $lo->getId()->toBinary(),
                'is_primary' => 0,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            self::fail('FK_QRA_OUTCOME_TOPIC_PROGRAM must reject topic/program mismatch');
        } catch (\Throwable $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    /**
     * @return array{0: User, 1: Subject, 2: CurriculumProgram, 3: CurriculumLearningOutcome}
     */
    private function platformCurriculum(string $suffix): array
    {
        $sa = $this->superAdmin($suffix.'-sa@example.com');
        $subject = $this->subjects()->create($sa, 'math_'.$suffix, 'Math '.$suffix, 'create_subj');
        $draft = $this->programs()->createDraft($subject, $sa, GradeLevel::Grade9, 'math_'.$suffix, 'Math', '1.0', 'prog');
        $unit = $this->units()->create($draft, $sa, 'u1', 'Unit', 1, 'create_u');
        $topic = $this->topics()->createRoot($unit, $sa, 't1', 'Topic', 1, 'create_t');
        $lo = $this->outcomes()->create($topic, $sa, 'lo_'.$suffix, 'Outcome', 1, 'create_lo');

        return [$sa, $subject, $draft, $lo];
    }

    private function createSingleChoice(
        User $actor,
        Subject $subject,
        CurriculumLearningOutcome $lo,
        string $correctKey,
        string $reason,
    ): Question {
        return $this->questions()->createDraftQuestion(
            $actor,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('CFK stem?'),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
            ],
            ['correctStableKey' => $correctKey],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            $reason,
        );
    }

    private function superAdmin(string $email): User
    {
        $user = $this->activeUser($email, UserRole::Student);
        $user->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($user);

        return $user;
    }

    private function activeUser(string $email, UserRole $role = UserRole::Student): User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', $role);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    private function subjects(): SubjectManager
    {
        $s = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $s);

        return $s;
    }

    private function programs(): CurriculumProgramManager
    {
        $s = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $s);

        return $s;
    }

    private function units(): CurriculumUnitManager
    {
        $s = static::getContainer()->get(CurriculumUnitManager::class);
        self::assertInstanceOf(CurriculumUnitManager::class, $s);

        return $s;
    }

    private function topics(): CurriculumTopicManager
    {
        $s = static::getContainer()->get(CurriculumTopicManager::class);
        self::assertInstanceOf(CurriculumTopicManager::class, $s);

        return $s;
    }

    private function outcomes(): CurriculumLearningOutcomeManager
    {
        $s = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $s);

        return $s;
    }

    private function questions(): QuestionManager
    {
        $s = static::getContainer()->get(QuestionManager::class);
        self::assertInstanceOf(QuestionManager::class, $s);

        return $s;
    }

    private function rebind(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->factory = $factory;
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($connection, [
            'question_revision_primary_alignment_guards',
            'question_revision_alignments',
            'question_answer_keys',
            'question_revision_options',
            'question_revisions',
            'questions',
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
            'institution_memberships',
            'institutions',
            'security_audit_events',
            'security_bootstrap_guards',
            'reset_password_requests',
            'users',
        ]);
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanup();
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }
}
