<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AccessPackageVersion;
use App\Entity\LearningContent;
use App\Entity\User;
use App\Enum\AccessEntitlementFailureReason;
use App\Enum\AccessLicenseSourceType;
use App\Enum\AccessLicenseStatus;
use App\Enum\AccessPackageTargetType;
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
use App\Service\AccessLicenseManager;
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
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

final class AccessLicenseDomainTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private SecurityAuditEventRepository $events;
    private MockClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebind();
        $this->clock = new MockClock('2026-09-12 12:00:00');
        Clock::set($this->clock);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        Clock::set(new \Symfony\Component\Clock\NativeClock());
        try {
            $this->cleanup();
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }

    public function testUserLicenseLifecycleAndLazyExpire(): void
    {
        [$sa, $version] = $this->activeIndividualVersion('alic');
        $licenses = $this->licenses();
        $licensee = $this->activeUser('alic-user@example.com', UserRole::Student);

        $from = new \DateTimeImmutable('2026-09-01 00:00:00');
        $until = new \DateTimeImmutable('2026-09-20 00:00:00');
        $license = $licenses->createUserLicense(
            $version,
            $licensee,
            $sa,
            AccessLicenseSourceType::Manual,
            $from,
            $until,
            'create_lic',
        );
        self::assertSame(AccessLicenseStatus::Pending, $license->getStatus());
        $license = $licenses->activate($license, $sa, 'activate_lic');
        self::assertSame(AccessLicenseStatus::Active, $license->getStatus());
        $license = $licenses->suspend($license, $sa, 'suspend_lic');
        self::assertSame(AccessLicenseStatus::Suspended, $license->getStatus());
        $license = $licenses->reactivate($license, $sa, 'reactivate_lic');
        self::assertSame(AccessLicenseStatus::Active, $license->getStatus());

        $this->clock->modify('2026-09-20 00:00:00');
        $expired = $licenses->evaluateAndExpireIfNeeded($license);
        self::assertSame(AccessLicenseStatus::Expired, $expired->getStatus());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AccessLicenseExpired->value));
    }

    public function testRetiredPackageCannotCreateLicense(): void
    {
        [$sa, $version] = $this->activeIndividualVersion('alic2');
        $packages = static::getContainer()->get(AccessPackageManager::class);
        self::assertInstanceOf(AccessPackageManager::class, $packages);
        $package = $version->getPackage();
        $packages->retire($package, $sa, 'retire');
        $this->em->clear();
        $version = $this->em->find(AccessPackageVersion::class, $version->getId());
        self::assertInstanceOf(AccessPackageVersion::class, $version);
        $sa = $this->em->find(User::class, $sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $licensee = $this->activeUser('alic2-u@example.com');
        try {
            $this->licenses()->createUserLicense(
                $version,
                $licensee,
                $sa,
                AccessLicenseSourceType::Manual,
                new \DateTimeImmutable('2026-09-01 00:00:00'),
                new \DateTimeImmutable('2026-10-01 00:00:00'),
                'create',
            );
            self::fail('Expected package retired');
        } catch (AccessEntitlementException $e) {
            self::assertSame(AccessEntitlementFailureReason::PackageRetired, $e->getReason());
        }
    }

    /** @return array{0: User, 1: AccessPackageVersion} */
    private function activeIndividualVersion(string $suffix): array
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
        $packages = static::getContainer()->get(AccessPackageManager::class);
        self::assertInstanceOf(AccessPackageManager::class, $packages);
        $versions = static::getContainer()->get(AccessPackageVersionManager::class);
        self::assertInstanceOf(AccessPackageVersionManager::class, $versions);

        $subject = $subjects->create($sa, $suffix.'_s', 'S', 'create_x');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade9, $suffix, 'P', '1.0', 'create_x');
        $unit = $units->create($program, $sa, 'u1', 'U', 1, 'create_x');
        $topic = $topics->createRoot($unit, $sa, 't1', 'T', 1, 'create_x');
        $lo = $outcomes->create($topic, $sa, $suffix.'_lo', 'O', 1, 'create_x');
        $programs->publish($program, $sa, 'publish_x');
        $content = $contents->createDraft(
            $sa,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            LearningContentType::Audio,
            $suffix.'_lc',
            'T',
            null,
            LearningContentDocument::paragraph('b'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'create_content',
        );
        $contents->submitForReview($content, $sa, 'submit_x');
        $this->em->clear();
        $content = $this->em->find(LearningContent::class, $content->getId());
        self::assertInstanceOf(LearningContent::class, $content);
        $contents->publish($content, $reviewer, 'publish_x');
        $this->em->clear();
        $content = $this->em->find(LearningContent::class, $content->getId());
        self::assertInstanceOf(LearningContent::class, $content);
        $sa = $this->em->find(User::class, $sa->getId());
        self::assertInstanceOf(User::class, $sa);

        $package = $packages->create($sa, $suffix.'_pkg', 'Pkg', null, AccessPackageTargetType::Individual, 30, null, 'create_x');
        $version = $versions->createDraftVersion($package, $sa, 30, null, 'create_v');
        $versions->addLearningContentGrant($version, $content, $sa, 'add_grant');
        $version = $versions->activate($version, $sa, 'activate_x');

        return [$sa, $version];
    }

    private function licenses(): AccessLicenseManager
    {
        $s = static::getContainer()->get(AccessLicenseManager::class);
        self::assertInstanceOf(AccessLicenseManager::class, $s);

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
}
