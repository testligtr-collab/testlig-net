<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Assessment\AssessmentManifestHasher;
use App\Entity\Assessment;
use App\Entity\AssessmentPublication;
use App\Entity\AssessmentRevision;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AssessmentFailureReason;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionOrderMode;
use App\Enum\QuestionScope;
use App\Enum\QuestionType;
use App\Enum\ResultReleasePolicy;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\AssessmentException;
use App\Question\Content\QuestionContentDocument;
use App\Repository\AssessmentPublicationRepository;
use App\Repository\AssessmentRevisionRepository;
use App\Repository\QuestionRevisionRepository;
use App\Repository\SecurityAuditEventRepository;
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
use App\Tests\Support\JsonKeyTree;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssessmentDomainTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private SecurityAuditEventRepository $events;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebind();
        $this->cleanup();
    }

    public function testLifecycleReviewSeparationPublicationHistoryAndManifest(): void
    {
        [$sa, $reviewer, $subject, $program, $lo] = $this->platformCurriculum('adom');
        $this->programs()->publish($program, $sa, 'pub_curr');
        $publishedQuestion = $this->createPublishedPlatformQuestion($sa, $reviewer, $subject, $lo, 'adom');

        /** @var QuestionRevisionRepository $qRevisions */
        $qRevisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $qRevision = $qRevisions->findForQuestionNumber($publishedQuestion, 1);
        self::assertInstanceOf(QuestionRevision::class, $qRevision);

        $assessment = $this->assessments()->createDraftAssessment(
            $sa,
            AssessmentScope::Platform,
            null,
            AssessmentType::Quiz,
            GradeLevel::Grade9,
            'Midterm Blueprint',
            'Desc',
            'Read carefully',
            3600,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Immediate,
            '50.00',
            [$this->sectionWithItem($publishedQuestion, $qRevision)],
            'create_a',
        );
        self::assertSame(AssessmentStatus::Draft, $assessment->getStatus());
        self::assertSame(1, $assessment->getCurrentRevisionNumber());
        self::assertNull($assessment->getPublishedRevisionNumber());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AssessmentCreated->value));

        /** @var AssessmentRevisionRepository $aRevisions */
        $aRevisions = static::getContainer()->get(AssessmentRevisionRepository::class);
        $rev1 = $aRevisions->findForAssessmentNumber($assessment, 1);
        self::assertInstanceOf(AssessmentRevision::class, $rev1);
        self::assertTrue($rev1->isSealed());

        $this->assessments()->submitForReview($assessment, $sa, 'submit_a');
        $assessment = $this->reloadAssessment($assessment->getId());
        self::assertSame(AssessmentStatus::InReview, $assessment->getStatus());

        try {
            $this->assessments()->publish($assessment, $sa, 'self_pub');
            self::fail('review separation');
        } catch (AssessmentException $e) {
            self::assertSame(AssessmentFailureReason::ReviewSeparation, $e->getReason());
        }
        self::assertSame(0, $this->events->countByAction(SecurityAuditAction::AssessmentPublished->value));

        $this->resetDoctrine();
        $sa = $this->users->find($sa->getId());
        $reviewer = $this->users->find($reviewer->getId());
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(User::class, $sa);
        self::assertInstanceOf(User::class, $reviewer);
        self::assertInstanceOf(Assessment::class, $assessment);

        $this->assessments()->publish($assessment, $reviewer, 'publish_ok');
        $assessment = $this->reloadAssessment($assessment->getId());
        self::assertSame(AssessmentStatus::Published, $assessment->getStatus());
        self::assertSame(1, $assessment->getPublishedRevisionNumber());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AssessmentPublished->value));
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AssessmentPublicationCreated->value));

        /** @var AssessmentPublicationRepository $pubs */
        $pubs = static::getContainer()->get(AssessmentPublicationRepository::class);
        $publication = $pubs->findOneBy(['assessment' => $assessment, 'publicationNumber' => 1]);
        self::assertInstanceOf(AssessmentPublication::class, $publication);
        $manifest = $publication->getManifest();
        $keys = JsonKeyTree::collectKeys($manifest);
        foreach ([
            'answerPayload',
            'correctStableKey',
            'correctStableKeys',
            'acceptedAnswers',
            'tolerance',
            'answerIntegrityHmac',
            'answer_integrity_hmac',
            'password',
            'email',
            'token',
            'secret',
        ] as $forbidden) {
            self::assertNotContains($forbidden, $keys);
        }
        self::assertSame($qRevision->getContentHash(), $manifest['sections'][0]['items'][0]['questionPublicContentHash']);

        /** @var AssessmentManifestHasher $hasher */
        $hasher = static::getContainer()->get(AssessmentManifestHasher::class);
        self::assertSame($publication->getManifestHash(), $hasher->hash($manifest));
        $reordered = $manifest;
        $reordered = ['manifestSchemaVersion' => $manifest['manifestSchemaVersion']] + $manifest;
        self::assertSame($publication->getManifestHash(), $hasher->hash($reordered));

        $sa = $this->users->find($sa->getId());
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        $publishedQuestion = $this->em->find(Question::class, $publishedQuestion->getId());
        $qRevision = $this->em->find(QuestionRevision::class, $qRevision->getId());
        self::assertInstanceOf(User::class, $sa);
        self::assertInstanceOf(Assessment::class, $assessment);
        self::assertInstanceOf(Question::class, $publishedQuestion);
        self::assertInstanceOf(QuestionRevision::class, $qRevision);

        $rev2 = $this->assessments()->createRevision(
            $assessment,
            $sa,
            'Midterm Blueprint v2',
            null,
            null,
            3600,
            NavigationMode::Sequential,
            QuestionOrderMode::Shuffle,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Manual,
            '60.00',
            [$this->sectionWithItem($publishedQuestion, $qRevision, title: 'Section A')],
            'rev2',
        );
        self::assertSame(2, $rev2->getRevisionNumber());
        $assessment = $this->reloadAssessment($assessment->getId());
        self::assertSame(AssessmentStatus::Draft, $assessment->getStatus());
        self::assertSame(2, $assessment->getCurrentRevisionNumber());
        self::assertSame(1, $assessment->getPublishedRevisionNumber());
        self::assertSame(1, $pubs->count(['assessment' => $assessment]));

        $this->assessments()->submitForReview($assessment, $sa, 'submit2');
        $assessment = $this->reloadAssessment($assessment->getId());
        $reviewer = $this->users->find($reviewer->getId());
        self::assertInstanceOf(User::class, $reviewer);
        $this->assessments()->publish($assessment, $reviewer, 'publish2');
        $assessment = $this->reloadAssessment($assessment->getId());
        self::assertSame(2, $assessment->getPublishedRevisionNumber());
        self::assertSame(2, $pubs->count(['assessment' => $assessment]));
    }

    public function testRejectsEmptySectionDuplicateGradeMismatchAndInvalidPoints(): void
    {
        [$sa, $reviewer, $subject, $program, $lo] = $this->platformCurriculum('arej');
        $this->programs()->publish($program, $sa, 'pub');
        $q = $this->createPublishedPlatformQuestion($sa, $reviewer, $subject, $lo, 'arej');
        /** @var QuestionRevisionRepository $qRevisions */
        $qRevisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $qr = $qRevisions->findForQuestionNumber($q, 1);
        self::assertInstanceOf(QuestionRevision::class, $qr);

        try {
            $this->assessments()->createDraftAssessment(
                $sa,
                AssessmentScope::Platform,
                null,
                AssessmentType::Quiz,
                GradeLevel::Grade9,
                'Bad',
                null,
                null,
                null,
                NavigationMode::Free,
                QuestionOrderMode::Fixed,
                OptionOrderMode::Fixed,
                ResultReleasePolicy::Immediate,
                null,
                [['title' => 'Empty', 'position' => 1, 'questionOrderMode' => QuestionOrderMode::Fixed, 'items' => []]],
                'empty_sec',
            );
            self::fail('empty section');
        } catch (AssessmentException $e) {
            self::assertSame(AssessmentFailureReason::EmptySection, $e->getReason());
        }
        $this->resetDoctrine();
        $sa = $this->users->find($sa->getId());
        $q = $this->em->find(Question::class, $q->getId());
        $qr = $this->em->find(QuestionRevision::class, $qr->getId());
        self::assertInstanceOf(User::class, $sa);
        self::assertInstanceOf(Question::class, $q);
        self::assertInstanceOf(QuestionRevision::class, $qr);

        try {
            $this->assessments()->createDraftAssessment(
                $sa,
                AssessmentScope::Platform,
                null,
                AssessmentType::Quiz,
                GradeLevel::Grade10,
                'Grade mismatch',
                null,
                null,
                null,
                NavigationMode::Free,
                QuestionOrderMode::Fixed,
                OptionOrderMode::Fixed,
                ResultReleasePolicy::Immediate,
                null,
                [$this->sectionWithItem($q, $qr)],
                'grade_bad',
            );
            self::fail('grade mismatch');
        } catch (AssessmentException $e) {
            self::assertSame(AssessmentFailureReason::GradeMismatch, $e->getReason());
        }
        $this->resetDoctrine();
        $sa = $this->users->find($sa->getId());
        $q = $this->em->find(Question::class, $q->getId());
        $qr = $this->em->find(QuestionRevision::class, $qr->getId());
        self::assertInstanceOf(User::class, $sa);
        self::assertInstanceOf(Question::class, $q);
        self::assertInstanceOf(QuestionRevision::class, $qr);

        try {
            $this->assessments()->createDraftAssessment(
                $sa,
                AssessmentScope::Platform,
                null,
                AssessmentType::Quiz,
                GradeLevel::Grade9,
                'Points',
                null,
                null,
                null,
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
                        'penaltyPoints' => '2.00',
                        'required' => true,
                    ]],
                ]],
                'pts_bad',
            );
            self::fail('penalty');
        } catch (AssessmentException $e) {
            self::assertSame(AssessmentFailureReason::InvalidPoints, $e->getReason());
        }
        $this->resetDoctrine();
        $sa = $this->users->find($sa->getId());
        $q = $this->em->find(Question::class, $q->getId());
        $qr = $this->em->find(QuestionRevision::class, $qr->getId());
        self::assertInstanceOf(User::class, $sa);
        self::assertInstanceOf(Question::class, $q);
        self::assertInstanceOf(QuestionRevision::class, $qr);

        $section = $this->sectionWithItem($q, $qr);
        $section['items'][] = [
            'questionId' => $q->getId(),
            'questionRevisionId' => $qr->getId(),
            'position' => 2,
            'points' => '1.00',
            'penaltyPoints' => '0.00',
            'required' => true,
        ];
        try {
            $this->assessments()->createDraftAssessment(
                $sa,
                AssessmentScope::Platform,
                null,
                AssessmentType::Quiz,
                GradeLevel::Grade9,
                'Dup',
                null,
                null,
                null,
                NavigationMode::Free,
                QuestionOrderMode::Fixed,
                OptionOrderMode::Fixed,
                ResultReleasePolicy::Immediate,
                null,
                [$section],
                'dup_q',
            );
            self::fail('duplicate');
        } catch (AssessmentException $e) {
            self::assertSame(AssessmentFailureReason::DuplicateQuestion, $e->getReason());
        }
    }

    public function testSequentialRevisionsMonotonicAndArchiveBlocksNewRevision(): void
    {
        [$sa, $reviewer, $subject, $program, $lo] = $this->platformCurriculum('amon');
        $this->programs()->publish($program, $sa, 'pub');
        $q = $this->createPublishedPlatformQuestion($sa, $reviewer, $subject, $lo, 'amon');
        /** @var QuestionRevisionRepository $qRevisions */
        $qRevisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $qr = $qRevisions->findForQuestionNumber($q, 1);
        self::assertInstanceOf(QuestionRevision::class, $qr);

        $assessment = $this->assessments()->createDraftAssessment(
            $sa,
            AssessmentScope::Platform,
            null,
            AssessmentType::PracticeTest,
            GradeLevel::Grade9,
            'Practice',
            null,
            null,
            120,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::AfterClose,
            null,
            [$this->sectionWithItem($q, $qr)],
            'create',
        );
        $this->assessments()->createRevision(
            $assessment,
            $sa,
            'Practice 2',
            null,
            null,
            120,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::AfterClose,
            null,
            [$this->sectionWithItem($q, $qr)],
            'rev2',
        );
        $assessment = $this->reloadAssessment($assessment->getId());
        self::assertSame(2, $assessment->getCurrentRevisionNumber());

        $this->assessments()->archive($assessment, $sa, 'arch');
        $assessment = $this->reloadAssessment($assessment->getId());
        self::assertSame(AssessmentStatus::Archived, $assessment->getStatus());
        try {
            $this->assessments()->createRevision(
                $assessment,
                $sa,
                'Practice 3',
                null,
                null,
                120,
                NavigationMode::Free,
                QuestionOrderMode::Fixed,
                OptionOrderMode::Fixed,
                ResultReleasePolicy::AfterClose,
                null,
                [$this->sectionWithItem($q, $qr)],
                'rev3',
            );
            self::fail('archived');
        } catch (AssessmentException $e) {
            self::assertSame(AssessmentFailureReason::InvalidTransition, $e->getReason());
        }
    }

    /**
     * @return array{
     *     title: string,
     *     position: int,
     *     questionOrderMode: QuestionOrderMode,
     *     items: list<array{
     *         questionId: \Symfony\Component\Uid\Uuid,
     *         questionRevisionId: \Symfony\Component\Uid\Uuid,
     *         position: int,
     *         points: string,
     *         penaltyPoints: string,
     *         required: bool
     *     }>
     * }
     */
    private function sectionWithItem(Question $question, QuestionRevision $revision, string $title = 'Section 1'): array
    {
        return [
            'title' => $title,
            'position' => 1,
            'questionOrderMode' => QuestionOrderMode::Fixed,
            'items' => [[
                'questionId' => $question->getId(),
                'questionRevisionId' => $revision->getId(),
                'position' => 1,
                'points' => '2.50',
                'penaltyPoints' => '0.50',
                'required' => true,
            ]],
        ];
    }

    private function createPublishedPlatformQuestion(
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
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('Q '.$suffix.'?'),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_b'],
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

    private function reloadAssessment(\Symfony\Component\Uid\Uuid $id): Assessment
    {
        $this->em->clear();
        $this->rebind();
        $a = $this->em->find(Assessment::class, $id);
        self::assertInstanceOf(Assessment::class, $a);

        return $a;
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
        $events = static::getContainer()->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);
        $this->events = $events;
    }

    private function resetDoctrine(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $doctrine->resetManager();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebind();
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
