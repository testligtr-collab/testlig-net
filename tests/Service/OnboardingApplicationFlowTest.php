<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\RegistrationRequest;
use App\Enum\AccountType;
use App\Enum\InstitutionType;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\OnboardingApplicationException;
use App\Onboarding\OnboardingPendingOwnerScope;
use App\Repository\InstitutionApplicationRepository;
use App\Repository\InstitutionRepository;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\TeacherApplicationRepository;
use App\Repository\UserRepository;
use App\Service\InstitutionApplicationManager;
use App\Service\RegistrationService;
use App\Service\TeacherApplicationManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

final class OnboardingApplicationFlowTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private UserFactory $factory;

    private UserAccountLifecycle $lifecycle;

    private UserRepository $users;

    private RegistrationService $registration;

    private TeacherApplicationManager $teacherApps;

    private InstitutionApplicationManager $institutionApps;

    private TeacherApplicationRepository $teacherRepo;

    private InstitutionApplicationRepository $institutionRepo;

    private SecurityAuditEventRepository $events;

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

        $lifecycle = $c->get(UserAccountLifecycle::class);
        self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
        $this->lifecycle = $lifecycle;

        $users = $c->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $registration = $c->get(RegistrationService::class);
        self::assertInstanceOf(RegistrationService::class, $registration);
        $this->registration = $registration;

        $teacherApps = $c->get(TeacherApplicationManager::class);
        self::assertInstanceOf(TeacherApplicationManager::class, $teacherApps);
        $this->teacherApps = $teacherApps;

        $institutionApps = $c->get(InstitutionApplicationManager::class);
        self::assertInstanceOf(InstitutionApplicationManager::class, $institutionApps);
        $this->institutionApps = $institutionApps;

        $teacherRepo = $c->get(TeacherApplicationRepository::class);
        self::assertInstanceOf(TeacherApplicationRepository::class, $teacherRepo);
        $this->teacherRepo = $teacherRepo;

        $institutionRepo = $c->get(InstitutionApplicationRepository::class);
        self::assertInstanceOf(InstitutionApplicationRepository::class, $institutionRepo);
        $this->institutionRepo = $institutionRepo;

        $events = $c->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);
        $this->events = $events;

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            $this->cleanup();
        }
        parent::tearDown();
    }

    public function testStudentRegistrationStillAssignsOnlyStudentRole(): void
    {
        $dto = $this->registrationDto('student-onb@example.com');
        $user = $this->registration->register($dto);

        self::assertSame(UserStatus::PendingVerification, $user->getStatus());
        self::assertContains(UserRole::Student->value, $user->getRoles());
        self::assertNotContains(UserRole::Parent->value, $user->getRoles());
        self::assertNotContains(UserRole::Teacher->value, $user->getRoles());
        self::assertNotContains(UserRole::Admin->value, $user->getRoles());
        self::assertNull($user->getNormalizedPhone());
    }

    public function testParentRegistrationAssignsParentNotTeacherOrInstitutionManager(): void
    {
        $dto = $this->registrationDto('parent-onb@example.com');
        $dto->accountType = AccountType::Parent;
        $user = $this->registration->register($dto);

        self::assertContains(UserRole::Parent->value, $user->getRoles());
        self::assertNotContains(UserRole::Student->value, $user->getRoles());
        self::assertNotContains(UserRole::Teacher->value, $user->getRoles());
        self::assertNotContains(UserRole::InstitutionManager->value, $user->getRoles());
        self::assertNull($user->getPhone());
    }

    public function testTeacherApplicationDoesNotGrantTeacherRoleEvenWhenApproved(): void
    {
        $applicant = $this->activeUser('teacher-app@example.com', UserRole::Student);
        $sa = $this->superAdmin('sa-teacher-app@example.com');

        $application = $this->teacherApps->submit($applicant);
        self::assertSame(OnboardingApplicationStatus::Pending, $application->getStatus());
        self::assertSame($applicant->getId()->toRfc4122(), $application->getUser()->getId()->toRfc4122());

        $this->teacherApps->markApproved($sa, $application->getId(), 'manual_review_ok');
        $this->em->clear();

        $reloaded = $this->users->findOneById($applicant->getId());
        self::assertNotNull($reloaded);
        self::assertNotContains(UserRole::Teacher->value, $reloaded->getRoles());
        self::assertNotContains(UserRole::ExpertTeacher->value, $reloaded->getRoles());

        $approved = $this->teacherRepo->findOneById($application->getId());
        self::assertNotNull($approved);
        self::assertSame(OnboardingApplicationStatus::Approved, $approved->getStatus());
    }

    public function testApplicantCannotMutateSomeoneElsesTeacherApplication(): void
    {
        $owner = $this->activeUser('owner-app@example.com', UserRole::Student);
        $intruder = $this->activeUser('intruder-app@example.com', UserRole::Student);
        $application = $this->teacherApps->submit($owner);

        $this->expectException(OnboardingApplicationException::class);
        $this->teacherApps->withdraw($intruder, $application->getId());
    }

    public function testNonSuperAdminCannotApproveTeacherApplication(): void
    {
        $applicant = $this->activeUser('teacher-auth@example.com', UserRole::Student);
        $moderator = $this->activeUser('mod-auth@example.com', UserRole::Moderator);
        $application = $this->teacherApps->submit($applicant);

        $this->expectException(OnboardingApplicationException::class);
        $this->teacherApps->markApproved($moderator, $application->getId(), 'manual_review_ok');
    }

    public function testPendingOwnerUniqueConstraintRejectsSecondPendingRow(): void
    {
        $applicant = $this->activeUser('pending-uniq@example.com', UserRole::Student);
        $this->teacherApps->submit($applicant);

        $connection = $this->em->getConnection();

        $column = $connection->fetchAssociative(
            'SELECT COLUMN_NAME, GENERATION_EXPRESSION, IS_GENERATED, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            [OnboardingPendingOwnerScope::TEACHER_TABLE, OnboardingPendingOwnerScope::COLUMN_NAME],
        );
        self::assertIsArray($column);
        self::assertSame('ALWAYS', $column['IS_GENERATED']);
        self::assertStringContainsString('STORED GENERATED', (string) $column['EXTRA']);
        $expression = strtolower(preg_replace('/\s+/', '', (string) $column['GENERATION_EXPRESSION']) ?? '');
        self::assertSame(OnboardingPendingOwnerScope::normalizedGenerationExpression(), $expression);

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $dupId = (new UuidV7())->toBinary();
        $rejected = false;
        try {
            $connection->executeStatement(
                'INSERT INTO teacher_applications
                    (id, user_id, status, decision_reason_code, submitted_at, decided_at, created_at, updated_at)
                 VALUES (?, ?, ?, NULL, ?, NULL, ?, ?)',
                [
                    $dupId,
                    $applicant->getId()->toBinary(),
                    OnboardingApplicationStatus::Pending->value,
                    $now,
                    $now,
                    $now,
                ],
            );
        } catch (UniqueConstraintViolationException $exception) {
            $rejected = true;
            self::assertStringContainsString(
                OnboardingPendingOwnerScope::TEACHER_UNIQUE_INDEX,
                $exception->getMessage(),
            );
        }
        self::assertTrue($rejected, 'second pending row must hit uniq_teacher_app_one_pending');
    }

    public function testTeacherApplicationApproveRejectWithdrawAndResubmit(): void
    {
        $applicant = $this->activeUser('teacher-flow@example.com', UserRole::User);
        $sa = $this->superAdmin('sa-teacher-flow@example.com');

        $first = $this->teacherApps->submit($applicant);
        $this->teacherApps->markRejected($sa, $first->getId(), 'incomplete_profile');

        $this->em->clear();
        $rejected = $this->teacherRepo->findOneById($first->getId());
        self::assertNotNull($rejected);
        self::assertSame(OnboardingApplicationStatus::Rejected, $rejected->getStatus());

        $second = $this->teacherApps->submit($this->users->findOneById($applicant->getId()) ?? $applicant);
        self::assertSame(OnboardingApplicationStatus::Pending, $second->getStatus());

        $this->teacherApps->withdraw($this->users->findOneById($applicant->getId()) ?? $applicant, $second->getId());
        $third = $this->teacherApps->submit($this->users->findOneById($applicant->getId()) ?? $applicant);

        $this->teacherApps->markApproved($sa, $third->getId(), 'manual_review_ok');
        $this->em->clear();
        $reloaded = $this->users->findOneById($applicant->getId());
        self::assertNotNull($reloaded);
        self::assertNotContains(UserRole::Teacher->value, $reloaded->getRoles());

        $this->expectException(OnboardingApplicationException::class);
        $this->teacherApps->submit($reloaded);
    }

    public function testInstitutionApplicationDoesNotCreateInstitutionOrMembership(): void
    {
        $applicant = $this->activeUser('inst-app@example.com', UserRole::Student);
        $sa = $this->superAdmin('sa-inst-app@example.com');
        $institutions = static::getContainer()->get(InstitutionRepository::class);
        self::assertInstanceOf(InstitutionRepository::class, $institutions);

        $before = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM institutions');
        $membershipBefore = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM institution_memberships');

        $application = $this->institutionApps->submit($applicant, 'Demo Eğitim Kurumu', InstitutionType::School);
        self::assertSame(OnboardingApplicationStatus::Pending, $application->getStatus());
        self::assertSame('demo eğitim kurumu', $application->getProposedNormalizedName());

        $this->institutionApps->markApproved($sa, $application->getId());

        $this->em->clear();
        $after = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM institutions');
        $membershipAfter = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM institution_memberships');
        self::assertSame($before, $after);
        self::assertSame($membershipBefore, $membershipAfter);

        $reloadedUser = $this->users->findOneById($applicant->getId());
        self::assertNotNull($reloadedUser);
        self::assertNotContains(UserRole::InstitutionManager->value, $reloadedUser->getRoles());

        $approved = $this->institutionRepo->findOneById($application->getId());
        self::assertNotNull($approved);
        self::assertSame(OnboardingApplicationStatus::Approved, $approved->getStatus());
    }

    public function testUnverifiedUserCannotSubmitApplications(): void
    {
        $pending = $this->factory->createAndPersist('pending-app@example.com', 'Guclu-Parola-123!', 'Pen', 'Ding', UserRole::Student);
        $this->expectException(OnboardingApplicationException::class);
        $this->teacherApps->submit($pending);
    }

    public function testAuditMetadataHasNoSensitivePhoneFields(): void
    {
        $applicant = $this->activeUser('audit-app@example.com', UserRole::Student);
        $this->teacherApps->submit($applicant);

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT metadata FROM security_audit_events WHERE action = ?',
            [SecurityAuditAction::TeacherApplicationSubmitted->value],
        );
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            $meta = json_decode((string) $row['metadata'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($meta);
            self::assertArrayNotHasKey('email', $meta);
            self::assertArrayNotHasKey('phone', $meta);
            self::assertArrayNotHasKey('otp', $meta);
            self::assertArrayHasKey('application_id', $meta);
        }
    }

    private function registrationDto(string $email): RegistrationRequest
    {
        $dto = new RegistrationRequest();
        $dto->firstName = 'Ali';
        $dto->lastName = 'Yılmaz';
        $dto->email = $email;
        $dto->plainPassword = 'Guclu-Parola-123!';
        $dto->agreeTerms = true;

        return $dto;
    }

    private function activeUser(string $email, UserRole $role)
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'Onb', 'User', $role);
        $this->lifecycle->markEmailVerifiedAndActivate($user);

        return $user;
    }

    private function superAdmin(string $email)
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'Super', 'Admin', UserRole::Moderator);
        $user->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($user);
        $this->lifecycle->markEmailVerifiedAndActivate($user);

        return $user;
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        foreach ([
            'security_audit_events',
            'teacher_applications',
            'institution_applications',
            'institution_memberships',
            'institutions',
            'reset_password_requests',
            'phone_verification_claims',
            'users',
        ] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
    }
}
