<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Assessment;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\User;
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
use App\Question\Content\QuestionContentDocument;
use App\Repository\QuestionRevisionRepository;
use App\Repository\UserRepository;
use App\Security\AssessmentPermission;
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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

final class AssessmentVoterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private AccessDecisionManagerInterface $access;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $factory = $c->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->factory = $factory;
        $users = $c->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
        $access = $c->get(AccessDecisionManagerInterface::class);
        self::assertInstanceOf(AccessDecisionManagerInterface::class, $access);
        $this->access = $access;
        $this->cleanup();
    }

    public function testPlatformMatrixHeadPublishTeacherOwnDraftAdminDenied(): void
    {
        $sa = $this->superAdmin('av-sa@example.com');
        $head = $this->activeUser('av-head@example.com', UserRole::HeadTeacher);
        $teacher = $this->activeUser('av-teacher@example.com', UserRole::Teacher);
        $admin = $this->activeUser('av-admin@example.com', UserRole::Student);
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $student = $this->activeUser('av-student@example.com', UserRole::Student);

        $subjects = static::getContainer()->get(SubjectManager::class);
        self::assertInstanceOf(SubjectManager::class, $subjects);
        $programs = static::getContainer()->get(CurriculumProgramManager::class);
        self::assertInstanceOf(CurriculumProgramManager::class, $programs);
        $units = static::getContainer()->get(CurriculumUnitManager::class);
        self::assertInstanceOf(CurriculumUnitManager::class, $units);
        $topics = static::getContainer()->get(CurriculumTopicManager::class);
        self::assertInstanceOf(CurriculumTopicManager::class, $topics);
        $outcomes = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $outcomes);
        $questions = static::getContainer()->get(QuestionManager::class);
        self::assertInstanceOf(QuestionManager::class, $questions);
        $assessments = static::getContainer()->get(AssessmentManager::class);
        self::assertInstanceOf(AssessmentManager::class, $assessments);

        $subject = $subjects->create($sa, 'av_math', 'AV Math', 'create_s');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade9, 'av_math', 'Math', '1.0', 'create_p');
        $unit = $units->create($program, $sa, 'u1', 'U', 1, 'create_u');
        $topic = $topics->createRoot($unit, $sa, 't1', 'T', 1, 'create_t');
        $lo = $outcomes->create($topic, $sa, 'lo_av', 'Outcome', 1, 'create_lo');
        $programs->publish($program, $sa, 'publish');

        $question = $questions->createDraftQuestion(
            $head,
            QuestionScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            QuestionType::TrueFalse,
            QuestionContentDocument::paragraph('True?'),
            null,
            [],
            ['correct' => true],
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            QuestionDifficulty::Easy,
            'create_q',
        );
        $questions->submitForReview($question, $head, 'submit');
        $questions->publish($question, $sa, 'publish');
        $question = $this->em->find(Question::class, $question->getId());
        self::assertInstanceOf(Question::class, $question);
        /** @var QuestionRevisionRepository $qRevisions */
        $qRevisions = static::getContainer()->get(QuestionRevisionRepository::class);
        $qr = $qRevisions->findForQuestionNumber($question, 1);
        self::assertInstanceOf(QuestionRevision::class, $qr);

        $assessment = $assessments->createDraftAssessment(
            $teacher,
            AssessmentScope::Platform,
            null,
            AssessmentType::Quiz,
            GradeLevel::Grade9,
            'Teacher draft',
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
                    'questionId' => $question->getId(),
                    'questionRevisionId' => $qr->getId(),
                    'position' => 1,
                    'points' => '1.00',
                    'penaltyPoints' => '0.00',
                    'required' => true,
                ]],
            ]],
            'create_a',
        );

        self::assertTrue($this->decide($teacher, AssessmentPermission::REVISE, $assessment));
        self::assertTrue($this->decide($teacher, AssessmentPermission::SUBMIT, $assessment));
        self::assertFalse($this->decide($teacher, AssessmentPermission::PUBLISH, $assessment));
        self::assertTrue($this->decide($head, AssessmentPermission::PUBLISH, $assessment));
        self::assertFalse($this->decide($admin, AssessmentPermission::PUBLISH, $assessment));
        self::assertFalse($this->decide($student, AssessmentPermission::VIEW, $assessment));

        $assessments->submitForReview($assessment, $teacher, 'submit_a');
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);
        $assessments->publish($assessment, $head, 'publish_a');
        $assessment = $this->em->find(Assessment::class, $assessment->getId());
        self::assertInstanceOf(Assessment::class, $assessment);

        self::assertTrue($this->decide($student, AssessmentPermission::VIEW, $assessment));
        self::assertTrue($this->decide($teacher, AssessmentPermission::VIEW, $assessment));
        self::assertFalse($this->decide($admin, AssessmentPermission::PUBLISH, $assessment));
    }

    private function decide(User $user, string $attribute, Assessment $assessment): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return $this->access->decide($token, [$attribute], $assessment);
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
