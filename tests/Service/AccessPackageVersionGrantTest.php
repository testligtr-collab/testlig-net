<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AccessPackage;
use App\Entity\AccessPackageVersion;
use App\Entity\LearningContent;
use App\Entity\User;
use App\Enum\AccessEntitlementFailureReason;
use App\Enum\AccessPackageCatalogResourceKind;
use App\Enum\AccessPackageTargetType;
use App\Enum\AccessPackageVersionStatus;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\AccessEntitlementException;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\AccessPackageManager;
use App\Service\AccessPackageVersionManager;
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

final class AccessPackageVersionGrantTest extends KernelTestCase
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

    public function testDraftGrantsActivateSupersedeAndHash(): void
    {
        [$sa, $content] = $this->publishedContent('apv');
        $packages = $this->packages();
        $versions = $this->versions();

        $package = $packages->create(
            $sa,
            'grant_pkg',
            'Grant Package',
            null,
            AccessPackageTargetType::Individual,
            365,
            null,
            'create_pkg',
        );
        $v1 = $versions->createDraftVersion($package, $sa, 365, null, 'create_v1');
        self::assertSame(AccessPackageVersionStatus::Draft, $v1->getStatus());
        self::assertSame(1, $v1->getVersionNumber());

        $versions->addLearningContentGrant($v1, $content, $sa, 'add_lc');
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AccessPackageGrantAdded->value));

        $versions->addCatalogGrant(
            $v1,
            AccessPackageCatalogResourceKind::LearningContent,
            $content->getSubject(),
            GradeLevel::Grade9,
            $sa,
            'add_cat',
        );

        $activated = $versions->activate($v1, $sa, 'activate_v1');
        self::assertSame(AccessPackageVersionStatus::Active, $activated->getStatus());
        $this->em->clear();
        $package = $this->em->find(AccessPackage::class, $package->getId());
        self::assertInstanceOf(AccessPackage::class, $package);
        self::assertTrue($package->getStatus()->allowsNewLicense());

        $v2 = $versions->createDraftVersion($package, $sa, 180, null, 'create_v2');
        $versions->addLearningContentGrant($v2, $content, $sa, 'add_lc_v2');
        $activated2 = $versions->activate($v2, $sa, 'activate_v2');
        self::assertSame(AccessPackageVersionStatus::Active, $activated2->getStatus());
        $this->em->clear();
        $v1 = $this->em->find(AccessPackageVersion::class, $v1->getId());
        self::assertInstanceOf(AccessPackageVersion::class, $v1);
        self::assertSame(AccessPackageVersionStatus::Superseded, $v1->getStatus());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AccessPackageVersionSuperseded->value));
    }

    public function testAssessmentCatalogGrantRequiresNullSubject(): void
    {
        $sa = $this->superAdmin('apv-as-sa@example.com');
        $packages = $this->packages();
        $versions = $this->versions();
        $package = $packages->create(
            $sa,
            'assess_cat_pkg',
            'Assess Cat',
            null,
            AccessPackageTargetType::Individual,
            30,
            null,
            'create',
        );
        $v1 = $versions->createDraftVersion($package, $sa, 30, null, 'create_v');
        $grant = $versions->addCatalogGrant(
            $v1,
            AccessPackageCatalogResourceKind::Assessment,
            null,
            GradeLevel::Grade10,
            $sa,
            'add_as_cat',
        );
        self::assertNull($grant->getSubject());
        self::assertSame(AccessPackageCatalogResourceKind::Assessment, $grant->getResourceKind());
    }

    public function testActivateWithoutGrantsFails(): void
    {
        $sa = $this->superAdmin('apv-empty@example.com');
        $packages = $this->packages();
        $versions = $this->versions();
        $package = $packages->create($sa, 'empty_pkg', 'Empty', null, AccessPackageTargetType::Individual, 30, null, 'create_x');
        $v1 = $versions->createDraftVersion($package, $sa, 30, null, 'create_v');
        try {
            $versions->activate($v1, $sa, 'activate');
            self::fail('Expected grant invalid');
        } catch (AccessEntitlementException $e) {
            self::assertSame(AccessEntitlementFailureReason::GrantInvalid, $e->getReason());
        }
    }

    /** @return array{0: User, 1: LearningContent} */
    private function publishedContent(string $suffix): array
    {
        $sa = $this->superAdmin($suffix.'-sa@example.com');
        $reviewer = $this->activeUser($suffix.'-rev@example.com', UserRole::HeadTeacher);
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

        $subject = $subjects->create($sa, $suffix.'_sub', 'S', 'cs');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade9, $suffix, 'P', '1.0', 'cp');
        $unit = $units->create($program, $sa, 'u1', 'U', 1, 'cu');
        $topic = $topics->createRoot($unit, $sa, 't1', 'T', 1, 'ct');
        $lo = $outcomes->create($topic, $sa, $suffix.'_lo', 'O', 1, 'clo');
        $programs->publish($program, $sa, 'pp');
        $content = $contents->createDraft(
            $sa,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            LearningContentType::Document,
            $suffix.'_doc',
            'Doc',
            null,
            LearningContentDocument::paragraph('x'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'cc',
        );
        $contents->submitForReview($content, $sa, 'sub');
        $this->em->clear();
        $content = $this->em->find(LearningContent::class, $content->getId());
        self::assertInstanceOf(LearningContent::class, $content);
        $contents->publish($content, $reviewer, 'pub');
        $this->em->clear();
        $content = $this->em->find(LearningContent::class, $content->getId());
        self::assertInstanceOf(LearningContent::class, $content);
        $sa = $this->em->find(User::class, $sa->getId());
        self::assertInstanceOf(User::class, $sa);

        return [$sa, $content];
    }

    private function packages(): AccessPackageManager
    {
        $s = static::getContainer()->get(AccessPackageManager::class);
        self::assertInstanceOf(AccessPackageManager::class, $s);

        return $s;
    }

    private function versions(): AccessPackageVersionManager
    {
        $s = static::getContainer()->get(AccessPackageVersionManager::class);
        self::assertInstanceOf(AccessPackageVersionManager::class, $s);

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
