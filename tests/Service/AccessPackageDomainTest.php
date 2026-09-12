<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AccessPackage;
use App\Entity\LearningContent;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AccessEntitlementFailureReason;
use App\Enum\AccessPackageStatus;
use App\Enum\AccessPackageTargetType;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\ResourceAccessClass;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\AccessEntitlementException;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\AccessPackageManager;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\LearningContentManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\AccessEntitlementDbCleanup;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

final class AccessPackageDomainTest extends KernelTestCase
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

    public function testCreateActivateRetireAndImmutableCode(): void
    {
        $sa = $this->superAdmin('ap-dom-sa@example.com');
        $expert = $this->activeUser('ap-dom-expert@example.com', UserRole::ExpertTeacher);

        $packages = $this->packages();
        $package = $packages->create(
            $expert,
            'starter_individual',
            'Starter Individual',
            'Desc',
            AccessPackageTargetType::Individual,
            30,
            null,
            'create_pkg',
        );
        self::assertSame(AccessPackageStatus::Draft, $package->getStatus());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AccessPackageCreated->value));

        try {
            $packages->create($expert, 'bad_seat_pkg', 'X', null, AccessPackageTargetType::Individual, 30, 5, 'bad_seat');
            self::fail('Individual seat limit should fail');
        } catch (AccessEntitlementException $e) {
            self::assertSame(AccessEntitlementFailureReason::InvalidInput, $e->getReason());
        }
        $this->resetDoctrine();
        $packages = $this->packages();
        $package = $this->em->find(AccessPackage::class, $package->getId());
        self::assertInstanceOf(AccessPackage::class, $package);
        $expert = $this->em->find(User::class, $expert->getId());
        self::assertInstanceOf(User::class, $expert);
        $sa = $this->em->find(User::class, $sa->getId());
        self::assertInstanceOf(User::class, $sa);

        try {
            $packages->activate($package, $expert, 'activate_denied');
            self::fail('Expert cannot activate');
        } catch (AccessEntitlementException $e) {
            self::assertSame(AccessEntitlementFailureReason::Unauthorized, $e->getReason());
        }
        $this->resetDoctrine();
        $packages = $this->packages();
        $package = $this->em->find(AccessPackage::class, $package->getId());
        self::assertInstanceOf(AccessPackage::class, $package);
        $sa = $this->em->find(User::class, $sa->getId());
        self::assertInstanceOf(User::class, $sa);

        $activated = $packages->activate($package, $sa, 'activate_ok');
        self::assertSame(AccessPackageStatus::Active, $activated->getStatus());

        $retired = $packages->retire($activated, $sa, 'retire_ok');
        self::assertSame(AccessPackageStatus::Retired, $retired->getStatus());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AccessPackageRetired->value));

        $serializer = static::getContainer()->get(SerializerInterface::class);
        self::assertInstanceOf(SerializerInterface::class, $serializer);
        $json = $serializer->serialize($retired, 'json');
        self::assertStringNotContainsString('createdBy', $json);
        self::assertStringContainsString('starter_individual', $json);
    }

    public function testResourceAccessPolicyDefaultsAndFree(): void
    {
        [$sa, $subject, $content] = $this->publishedPlatformContent('ap_pol');
        $packages = $this->packages();
        $policy = $packages->setLearningContentAccessPolicy(
            $content,
            $sa,
            ResourceAccessClass::EntitlementRequired,
            'set_policy',
        );
        self::assertSame(ResourceAccessClass::EntitlementRequired, $policy->getAccessClass());
        $free = $packages->setLearningContentAccessPolicy($content, $sa, ResourceAccessClass::Free, 'set_free');
        self::assertSame(ResourceAccessClass::Free, $free->getAccessClass());
        self::assertGreaterThanOrEqual(1, $this->events->countByAction(SecurityAuditAction::LearningContentAccessPolicySet->value));
        self::assertInstanceOf(Subject::class, $subject);
        self::assertInstanceOf(LearningContent::class, $content);
    }

    /**
     * @return array{0: User, 1: Subject, 2: LearningContent}
     */
    private function publishedPlatformContent(string $suffix): array
    {
        $sa = $this->superAdmin($suffix.'-sa@example.com');
        $reviewer = $this->activeUser($suffix.'-rev@example.com', UserRole::HeadTeacher);
        $subjects = $this->subjects();
        $programs = $this->programs();
        $units = static::getContainer()->get(CurriculumUnitManager::class);
        self::assertInstanceOf(CurriculumUnitManager::class, $units);
        $topics = static::getContainer()->get(CurriculumTopicManager::class);
        self::assertInstanceOf(CurriculumTopicManager::class, $topics);
        $outcomes = static::getContainer()->get(CurriculumLearningOutcomeManager::class);
        self::assertInstanceOf(CurriculumLearningOutcomeManager::class, $outcomes);
        $contents = static::getContainer()->get(LearningContentManager::class);
        self::assertInstanceOf(LearningContentManager::class, $contents);

        $subject = $subjects->create($sa, $suffix.'_sub', 'Sub '.$suffix, 'create_s');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade9, $suffix, 'P', '1.0', 'create_p');
        $unit = $units->create($program, $sa, 'u1', 'U', 1, 'create_u');
        $topic = $topics->createRoot($unit, $sa, 't1', 'T', 1, 'create_t');
        $lo = $outcomes->create($topic, $sa, $suffix.'_lo', 'Outcome', 1, 'create_lo');
        $programs->publish($program, $sa, 'publish_p');
        $content = $contents->createDraft(
            $sa,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            LearningContentType::TopicExplanation,
            $suffix.'_lc',
            'Title '.$suffix,
            null,
            LearningContentDocument::paragraph('Body'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'create_c',
        );
        $contents->submitForReview($content, $sa, 'submit');
        $this->em->clear();
        $content = $this->em->find(LearningContent::class, $content->getId());
        self::assertInstanceOf(LearningContent::class, $content);
        $contents->publish($content, $reviewer, 'publish_c');
        $this->em->clear();
        $content = $this->em->find(LearningContent::class, $content->getId());
        self::assertInstanceOf(LearningContent::class, $content);

        return [$sa, $subject, $content];
    }

    private function packages(): AccessPackageManager
    {
        $s = static::getContainer()->get(AccessPackageManager::class);
        self::assertInstanceOf(AccessPackageManager::class, $s);

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

    private function superAdmin(string $email): User
    {
        $user = $this->activeUser($email, UserRole::Teacher);
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
        AccessEntitlementDbCleanup::deleteAll($connection);
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
