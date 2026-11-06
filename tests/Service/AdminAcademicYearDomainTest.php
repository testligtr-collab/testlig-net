<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\AdminAcademicYearListItemView;
use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\ClassroomStudentEnrollment;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionStatus;
use App\Enum\InstitutionType;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\AcademicYearException;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\Admin\AdminAcademicYearQuery;
use App\Service\ClassroomManager;
use App\Service\ClassroomStudentEnrollmentManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AdminAcademicYearDomainTest extends KernelTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function testCreateStaysPlannedAndActivationClosesOnlyTheSameInstitution(): void
    {
        $actor = $this->user('ay_domain_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('ay_domain_owner@example.com', UserRole::Teacher);
        $student = $this->user('ay_domain_student@example.com', UserRole::Student);
        $home = $this->activateInstitution($actor, $owner, 'Ay Domain Home');
        $other = $this->activateInstitution($actor, $owner, 'Ay Domain Other');
        $manager = $this->years();

        $pending = $this->institution($actor, $owner, 'Ay Domain Pending');
        $beforeYears = $this->yearCount();
        $beforeAudits = $this->auditTotal();
        try {
            $manager->createPlanned($pending, $actor, 'Kapali Donem', new \DateTimeImmutable('2024-09-01'), new \DateTimeImmutable('2025-06-30'), 'institution_onboarding');
            self::fail('A pending institution accepted a year.');
        } catch (AcademicYearException $e) {
            self::assertSame('institution_not_operable', $e->getReason()->value);
        }
        $this->reboot();
        self::assertSame($beforeYears, $this->yearCount());
        self::assertSame($beforeAudits, $this->auditTotal());

        $actor = $this->reloadUser($actor->getId());
        $owner = $this->reloadUser($owner->getId());
        $student = $this->reloadUser($student->getId());
        $home = $this->reloadInstitution($home->getId());
        $other = $this->reloadInstitution($other->getId());
        $manager = $this->years();
        $first = $manager->createPlanned($home, $actor, 'Birinci Donem', new \DateTimeImmutable('2024-09-01'), new \DateTimeImmutable('2025-06-30'), 'institution_onboarding');
        self::assertSame(AcademicYearStatus::Planned, $first->getStatus());
        self::assertSame($home->getId()->toRfc4122(), $first->getInstitution()->getId()->toRfc4122());
        $manager->activate($first, $actor, 'lifecycle_change');
        $first = $this->reloadYear($first->getId());
        self::assertSame(AcademicYearStatus::Active, $first->getStatus());

        $membership = $this->memberships()->addMember($home, $actor, $student, InstitutionMembershipRole::Student, 'operator_setup');
        $classroom = $this->classrooms()->create($first, $actor, 'Ay Sinif', GradeLevel::Grade1, 'operator_setup', 'A', 24);
        $enrollment = $this->enrollments()->enroll($classroom, $actor, $membership, 'operator_setup');
        $classroomId = $classroom->getId();
        $enrollmentId = $enrollment->getId();

        $second = $manager->createPlanned($this->reloadInstitution($home->getId()), $this->reloadUser($actor->getId()), 'Ikinci Donem', new \DateTimeImmutable('2025-09-01'), new \DateTimeImmutable('2026-06-30'), 'operator_setup');
        self::assertSame(AcademicYearStatus::Planned, $second->getStatus());
        $foreign = $manager->createPlanned($this->reloadInstitution($other->getId()), $this->reloadUser($actor->getId()), 'Diger Donem', new \DateTimeImmutable('2024-09-01'), new \DateTimeImmutable('2025-06-30'), 'operator_setup');
        $manager->activate($foreign, $this->reloadUser($actor->getId()), 'lifecycle_change');
        $manager->activate($this->reloadYear($second->getId()), $this->reloadUser($actor->getId()), 'lifecycle_change');

        $first = $this->reloadYear($first->getId());
        $second = $this->reloadYear($second->getId());
        $foreign = $this->reloadYear($foreign->getId());
        self::assertSame(AcademicYearStatus::Closed, $first->getStatus());
        self::assertSame(AcademicYearStatus::Active, $second->getStatus());
        self::assertSame(AcademicYearStatus::Active, $foreign->getStatus());
        self::assertSame(1, $this->activeCount($home->getId()));
        self::assertSame(1, $this->guardCount($home->getId()));
        self::assertSame(1, $this->guardCount($other->getId()));

        $classroom = $this->em->find(Classroom::class, $classroomId);
        $enrollment = $this->em->find(ClassroomStudentEnrollment::class, $enrollmentId);
        self::assertInstanceOf(Classroom::class, $classroom);
        self::assertInstanceOf(ClassroomStudentEnrollment::class, $enrollment);
        self::assertTrue($classroom->getAcademicYear()->getId()->equals($first->getId()));
        self::assertTrue($enrollment->getAcademicYear()->getId()->equals($first->getId()));

        $blocked = $manager->createPlanned($this->reloadInstitution($home->getId()), $this->reloadUser($actor->getId()), 'Ucuncu Donem', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-30'), 'operator_setup');
        self::assertSame(AcademicYearStatus::Planned, $blocked->getStatus());
        $this->institutionStatus()->suspend($this->reloadInstitution($home->getId()), $this->reloadUser($actor->getId()), 'operator_correction');
        $audits = $this->auditCount(SecurityAuditAction::AcademicYearActivated->value, $blocked->getId()->toRfc4122());
        try {
            $manager->activate($this->reloadYear($blocked->getId()), $this->reloadUser($actor->getId()), 'lifecycle_change');
            self::fail('A suspended institution activated a year.');
        } catch (AcademicYearException $e) {
            self::assertSame('institution_not_operable', $e->getReason()->value);
        }
        $this->reboot();
        self::assertSame(AcademicYearStatus::Planned, $this->reloadYear($blocked->getId())->getStatus());
        self::assertSame($audits, $this->auditCount(SecurityAuditAction::AcademicYearActivated->value, $blocked->getId()->toRfc4122()));
        self::assertSame(AcademicYearStatus::Active, $this->reloadYear($foreign->getId())->getStatus());
    }

    public function testListQueryCountDoesNotGrowAndCountersIgnoreFilters(): void
    {
        $actor = $this->user('ay_query_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('ay_query_owner@example.com', UserRole::Teacher);
        $home = $this->activateInstitution($actor, $owner, 'Ay Query Home');
        $manager = $this->years();
        $manager->createPlanned($home, $actor, 'Ay Bound Bir', new \DateTimeImmutable('2024-09-01'), new \DateTimeImmutable('2025-06-30'), 'institution_onboarding');

        $counter = new class extends AbstractLogger {
            public int $count = 0;

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ++$this->count;
            }
        };
        $connection = $this->em->getConnection();
        $configuration = $connection->getConfiguration();
        $configuration->setMiddlewares(array_merge(
            [new LoggingMiddleware($counter)],
            $configuration->getMiddlewares(),
        ));
        $connection->close();

        $query = $this->query();
        $actorId = $actor->getId();
        $query->listYears($actorId, ['q' => 'Ay Bound']);
        $before = $counter->count;
        $one = $query->listYears($actorId, ['q' => 'Ay Bound', 'page_size' => 1]);
        $oneQueries = $counter->count - $before;
        self::assertCount(1, $one->items);
        self::assertSame(1, $one->totalPages());

        $summary = $query->summarize($actorId);
        $manager->createPlanned($this->reloadInstitution($home->getId()), $this->reloadUser($actor->getId()), 'Ay Bound Iki', new \DateTimeImmutable('2025-09-01'), new \DateTimeImmutable('2026-06-30'), 'operator_setup');
        $manager->createPlanned($this->reloadInstitution($home->getId()), $this->reloadUser($actor->getId()), 'Ay Bound Uc', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-30'), 'operator_setup');
        $quiet = $this->activateInstitution($this->reloadUser($actor->getId()), $this->reloadUser($owner->getId()), 'Ay Query Quiet');
        $pending = $this->institution($this->reloadUser($actor->getId()), $this->reloadUser($owner->getId()), 'Ay Query Pending');
        self::assertSame(InstitutionStatus::Pending, $pending->getStatus());
        self::assertSame(InstitutionStatus::Active, $quiet->getStatus());

        $before = $counter->count;
        $three = $query->listYears($actorId, ['q' => 'Ay Bound', 'page_size' => 1]);
        $threeQueries = $counter->count - $before;
        $filtered = $query->summarize($actorId);
        self::assertSame($oneQueries, $threeQueries);
        self::assertLessThan(12, $threeQueries);
        self::assertSame(3, $three->totalCount);
        self::assertSame(3, $three->totalPages());
        self::assertCount(1, $three->items);
        $listed = $three->items[0] ?? null;
        self::assertInstanceOf(AdminAcademicYearListItemView::class, $listed);
        self::assertSame(
            [$summary->total + 2, $summary->active, $summary->activeInstitutionsWithoutActiveYear + 1, $summary->linkedInstitutions],
            [$filtered->total, $filtered->active, $filtered->activeInstitutionsWithoutActiveYear, $filtered->linkedInstitutions],
        );

        $planned = $query->listYears($actorId, ['q' => 'Ay Bound', 'status' => 'planned']);
        self::assertSame(3, $planned->totalCount);
        $missing = $query->listYears($actorId, ['q' => 'Ay Bound Olmayan']);
        self::assertSame(0, $missing->totalCount);
        self::assertSame($filtered->total, $query->summarize($actorId)->total);
    }

    private function reboot(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    private function years(): AcademicYearManager
    {
        $service = static::getContainer()->get(AcademicYearManager::class);
        self::assertInstanceOf(AcademicYearManager::class, $service);

        return $service;
    }

    private function classrooms(): ClassroomManager
    {
        $service = static::getContainer()->get(ClassroomManager::class);
        self::assertInstanceOf(ClassroomManager::class, $service);

        return $service;
    }

    private function enrollments(): ClassroomStudentEnrollmentManager
    {
        $service = static::getContainer()->get(ClassroomStudentEnrollmentManager::class);
        self::assertInstanceOf(ClassroomStudentEnrollmentManager::class, $service);

        return $service;
    }

    private function memberships(): InstitutionMembershipManager
    {
        $service = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $service);

        return $service;
    }

    private function institutionStatus(): InstitutionStatusManager
    {
        $service = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $service);

        return $service;
    }

    private function query(): AdminAcademicYearQuery
    {
        $service = static::getContainer()->get(AdminAcademicYearQuery::class);
        self::assertInstanceOf(AdminAcademicYearQuery::class, $service);

        return $service;
    }

    private function user(string $email, UserRole $role): User
    {
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, 'Ad', 'Soyad', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $users->save($user);

        return $user;
    }

    private function institution(User $actor, User $owner, string $name): Institution
    {
        $creator = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $creator);

        return $creator->create($actor, $owner, $name, InstitutionType::School, 'platform_setup');
    }

    private function activateInstitution(User $actor, User $owner, string $name): Institution
    {
        $institution = $this->institution($actor, $owner, $name);
        $this->institutionStatus()->activate($institution, $actor, 'operator_setup');

        return $institution;
    }

    private function reloadUser(Uuid $id): User
    {
        $this->em->clear();
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $users->findOneById($id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function reloadInstitution(Uuid $id): Institution
    {
        $this->em->clear();
        $institution = $this->em->find(Institution::class, $id);
        self::assertInstanceOf(Institution::class, $institution);

        return $institution;
    }

    private function reloadYear(Uuid $id): AcademicYear
    {
        $this->em->clear();
        $year = $this->em->find(AcademicYear::class, $id);
        self::assertInstanceOf(AcademicYear::class, $year);

        return $year;
    }

    private function yearCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM academic_years');
    }

    private function activeCount(Uuid $institutionId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM academic_years WHERE institution_id = ? AND status = ?',
            [$institutionId->toBinary(), AcademicYearStatus::Active->value],
        );
    }

    private function guardCount(Uuid $institutionId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM institution_active_academic_year_guards WHERE institution_id = ?',
            [$institutionId->toBinary()],
        );
    }

    private function auditTotal(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM security_audit_events');
    }

    private function auditCount(string $action, string $yearId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM security_audit_events WHERE action = ? AND metadata LIKE ?',
            [$action, '%'.$yearId.'%'],
        );
    }
}
