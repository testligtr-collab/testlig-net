<?php

declare(strict_types=1);

namespace App\Tests\Access;

use App\Entity\AccessLicense;
use App\Entity\AccessPackageVersion;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\LearningContent;
use App\Entity\User;
use App\Enum\AccessLicenseSourceType;
use App\Enum\AccessPackageTargetType;
use App\Enum\EntitlementAccessDecisionReason;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
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
use App\Service\InstitutionCreator;
use App\Service\InstitutionLicenseSeatManager;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\LearningContentManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\AccessEntitlementDbCleanup;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Fresh DBAL authorization + access-time hash recomputation regressions.
 * Intentionally does NOT clear()/detach() — managed entities stay stale after DBAL updates.
 */
final class EntitlementAccessGateFreshIntegrityTest extends KernelTestCase
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

    public function testLicenseSnapshotHashTamperDeniesIntegrityFailed(): void
    {
        [$sa, $content, $student, $license] = $this->licensedIndividual('hash_snap');
        self::assertTrue($this->gate()->evaluateLearningContent($content->getId(), $student)->granted);

        $this->em->getConnection()->executeStatement(
            'UPDATE access_licenses SET policy_snapshot_hash = ? WHERE id = ?',
            [str_repeat('b', 64), $license->getId()->toBinary()],
        );
        self::assertSame('active', $license->getStatus()->value);

        $decision = $this->gate()->evaluateLearningContent($content->getId(), $student);
        self::assertFalse($decision->granted);
        self::assertSame(EntitlementAccessDecisionReason::IntegrityFailed, $decision->reason);
    }

    public function testGrantGraphTamperWithMatchingStoredHashesDeniesIntegrityFailed(): void
    {
        [$sa, $content, $student, $license, $version] = $this->licensedIndividual('hash_graph');
        $extra = $this->publishExtraContent($sa, 'hash_graph_extra');

        // Controlled fixture: flip active→draft (allowed), insert extra grant, flip back to active
        // without recomputing stored hashes — both stored hashes remain equal and stale.
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "UPDATE access_package_versions
             SET status = 'draft', activated_at = NULL, activated_by_id = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = ?",
            [$version->getId()->toBinary()],
        );
        $conn->executeStatement(
            'INSERT INTO access_package_learning_content_grants (id, version_id, content_id, created_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())',
            [(new UuidV7())->toBinary(), $version->getId()->toBinary(), $extra->getId()->toBinary()],
        );
        $conn->executeStatement(
            "UPDATE access_package_versions
             SET status = 'active',
                 activated_at = UTC_TIMESTAMP(),
                 activated_by_id = ?,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = ?",
            [$sa->getId()->toBinary(), $version->getId()->toBinary()],
        );

        self::assertSame($license->getPolicySnapshotHash(), $version->getPolicyHash());

        $decision = $this->gate()->evaluateLearningContent($content->getId(), $student);
        self::assertFalse($decision->granted);
        self::assertSame(EntitlementAccessDecisionReason::IntegrityFailed, $decision->reason);
    }

    public function testStaleActiveLicenseSuspendedInDbDenies(): void
    {
        [$sa, $content, $student, $license] = $this->licensedIndividual('stale_susp');
        self::assertTrue($this->gate()->evaluateLearningContent($content->getId(), $student)->granted);

        $this->em->getConnection()->executeStatement(
            "UPDATE access_licenses SET status = 'suspended', suspended_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?",
            [$license->getId()->toBinary()],
        );
        self::assertSame('active', $license->getStatus()->value);

        $decision = $this->gate()->evaluateLearningContent($content->getId(), $student);
        self::assertFalse($decision->granted);
        self::assertSame(EntitlementAccessDecisionReason::LicenseSuspended, $decision->reason);
    }

    public function testStaleActiveLicenseRevokedInDbDenies(): void
    {
        [$sa, $content, $student, $license] = $this->licensedIndividual('stale_rev');
        $this->em->getConnection()->executeStatement(
            "UPDATE access_licenses
             SET status = 'revoked', revoked_at = UTC_TIMESTAMP(), revoked_by_id = ?, revocation_reason_code = 'dbal', updated_at = UTC_TIMESTAMP()
             WHERE id = ?",
            [$sa->getId()->toBinary(), $license->getId()->toBinary()],
        );
        self::assertSame('active', $license->getStatus()->value);

        $decision = $this->gate()->evaluateLearningContent($content->getId(), $student);
        self::assertFalse($decision->granted);
        self::assertSame(EntitlementAccessDecisionReason::LicenseRevoked, $decision->reason);
    }

    public function testStaleSeatRevokedInDbDenies(): void
    {
        [$sa, $content, $student, $seatId] = $this->institutionSeatAccess('stale_seat');
        self::assertTrue($this->gate()->evaluateLearningContent($content->getId(), $student)->granted);

        $this->em->getConnection()->executeStatement(
            "UPDATE institution_license_seats
             SET status = 'revoked', revoked_at = UTC_TIMESTAMP(), revoked_by_id = assigned_by_id, revocation_reason_code = 'dbal'
             WHERE id = ?",
            [Uuid::fromString($seatId)->toBinary()],
        );

        $decision = $this->gate()->evaluateLearningContent($content->getId(), $student);
        self::assertFalse($decision->granted);
        self::assertContains(
            $decision->reason,
            [EntitlementAccessDecisionReason::EntitlementRequired, EntitlementAccessDecisionReason::SeatRevoked],
        );
    }

    public function testStaleMembershipEndedDenies(): void
    {
        [$sa, $content, $student, $seatId, $membership] = $this->institutionSeatAccess('stale_mem');
        $this->em->getConnection()->executeStatement(
            "UPDATE institution_memberships SET status = 'ended', ended_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?",
            [$membership->getId()->toBinary()],
        );
        self::assertSame('active', $membership->getStatus()->value);

        $decision = $this->gate()->evaluateLearningContent($content->getId(), $student);
        self::assertFalse($decision->granted);
        self::assertSame(EntitlementAccessDecisionReason::MembershipNotActive, $decision->reason);
    }

    public function testStaleInstitutionSuspendedDenies(): void
    {
        [$sa, $content, $student, $seatId, $membership, $institution] = $this->institutionSeatAccess('stale_inst');
        $this->em->getConnection()->executeStatement(
            "UPDATE institutions SET status = 'suspended', updated_at = UTC_TIMESTAMP() WHERE id = ?",
            [$institution->getId()->toBinary()],
        );
        self::assertSame('active', $institution->getStatus()->value);

        $decision = $this->gate()->evaluateLearningContent($content->getId(), $student);
        self::assertFalse($decision->granted);
        self::assertSame(EntitlementAccessDecisionReason::InstitutionNotActive, $decision->reason);
    }

    public function testAdminDoesNotAutoAllowEntitlementRequired(): void
    {
        [$sa, $content] = $this->publishedContentWithPolicy('admin_deny', ResourceAccessClass::EntitlementRequired);
        $admin = $this->activeUser('admin_deny@example.com', UserRole::Teacher);
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $decision = $this->gate()->evaluateLearningContent($content->getId(), $admin);
        self::assertFalse($decision->granted);
        self::assertSame(EntitlementAccessDecisionReason::EntitlementRequired, $decision->reason);
    }

    public function testOutOfScopeResourceDeniedWithValidLicense(): void
    {
        [$sa, $content, $student] = $this->publishedContentWithPolicy('scope_a', ResourceAccessClass::EntitlementRequired);
        $other = $this->publishExtraContent($sa, 'scope_b');
        $packages = $this->packages();
        $versions = $this->versions();
        $licenses = $this->licenses();
        $package = $packages->create($sa, 'scope_pkg', 'Pkg', null, AccessPackageTargetType::Individual, 30, null, 'create_pkg');
        $version = $versions->createDraftVersion($package, $sa, 30, null, 'create_version');
        $versions->addLearningContentGrant($version, $content, $sa, 'add_grant');
        $version = $versions->activate($version, $sa, 'activate_version');
        $license = $licenses->createUserLicense(
            $version,
            $student,
            $sa,
            AccessLicenseSourceType::Manual,
            new \DateTimeImmutable('-1 day'),
            new \DateTimeImmutable('+30 days'),
            'create_license',
        );
        $licenses->activate($license, $sa, 'activate_license');

        $decision = $this->gate()->evaluateLearningContent($other->getId(), $student);
        self::assertFalse($decision->granted);
        self::assertSame(EntitlementAccessDecisionReason::EntitlementRequired, $decision->reason);
    }

    /**
     * @return array{0: User, 1: LearningContent, 2: User, 3: AccessLicense, 4: AccessPackageVersion}
     */
    private function licensedIndividual(string $suffix): array
    {
        [$sa, $content, $student] = $this->publishedContentWithPolicy($suffix, ResourceAccessClass::EntitlementRequired);
        $packages = $this->packages();
        $versions = $this->versions();
        $licenses = $this->licenses();
        $package = $packages->create($sa, $suffix.'_pkg', 'Pkg', null, AccessPackageTargetType::Individual, 30, null, 'create_pkg');
        $version = $versions->createDraftVersion($package, $sa, 30, null, 'create_version');
        $versions->addLearningContentGrant($version, $content, $sa, 'add_grant');
        $version = $versions->activate($version, $sa, 'activate_version');
        $license = $licenses->createUserLicense(
            $version,
            $student,
            $sa,
            AccessLicenseSourceType::Manual,
            new \DateTimeImmutable('-1 day'),
            new \DateTimeImmutable('+30 days'),
            'create_license',
        );
        $license = $licenses->activate($license, $sa, 'activate_license');

        return [$sa, $content, $student, $license, $version];
    }

    /**
     * @return array{0: User, 1: LearningContent, 2: User, 3: string, 4: InstitutionMembership, 5: Institution}
     */
    private function institutionSeatAccess(string $suffix): array
    {
        [$sa, $content, $student] = $this->publishedContentWithPolicy($suffix, ResourceAccessClass::EntitlementRequired);
        $owner = $this->activeUser($suffix.'-owner@example.com');
        $creator = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $creator);
        $statusManager = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $statusManager);
        $memberships = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $memberships);

        $institution = $creator->create($sa, $owner, $suffix.' School', InstitutionType::School, 'platform_setup');
        $statusManager->activate($institution, $sa, 'activate_inst');
        $membership = $memberships->addMember(
            $institution,
            $owner,
            $student,
            InstitutionMembershipRole::Student,
            'add_stu',
        );

        $packages = $this->packages();
        $versions = $this->versions();
        $licenses = $this->licenses();
        $seats = static::getContainer()->get(InstitutionLicenseSeatManager::class);
        self::assertInstanceOf(InstitutionLicenseSeatManager::class, $seats);

        $package = $packages->create($sa, $suffix.'_ipkg', 'IPkg', null, AccessPackageTargetType::Institution, 30, 10, 'create_pkg');
        $version = $versions->createDraftVersion($package, $sa, 30, 10, 'create_version');
        $versions->addLearningContentGrant($version, $content, $sa, 'add_grant');
        $version = $versions->activate($version, $sa, 'activate_version');
        $license = $licenses->createInstitutionLicense(
            $version,
            $institution,
            $sa,
            AccessLicenseSourceType::InstitutionContract,
            new \DateTimeImmutable('-1 day'),
            new \DateTimeImmutable('+30 days'),
            10,
            'create_license',
        );
        $license = $licenses->activate($license, $sa, 'activate_license');
        $seat = $seats->assign($license, $membership, $owner, 'assign_seat');

        return [$sa, $content, $student, $seat->getId()->toRfc4122(), $membership, $institution];
    }

    private function publishExtraContent(User $sa, string $suffix): LearningContent
    {
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
        $packages = $this->packages();

        $uniq = bin2hex(random_bytes(3));
        $subject = $subjects->create($sa, $suffix.'_s_'.$uniq, 'Subject '.$uniq, 'create_subject');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade9, $suffix.$uniq, 'P', '1.0', 'create_program');
        $unit = $units->create($program, $sa, 'u1', 'U', 1, 'create_unit');
        $topic = $topics->createRoot($unit, $sa, 't1', 'T', 1, 'create_topic');
        $lo = $outcomes->create($topic, $sa, $suffix.'_lo_'.$uniq, 'O', 1, 'create_outcome');
        $programs->publish($program, $sa, 'publish_program');
        $content = $contents->createDraft(
            $sa,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            LearningContentType::Interactive,
            $suffix.'_lc_'.$uniq,
            'Title '.$uniq,
            null,
            LearningContentDocument::paragraph('b'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'create_content',
        );
        $contents->submitForReview($content, $sa, 'submit_review');
        $this->em->clear();
        $content = $this->em->find(LearningContent::class, $content->getId());
        self::assertInstanceOf(LearningContent::class, $content);
        $sa = $this->em->find(User::class, $sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $reviewer = $this->em->find(User::class, $reviewer->getId());
        self::assertInstanceOf(User::class, $reviewer);
        $contents->publish($content, $reviewer, 'publish_content');
        $this->em->clear();
        $content = $this->em->find(LearningContent::class, $content->getId());
        self::assertInstanceOf(LearningContent::class, $content);
        $sa = $this->em->find(User::class, $sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $packages->setLearningContentAccessPolicy($content, $sa, ResourceAccessClass::EntitlementRequired, 'set_policy');

        return $content;
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
        $packages = $this->packages();

        $uniq = bin2hex(random_bytes(3));
        $subject = $subjects->create($sa, $suffix.'_s_'.$uniq, 'Subject '.$uniq, 'create_subject');
        $program = $programs->createDraft($subject, $sa, GradeLevel::Grade9, $suffix.$uniq, 'P', '1.0', 'create_program');
        $unit = $units->create($program, $sa, 'u1', 'U', 1, 'create_unit');
        $topic = $topics->createRoot($unit, $sa, 't1', 'T', 1, 'create_topic');
        $lo = $outcomes->create($topic, $sa, $suffix.'_lo_'.$uniq, 'O', 1, 'create_outcome');
        $programs->publish($program, $sa, 'publish_program');
        $content = $contents->createDraft(
            $sa,
            LearningContentScope::Platform,
            null,
            $subject,
            GradeLevel::Grade9,
            LearningContentType::Interactive,
            $suffix.'_lc_'.$uniq,
            'Title '.$uniq,
            null,
            LearningContentDocument::paragraph('b'),
            [['learningOutcome' => $lo, 'isPrimary' => true]],
            'create_content',
        );
        $contents->submitForReview($content, $sa, 'submit_review');
        $this->em->clear();
        $content = $this->em->find(LearningContent::class, $content->getId());
        self::assertInstanceOf(LearningContent::class, $content);
        $reviewerReloaded = $this->em->find(User::class, $reviewer->getId());
        self::assertInstanceOf(User::class, $reviewerReloaded);
        $contents->publish($content, $reviewerReloaded, 'publish_content');
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
