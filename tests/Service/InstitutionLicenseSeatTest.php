<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AccessLicense;
use App\Entity\Institution;
use App\Entity\InstitutionLicenseSeat;
use App\Entity\InstitutionMembership;
use App\Entity\LearningContent;
use App\Entity\User;
use App\Enum\AccessEntitlementFailureReason;
use App\Enum\AccessLicenseSourceType;
use App\Enum\AccessPackageTargetType;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
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
use App\Service\InstitutionCreator;
use App\Service\InstitutionLicenseSeatManager;
use App\Service\InstitutionMembershipManager;
use App\Service\LearningContentManager;
use App\Service\SubjectManager;
use App\Service\UserFactory;
use App\Tests\Support\AccessEntitlementDbCleanup;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InstitutionLicenseSeatTest extends KernelTestCase
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

    public function testAssignRevokeSeatLimitAndNoReactivate(): void
    {
        [$sa, $owner, $institution, $license, $studentMembership] = $this->activeInstitutionLicense('ils');
        $seats = $this->seats();

        $seat = $seats->assign($license, $studentMembership, $owner, 'assign_seat');
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::InstitutionLicenseSeatAssigned->value));

        try {
            $seats->assign($license, $studentMembership, $owner, 'dup');
            self::fail('Duplicate active seat');
        } catch (AccessEntitlementException $e) {
            self::assertSame(AccessEntitlementFailureReason::Conflict, $e->getReason());
        }

        $this->resetDoctrine();
        $seats = $this->seats();
        $seat = $this->em->find(InstitutionLicenseSeat::class, $seat->getId());
        self::assertInstanceOf(InstitutionLicenseSeat::class, $seat);
        $owner = $this->em->find(User::class, $owner->getId());
        self::assertInstanceOf(User::class, $owner);
        $license = $this->em->find(AccessLicense::class, $license->getId());
        self::assertInstanceOf(AccessLicense::class, $license);
        $studentMembership = $this->em->find(InstitutionMembership::class, $studentMembership->getId());
        self::assertInstanceOf(InstitutionMembership::class, $studentMembership);
        $institution = $this->em->find(Institution::class, $institution->getId());
        self::assertInstanceOf(Institution::class, $institution);
        $sa = $this->em->find(User::class, $sa->getId());
        self::assertInstanceOf(User::class, $sa);

        $revoked = $seats->revoke($seat, $owner, 'revoke_seat');
        self::assertNotNull($revoked->getRevokedAt());

        try {
            $revoked->revoke($owner, 'again', new \DateTimeImmutable('now'));
            self::fail('Cannot revoke twice / reactivate');
        } catch (AccessEntitlementException $e) {
            self::assertSame(AccessEntitlementFailureReason::InvalidTransition, $e->getReason());
        }

        $seat2 = $seats->assign($license, $studentMembership, $owner, 'reassign');
        self::assertNotSame($seat->getId()->toRfc4122(), $seat2->getId()->toRfc4122());

        // Seat limit 1: after second active, exceed
        $student2 = $this->activeUser('ils-s2@example.com');
        $memberships = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $memberships);
        $m2 = $memberships->addMember($institution, $owner, $student2, InstitutionMembershipRole::Student, 'add_s2');
        try {
            $seats->assign($license, $m2, $owner, 'over_limit');
            self::fail('Seat limit');
        } catch (AccessEntitlementException $e) {
            self::assertSame(AccessEntitlementFailureReason::SeatLimitExceeded, $e->getReason());
        }

        $this->resetDoctrine();
        $seats = $this->seats();
        $licenses = static::getContainer()->get(AccessLicenseManager::class);
        self::assertInstanceOf(AccessLicenseManager::class, $licenses);
        $license = $this->em->find(AccessLicense::class, $license->getId());
        self::assertInstanceOf(AccessLicense::class, $license);
        $institution = $this->em->find(Institution::class, $institution->getId());
        self::assertInstanceOf(Institution::class, $institution);
        $sa = $this->em->find(User::class, $sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $owner = $this->em->find(User::class, $owner->getId());
        self::assertInstanceOf(User::class, $owner);
        $m2 = $this->em->find(InstitutionMembership::class, $m2->getId());
        self::assertInstanceOf(InstitutionMembership::class, $m2);

        $pending = $licenses->createInstitutionLicense(
            $license->getPackageVersion(),
            $institution,
            $sa,
            AccessLicenseSourceType::Manual,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2027-01-01 00:00:00'),
            2,
            'pending_lic',
        );
        try {
            $seats->assign($pending, $m2, $owner, 'pending_seat');
            self::fail('Pending cannot seat');
        } catch (AccessEntitlementException $e) {
            self::assertSame(AccessEntitlementFailureReason::InvalidTransition, $e->getReason());
        }
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

    /**
     * @return array{0: User, 1: User, 2: Institution, 3: AccessLicense, 4: InstitutionMembership}
     */
    private function activeInstitutionLicense(string $suffix): array
    {
        $sa = $this->superAdmin($suffix.'-sa@example.com');
        $ownerUser = $this->activeUser($suffix.'-owner@example.com', UserRole::Teacher);
        $institutions = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $institutions);
        $institution = $institutions->create($sa, $ownerUser, 'School '.$suffix, InstitutionType::School, 'create_i');
        $statusManager = static::getContainer()->get(\App\Service\InstitutionStatusManager::class);
        self::assertInstanceOf(\App\Service\InstitutionStatusManager::class, $statusManager);
        $statusManager->activate($institution, $sa, 'activate_inst');
        $this->em->clear();
        $institution = $this->em->find(Institution::class, $institution->getId());
        self::assertInstanceOf(Institution::class, $institution);
        $ownerUser = $this->em->find(User::class, $ownerUser->getId());
        self::assertInstanceOf(User::class, $ownerUser);
        $sa = $this->em->find(User::class, $sa->getId());
        self::assertInstanceOf(User::class, $sa);
        $owner = $this->em->getRepository(InstitutionMembership::class)->findOneBy([
            'institution' => $institution,
            'user' => $ownerUser,
        ]);
        self::assertInstanceOf(InstitutionMembership::class, $owner);

        $student = $this->activeUser($suffix.'-stu@example.com');
        $memberships = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $memberships);
        $studentMembership = $memberships->addMember($institution, $ownerUser, $student, InstitutionMembershipRole::Student, 'add_stu');

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
        $licenses = static::getContainer()->get(AccessLicenseManager::class);
        self::assertInstanceOf(AccessLicenseManager::class, $licenses);

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
            LearningContentType::Worksheet,
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
        $ownerUser = $this->em->find(User::class, $ownerUser->getId());
        self::assertInstanceOf(User::class, $ownerUser);
        $institution = $this->em->find(Institution::class, $institution->getId());
        self::assertInstanceOf(Institution::class, $institution);
        $studentMembership = $this->em->find(InstitutionMembership::class, $studentMembership->getId());
        self::assertInstanceOf(InstitutionMembership::class, $studentMembership);

        $package = $packages->create($sa, $suffix.'_pkg', 'Pkg', null, AccessPackageTargetType::Institution, 365, 1, 'create_x');
        $version = $versions->createDraftVersion($package, $sa, 365, 1, 'create_v');
        $versions->addLearningContentGrant($version, $content, $sa, 'add_grant');
        $version = $versions->activate($version, $sa, 'activate_x');
        $license = $licenses->createInstitutionLicense(
            $version,
            $institution,
            $ownerUser,
            AccessLicenseSourceType::InstitutionContract,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2027-01-01 00:00:00'),
            1,
            'create_lic',
        );
        $license = $licenses->activate($license, $ownerUser, 'act_lic');

        return [$sa, $ownerUser, $institution, $license, $studentMembership];
    }

    private function seats(): InstitutionLicenseSeatManager
    {
        $s = static::getContainer()->get(InstitutionLicenseSeatManager::class);
        self::assertInstanceOf(InstitutionLicenseSeatManager::class, $s);

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
