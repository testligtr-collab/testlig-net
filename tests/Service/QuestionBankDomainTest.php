<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\CurriculumTopic;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumContentStatus;
use App\Enum\GradeLevel;
use App\Enum\InstitutionType;
use App\Enum\LearningOutcomeFailureReason;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionFailureReason;
use App\Enum\QuestionScope;
use App\Enum\QuestionStatus;
use App\Enum\QuestionType;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\LearningOutcomeException;
use App\Exception\QuestionException;
use App\Question\Answer\QuestionTypeAnswerValidator;
use App\Question\Content\QuestionContentDocument;
use App\Question\Content\QuestionContentValidator;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\QuestionAnswerKeyRepository;
use App\Repository\QuestionRevisionRepository;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionStatusManager;
use App\Service\QuestionManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\UuidV7;

final class QuestionBankDomainTest extends KernelTestCase
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

    public function testLearningOutcomeDraftOnlyUniqueAndClone(): void
    {
        $sa = $this->superAdmin('lo-sa@example.com');
        $subject = $this->subjects()->create($sa, 'physics', 'Physics', 'create_phys');
        $draft = $this->programs()->createDraft($subject, $sa, GradeLevel::Grade9, 'phys_9', 'Physics 9', '1.0', 'create_prog');
        $unit = $this->units()->create($draft, $sa, 'u1', 'Unit 1', 1, 'create_unit');
        $topic = $this->topics()->createRoot($unit, $sa, 't1', 'Topic 1', 1, 'create_topic');

        $lo = $this->outcomes()->create($topic, $sa, 'lo_1', 'Explain motion', 1, 'create_lo');
        self::assertSame(CurriculumContentStatus::Active, $lo->getStatus());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::CurriculumLearningOutcomeCreated->value));

        try {
            $this->outcomes()->create($topic, $sa, 'lo_1', 'Dup code', 2, 'dup_code');
            self::fail('code unique per program');
        } catch (LearningOutcomeException $e) {
            self::assertSame(LearningOutcomeFailureReason::Conflict, $e->getReason());
        }
        $this->resetDoctrine();
        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $topic = $this->em->find(CurriculumTopic::class, $topic->getId());
        self::assertInstanceOf(CurriculumTopic::class, $topic);
        $draft = $this->em->find(CurriculumProgram::class, $draft->getId());
        self::assertInstanceOf(CurriculumProgram::class, $draft);

        try {
            $this->outcomes()->create($topic, $sa, 'lo_2', 'Dup pos', 1, 'dup_pos');
            self::fail('position unique per topic');
        } catch (LearningOutcomeException $e) {
            self::assertSame(LearningOutcomeFailureReason::Conflict, $e->getReason());
        }
        $this->resetDoctrine();
        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $topic = $this->em->find(CurriculumTopic::class, $topic->getId());
        self::assertInstanceOf(CurriculumTopic::class, $topic);
        $draft = $this->em->find(CurriculumProgram::class, $draft->getId());
        self::assertInstanceOf(CurriculumProgram::class, $draft);
        $lo = $this->em->find(CurriculumLearningOutcome::class, $lo->getId());
        self::assertInstanceOf(CurriculumLearningOutcome::class, $lo);

        $this->programs()->publish($draft, $sa, 'publish');
        try {
            $this->outcomes()->create($topic, $sa, 'lo_x', 'Blocked', 3, 'blocked');
            self::fail('published immutable');
        } catch (LearningOutcomeException $e) {
            self::assertSame(LearningOutcomeFailureReason::ProgramNotDraft, $e->getReason());
        }
        $this->resetDoctrine();
        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $draft = $this->em->find(CurriculumProgram::class, $draft->getId());
        self::assertInstanceOf(CurriculumProgram::class, $draft);
        $lo = $this->em->find(CurriculumLearningOutcome::class, $lo->getId());
        self::assertInstanceOf(CurriculumLearningOutcome::class, $lo);

        $clone = $this->programs()->cloneAsNewVersion($draft, $sa, '2.0', 'clone');
        /** @var CurriculumLearningOutcomeRepository $repo */
        $repo = static::getContainer()->get(CurriculumLearningOutcomeRepository::class);
        $clonedOutcomes = $repo->findByProgram($clone);
        self::assertCount(1, $clonedOutcomes);
        self::assertNotSame($lo->getId()->toRfc4122(), $clonedOutcomes[0]->getId()->toRfc4122());
        self::assertSame('lo_1', $clonedOutcomes[0]->getCode());
    }

    public function testQuestionLifecycleReviewSeparationSerializerAndImmutability(): void
    {
        [$sa, $reviewer, $subject, $program, $lo] = $this->platformCurriculum('qlife');

        $question = $this->questions()->createDraftQuestion(
            $sa,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('What is 2+2?'),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('Three'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('Four'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_b'],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'create_q',
        );
        self::assertSame(QuestionStatus::Draft, $question->getStatus());
        self::assertSame(1, $question->getCurrentRevisionNumber());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::QuestionCreated->value));

        $this->questions()->submitForReview($question, $sa, 'submit');
        $question = $this->reloadQuestion($question->getId());
        self::assertSame(QuestionStatus::InReview, $question->getStatus());

        try {
            $this->questions()->publish($question, $sa, 'self_publish');
            self::fail('review separation');
        } catch (QuestionException $e) {
            self::assertSame(QuestionFailureReason::ReviewSeparation, $e->getReason());
        }
        $this->resetDoctrine();
        $sa = $this->users->find($sa->getId());
        $reviewer = $this->users->find($reviewer->getId());
        $program = $this->em->find(CurriculumProgram::class, $program->getId());
        $question = $this->em->find(Question::class, $question->getId());
        self::assertInstanceOf(User::class, $sa);
        self::assertInstanceOf(User::class, $reviewer);
        self::assertInstanceOf(CurriculumProgram::class, $program);
        self::assertInstanceOf(Question::class, $question);

        $this->programs()->publish($program, $sa, 'pub_curr');
        $this->questions()->publish($question, $reviewer, 'publish_ok');
        $question = $this->reloadQuestion($question->getId());
        self::assertSame(QuestionStatus::Published, $question->getStatus());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::QuestionPublished->value));

        /** @var QuestionRevisionRepository $revisions */
        $revisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $revision = $revisions->findForQuestionNumber($question, 1);
        self::assertInstanceOf(QuestionRevision::class, $revision);

        /** @var SerializerInterface $serializer */
        $serializer = static::getContainer()->get(SerializerInterface::class);
        $json = $serializer->serialize($question, 'json');
        self::assertStringNotContainsString('correctStableKey', $json);
        self::assertStringNotContainsString('answerPayload', $json);
        $jsonRev = $serializer->serialize($revision, 'json');
        self::assertStringNotContainsString('correctStableKey', $jsonRev);

        /** @var QuestionAnswerKeyRepository $keys */
        $keys = static::getContainer()->get(QuestionAnswerKeyRepository::class);
        $key = $keys->findOneByRevision($revision);
        self::assertNotNull($key);
        self::assertSame('opt_b', $key->getAnswerPayload()['correctStableKey']);

        try {
            $this->em->remove($revision);
            $this->em->flush();
            self::fail('immutable delete');
        } catch (QuestionException $e) {
            self::assertSame(QuestionFailureReason::Immutable, $e->getReason());
            $this->resetDoctrine();
        }

        $question = $this->reloadQuestion($question->getId());
        /** @var QuestionRevisionRepository $revisions */
        $revisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $revision = $revisions->findForQuestionNumber($question, 1);
        self::assertInstanceOf(QuestionRevision::class, $revision);
        $listener = new \App\Doctrine\QuestionRevisionImmutabilityListener();
        $changeSet = ['contentHash' => [str_repeat('a', 64), str_repeat('b', 64)]];
        try {
            $listener->preUpdate(new \Doctrine\ORM\Event\PreUpdateEventArgs(
                $revision,
                $this->em,
                $changeSet,
            ));
            self::fail('immutable update');
        } catch (QuestionException $e) {
            self::assertSame(QuestionFailureReason::Immutable, $e->getReason());
        }

        $loId = $lo->getId();
        $sa = $this->users->find($sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $lo = $this->em->find(CurriculumLearningOutcome::class, $loId);
        self::assertInstanceOf(CurriculumLearningOutcome::class, $lo);

        $rev2 = $this->questions()->createRevision(
            $question,
            $sa,
            QuestionType::SingleChoice,
            QuestionContentDocument::paragraph('What is 3+3?'),
            null,
            [
                ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('Five'), 'position' => 1],
                ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('Six'), 'position' => 2],
            ],
            ['correctStableKey' => 'opt_b'],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Medium,
            'rev2',
        );
        $question = $this->reloadQuestion($question->getId());
        self::assertSame(2, $question->getCurrentRevisionNumber());
        self::assertSame(QuestionStatus::Draft, $question->getStatus());
        self::assertSame(2, $rev2->getRevisionNumber());
    }

    public function testAnswerPoliciesAndContentSecurity(): void
    {
        /** @var QuestionTypeAnswerValidator $answers */
        $answers = static::getContainer()->get(QuestionTypeAnswerValidator::class);
        /** @var QuestionContentValidator $content */
        $content = static::getContainer()->get(QuestionContentValidator::class);

        try {
            $content->validate(QuestionContentDocument::fromArray([
                'schemaVersion' => 1,
                'blocks' => [['type' => 'paragraph', 'text' => '<script>alert(1)</script>']],
            ]));
            self::fail('html blocked');
        } catch (QuestionException $e) {
            self::assertSame(QuestionFailureReason::ContentInvalid, $e->getReason());
        }

        $opts = [
            ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('A'), 'position' => 1],
            ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('B'), 'position' => 2],
        ];
        $single = $answers->validateAndNormalize(QuestionType::SingleChoice, $opts, ['correctStableKey' => 'opt_a']);
        self::assertSame('opt_a', $single['answerPayload']['correctStableKey']);

        try {
            $answers->validateAndNormalize(QuestionType::MultipleChoice, $opts, [
                'correctStableKeys' => ['opt_a', 'opt_b'],
            ]);
            self::fail('all correct forbidden');
        } catch (QuestionException $e) {
            self::assertSame(QuestionFailureReason::AnswerInvalid, $e->getReason());
        }

        $tf = $answers->validateAndNormalize(QuestionType::TrueFalse, [], ['correct' => true]);
        self::assertTrue($tf['answerPayload']['correct']);

        $num = $answers->validateAndNormalize(QuestionType::Numeric, [], ['value' => '3.1400', 'tolerance' => '0.01']);
        self::assertSame('3.14', $num['answerPayload']['value']);

        try {
            $answers->validateAndNormalize(QuestionType::Numeric, [], ['value' => '1', 'tolerance' => '-1']);
            self::fail('neg tolerance');
        } catch (QuestionException $e) {
            self::assertSame(QuestionFailureReason::AnswerInvalid, $e->getReason());
        }

        $short = $answers->validateAndNormalize(QuestionType::ShortAnswer, [], [
            'acceptedAnswers' => ['  Ankara  ', 'Ankara'],
            'caseSensitive' => false,
        ]);
        self::assertCount(1, $short['answerPayload']['acceptedAnswers']);
    }

    public function testInstitutionScopeCheckAndCrossTenant(): void
    {
        $sa = $this->superAdmin('inst-q-sa@example.com');
        $ownerA = $this->activeUser('owner-a@example.com', UserRole::InstitutionManager);
        $ownerB = $this->activeUser('owner-b@example.com', UserRole::InstitutionManager);
        $instA = $this->institutions()->create($sa, $ownerA, 'School A', InstitutionType::School, 'create_a');
        $instB = $this->institutions()->create($sa, $ownerB, 'School B', InstitutionType::School, 'create_b');
        $statusManager = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $statusManager);
        $statusManager->activate($instA, $sa, 'act_a');
        $statusManager->activate($instB, $sa, 'act_b');

        $subject = $this->subjects()->create($sa, 'chem', 'Chemistry', 'create_chem');
        $draft = $this->programs()->createDraft($subject, $sa, GradeLevel::Grade10, 'chem_10', 'Chem 10', '1.0', 'prog');
        $unit = $this->units()->create($draft, $sa, 'u1', 'Unit', 1, 'create_u');
        $topic = $this->topics()->createRoot($unit, $sa, 't1', 'Topic', 1, 'create_t');
        $lo = $this->outcomes()->create($topic, $sa, 'lo_chem', 'Bonding', 1, 'create_lo');
        $this->programs()->publish($draft, $sa, 'pub');

        $q = $this->questions()->createDraftQuestion(
            $ownerA,
            QuestionScope::Institution,
            $instA,
            $subject,
            GradeLevel::Grade10,
            QuestionType::TrueFalse,
            QuestionContentDocument::paragraph('Is water polar?'),
            null,
            [],
            ['correct' => true],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Medium,
            'create_inst_q',
        );
        self::assertSame(QuestionScope::Institution, $q->getScope());

        try {
            $this->questions()->createRevision(
                $q,
                $ownerB,
                QuestionType::TrueFalse,
                QuestionContentDocument::paragraph('Hijack'),
                null,
                [],
                ['correct' => false],
                [['learningOutcome' => $lo, 'isPrimary' => true]],
                QuestionDifficulty::Medium,
                'cross_tenant',
            );
            self::fail('cross tenant denied');
        } catch (QuestionException $e) {
            self::assertSame(QuestionFailureReason::Unauthorized, $e->getReason());
        }
        $this->resetDoctrine();

        try {
            $this->em->getConnection()->insert('questions', [
                'id' => (new UuidV7())->toBinary(),
                'scope' => 'platform',
                'institution_id' => $instA->getId()->toBinary(),
                'subject_id' => $subject->getId()->toBinary(),
                'grade_level' => 10,
                'created_by_id' => $sa->getId()->toBinary(),
                'status' => 'draft',
                'current_revision_number' => 1,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            self::fail('scope check must reject');
        } catch (\Throwable $e) {
            self::assertNotSame('', $e->getMessage());
        }
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

    private function reloadQuestion(\Symfony\Component\Uid\Uuid $id): Question
    {
        $this->em->clear();
        $this->rebind();
        $q = $this->em->find(Question::class, $id);
        self::assertInstanceOf(Question::class, $q);

        return $q;
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

    private function institutions(): InstitutionCreator
    {
        $s = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $s);

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

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        foreach ([
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
        ] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
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
