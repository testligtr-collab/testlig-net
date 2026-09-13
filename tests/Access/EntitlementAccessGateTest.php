<?php

declare(strict_types=1);

namespace App\Tests\Access;

use App\Entity\LearningContent;
use App\Entity\User;
use App\Enum\AccessLicenseSourceType;
use App\Enum\AccessPackageTargetType;
use App\Enum\EntitlementAccessDecisionReason;
use App\Enum\EntitlementGrantSource;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\ResourceAccessClass;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\UserRepository;
use App\Service\AccessLicenseManager;
use App\Service\AccessPackageManager;
use App\Service\AccessPackageVersionManager;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use App\Service\EntitlementAccessGate;
use App\Service\LearningContentManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\AccessEntitlementDbCleanup;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EntitlementAccessGateTest extends KernelTestCase
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

    public function testFreePolicyAllowsWithoutLicense(): void
    {
        [$sa, $content, $student] = $this->publishedContentWithPolicy('eag_free', ResourceAccessClass::Free);
        $gate = $this->gate();
        $decision = $gate->evaluateLearningContent($content->getId(), $student);
        self::assertTrue($decision->granted);
        self::assertSame(EntitlementGrantSource::FreePublication, $decision->grantSource);
        self::assertSame(EntitlementAccessDecisionReason::Allowed, $decision->reason);
        self::assertStringNotContainsString('license', strtolower($decision->publicMessage()));
    }

    public function testIndividualLicenseAllows(): void
    {
        [$sa, $content, $student] = $this->publishedContentWithPolicy('eag_lic', ResourceAccessClass::EntitlementRequired);
        $packages = static::getContainer()->get(AccessPackageManager::class);
        self::assertInstanceOf(AccessPackageManager::class, $packages);
        $versions = static::getContainer()->get(AccessPackageVersionManager::class);
        self::assertInstanceOf(AccessPackageVersionManager::class, $versions);
        $licenses = static::getContainer()->get(AccessLicenseManager::class);
        self::assertInstanceOf(AccessLicenseManager::class, $licenses);

        $package = $packages->create($sa, 'eag_pkg', 'Pkg', null, AccessPackageTargetType::Individual, 30, null, 'create_x');
        $version = $versions->createDraftVersion($package, $sa, 30, null, 'create_v');
        $versions->addLearningContentGrant($version, $content, $sa, 'add_grant');
        $version = $versions->activate($version, $sa, 'activate_x');
        $license = $licenses->createUserLicense(
            $version,
            $student,
            $sa,
            AccessLicenseSourceType::Manual,
            new \DateTimeImmutable('-1 day'),
            new \DateTimeImmutable('+30 days'),
            'lic',
        );
        $licenses->activate($license, $sa, 'activate_lic');

        $decision = $this->gate()->evaluateLearningContent($content->getId(), $student);
        self::assertTrue($decision->granted);
        self::assertSame(EntitlementGrantSource::IndividualLicense, $decision->grantSource);
        self::assertNotNull($decision->licenseId);
    }

    public function testEntitlementRequiredWithoutLicense(): void
    {
        [$sa, $content, $student] = $this->publishedContentWithPolicy('eag_deny', ResourceAccessClass::EntitlementRequired);
        $decision = $this->gate()->evaluateLearningContent($content->getId(), $student);
        self::assertFalse($decision->granted);
        self::assertSame(EntitlementAccessDecisionReason::EntitlementRequired, $decision->reason);
    }

    /**
     * @return array{0: User, 1: LearningContent, 2: User}
     */
    private function publishedContentWithPolicy(string $suffix, ResourceAccessClass $accessClass): array
    {
        $sa = $this->superAdmin($suffix.'-sa@example.com');
        $reviewer = $this->activeUser($suffix.'-rev@example.com', UserRole::HeadTeacher);
        $student = $this->activeUser($suffix.'-stu@example.com');
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
            LearningContentType::Interactive,
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
        $student = $this->em->find(User::class, $student->getId());
        self::assertInstanceOf(User::class, $student);
        $packages->setLearningContentAccessPolicy($content, $sa, $accessClass, 'set_policy');

        return [$sa, $content, $student];
    }

    private function gate(): EntitlementAccessGate
    {
        $s = static::getContainer()->get(EntitlementAccessGate::class);
        self::assertInstanceOf(EntitlementAccessGate::class, $s);

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
