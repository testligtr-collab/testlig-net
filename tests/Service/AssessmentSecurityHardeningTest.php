<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Assessment;
use App\Entity\AssessmentRevision;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AssessmentFailureReason;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionOrderMode;
use App\Enum\QuestionScope;
use App\Enum\QuestionType;
use App\Enum\ResultReleasePolicy;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\AssessmentException;
use App\Question\Content\QuestionContentDocument;
use App\Repository\AssessmentRevisionRepository;
use App\Repository\QuestionRevisionRepository;
use App\Repository\UserRepository;
use App\Service\AssessmentManager;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\QuestionManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\AssessmentDbCleanup;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

final class AssessmentSecurityHardeningTest extends KernelTestCase
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

    public function testDbalUpdateDeleteAndSealedInsertBlockedWithoutBypass(): void
    {
        [$assessment, $revision] = $this->seedPublishedAssessment('seal');

        $conn = $this->em->getConnection();
        $triggers = $conn->fetchFirstColumn(
            "SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND EVENT_OBJECT_TABLE IN ('assessment_revisions','assessment_sections','assessment_items','assessment_publications')",
        );
        foreach ($triggers as $body) {
            self::assertStringNotContainsStringIgnoringCase('@testlig', (string) $body);
            self::assertStringNotContainsStringIgnoringCase('bypass', (string) $body);
        }

        try {
            $conn->executeStatement(
                'UPDATE assessment_revisions SET title = ? WHERE id = ?',
                ['Hacked', $revision->getId()->toBinary()],
            );
            self::fail('revision update');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'DELETE FROM assessment_revisions WHERE id = ?',
                [$revision->getId()->toBinary()],
            );
            self::fail('revision delete');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->insert('assessment_sections', [
                'id' => (new UuidV7())->toBinary(),
                'revision_id' => $revision->getId()->toBinary(),
                'title' => 'Late',
                'instructions' => null,
                'position' => 99,
                'duration_seconds' => null,
                'question_order_mode' => QuestionOrderMode::Fixed->value,
                'created_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            ]);
            self::fail('sealed section insert');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $conn->executeStatement(
                'UPDATE assessment_publications SET schema_version = 99 WHERE assessment_id = ?',
                [$assessment->getId()->toBinary()],
            );
            self::fail('publication update');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        // ORM seal-only path already applied; second seal must fail.
        try {
            $revision->seal();
            self::fail('double seal');
        } catch (AssessmentException $e) {
            self::assertSame(AssessmentFailureReason::Immutable, $e->getReason());
        }
    }

    public function testParentCascadeCleanupClearsChildren(): void
    {
        $this->seedPublishedAssessment('casc');
        $conn = $this->em->getConnection();
        self::assertGreaterThan(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM assessment_revisions'));
        AssessmentDbCleanup::deleteAssessments($conn);
    }

    public function testDuplicatePublicationRevisionRejected(): void
    {
        [$assessment, $revision] = $this->seedPublishedAssessment('dupub');
        $conn = $this->em->getConnection();
        $pub = $conn->fetchAssociative(
            'SELECT * FROM assessment_publications WHERE assessment_id = ? LIMIT 1',
            [$assessment->getId()->toBinary()],
        );
        self::assertIsArray($pub);

        try {
            $conn->insert('assessment_publications', [
                'id' => (new UuidV7())->toBinary(),
                'assessment_id' => $assessment->getId()->toBinary(),
                'assessment_revision_id' => $revision->getId()->toBinary(),
                'publication_number' => 2,
                'manifest' => $pub['manifest'],
                'manifest_hash' => $pub['manifest_hash'],
                'published_by_id' => $pub['published_by_id'],
                'published_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'schema_version' => 1,
            ]);
            self::fail('duplicate revision publication');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    /**
     * @return array{0: Assessment, 1: AssessmentRevision}
     */
    private function seedPublishedAssessment(string $suffix): array
    {
        [$sa, $reviewer, $subject, $program, $lo] = $this->platformCurriculum($suffix);
        $this->programs()->publish($program, $sa, 'pub');
        $q = $this->createPublishedQuestion($sa, $reviewer, $subject, $lo, $suffix);
        /** @var QuestionRevisionRepository $qRevisions */
        $qRevisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $qr = $qRevisions->findForQuestionNumber($q, 1);
        self::assertInstanceOf(QuestionRevision::class, $qr);

        $assessment = $this->assessments()->createDraftAssessment(
            $sa,
            AssessmentScope::Platform,
            null,
            AssessmentType::MockExam,
            GradeLevel::Grade9,
            'Secure '.$suffix,
            null,
            null,
            600,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Immediate,
            null,
            [[
                'title' => 'S1',
                'position' => 1,
                'questionOrderMode' => QuestionOrderMode::Fixed,
                'items' => [[
                    'questionId' => $q->getId(),
                    'questionRevisionId' => $qr->getId(),
                    'position' => 1,
                    'points' => '1.00',
                    'penaltyPoints' => '0.00',
                    'required' => true,
                ]],
            ]],
            'create_'.$suffix,
        );
        $this->assessments()->submitForReview($assessment, $sa, 'submit');
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);
        $reviewer = $this->users->find($reviewer->getId());
        self::assertInstanceOf(User::class, $reviewer);
        $this->assessments()->publish($assessment, $reviewer, 'publish');
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);

        /** @var AssessmentRevisionRepository $revisions */
        $revisions = static::getContainer()->get(AssessmentRevisionRepository::class);
        $revision = $revisions->findForAssessmentNumber($assessment, 1);
        self::assertInstanceOf(AssessmentRevision::class, $revision);

        return [$assessment, $revision];
    }

    private function createPublishedQuestion(
        User $author,
        User $publisher,
        Subject $subject,
        CurriculumLearningOutcome $lo,
        string $suffix,
    ): Question {
        $question = $this->questions()->createDraftQuestion(
            $author,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::TrueFalse,
            QuestionContentDocument::paragraph('TF '.$suffix.'?'),
            null,
            [],
            ['correct' => true],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'create_q_'.$suffix,
        );
        $this->questions()->submitForReview($question, $author, 'submit_q');
        $question = $this->em->find(Question::class, $question->getId());
        self::assertInstanceOf(Question::class, $question);
        $publisher = $this->users->find($publisher->getId());
        self::assertInstanceOf(User::class, $publisher);
        $this->questions()->publish($question, $publisher, 'pub_q');
        $question = $this->em->find(Question::class, $question->getId());
        self::assertInstanceOf(Question::class, $question);

        return $question;
    }

    /**
     * @return array{0: User, 1: User, 2: Subject, 3: CurriculumProgram, 4: CurriculumLearningOutcome}
     */
    private function platformCurriculum(string $suffix): array
    {
        $sa = $this->superAdmin($suffix.'-sa@example.com');
        $reviewer = $this->activeUser($suffix.'-rev@example.com', UserRole::HeadTeacher);
        $subject = $this->subjects()->create($sa, 'math_'.$suffix, 'Math '.$suffix, 'create_subj');
        $draft = $this->programs()->createDraft($subject, $sa, GradeLevel::Grade9, 'math_'.$suffix, 'Math', '1.0', 'prog');
        $unit = $this->units()->create($draft, $sa, 'u1', 'Unit', 1, 'create_u');
        $topic = $this->topics()->createRoot($unit, $sa, 't1', 'Topic', 1, 'create_t');
        $lo = $this->outcomes()->create($topic, $sa, 'lo_'.$suffix, 'Outcome', 1, 'create_lo');

        return [$sa, $reviewer, $subject, $draft, $lo];
    }

    private function assessments(): AssessmentManager
    {
        $s = static::getContainer()->get(AssessmentManager::class);
        self::assertInstanceOf(AssessmentManager::class, $s);

        return $s;
    }

    private function questions(): QuestionManager
    {
        $s = static::getContainer()->get(QuestionManager::class);
        self::assertInstanceOf(QuestionManager::class, $s);

        return $s;
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

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        AssessmentDbCleanup::deleteAssessments($connection);
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($connection, [
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
            'institution_memberships',
            'institutions',
            'security_audit_events',
            'users',
        ]);
    }
}
