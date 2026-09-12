<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\UserRepository;
use App\Security\LearningContentPermission;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\LearningContentManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

final class LearningContentVoterTest extends KernelTestCase
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

    public function testPlatformMatrixHeadPublishTeacherManageOwnDraft(): void
    {
        $sa = $this->superAdmin('lcv-sa@example.com');
        $head = $this->activeUser('lcv-head@example.com', UserRole::HeadTeacher);
        $teacher = $this->activeUser('lcv-teacher@example.com', UserRole::Teacher);
        $admin = $this->activeUser('lcv-admin@example.com', UserRole::Student);
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $student = $this->activeUser('lcv-student@example.com', UserRole::Student);

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
        $contents = static::getContainer()->get(LearningContentManager::class);
        self::assertInstanceOf(LearningContentManager::class, $contents);

        $subject = $subjects->create($sa, 'lcv_math', 'LCV Math', 'create_s');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade9, 'lcv_math', 'Math', '1.0', 'create_p');
        $unit = $units->create($program, $sa, 'u1', 'U', 1, 'create_u');
        $topic = $topics->createRoot($unit, $sa, 't1', 'T', 1, 'create_t');
        $lo = $outcomes->create($topic, $sa, 'lo_lcv', 'Outcome', 1, 'create_lo');
        $programs->publish($program, $sa, 'publish');

        $content = $contents->createDraft(
            $teacher,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            LearningContentType::TopicExplanation,
            'lcv_topic',
            'Voter Topic',
            null,
            LearningContentDocument::paragraph('Body'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'create_c',
        );

        self::assertTrue($this->decide($teacher, LearningContentPermission::MANAGE, $content));
        self::assertFalse($this->decide($teacher, LearningContentPermission::PUBLISH, $content));
        self::assertTrue($this->decide($head, LearningContentPermission::PUBLISH, $content));
        self::assertFalse($this->decide($admin, LearningContentPermission::PUBLISH, $content));
        self::assertFalse($this->decide($student, LearningContentPermission::VIEW_METADATA, $content));

        $contents->submitForReview($content, $teacher, 'submit');
        $contents->publish($content, $head, 'publish');
        $this->em->refresh($content);

        self::assertTrue($this->decide($student, LearningContentPermission::VIEW_METADATA, $content));
        self::assertTrue($this->decide($head, LearningContentPermission::ARCHIVE, $content));
    }

    private function decide(\App\Entity\User $user, string $attribute, mixed $subject): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return $this->access->decide($token, [$attribute], $subject);
    }

    private function superAdmin(string $email): \App\Entity\User
    {
        $user = $this->activeUser($email, UserRole::Student);
        $user->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($user);

        return $user;
    }

    private function activeUser(string $email, UserRole $role = UserRole::Student): \App\Entity\User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', $role);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($connection, [
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
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
