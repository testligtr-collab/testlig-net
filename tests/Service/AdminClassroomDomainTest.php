<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\AdminClassroomListItemView;
use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\ClassroomStatus;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionStatus;
use App\Enum\InstitutionType;
use App\Enum\SecurityAuditAction;
use App\Enum\TeacherAssignmentRole;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\ClassroomException;
use App\Exception\CommerceException;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\Admin\AdminClassroomQuery;
use App\Service\ClassroomManager;
use App\Service\ClassroomStudentEnrollmentManager;
use App\Service\ClassroomTeacherAssignmentManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AdminClassroomDomainTest extends KernelTestCase
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

    public function testCreateDoesNotGrantAccessAndWritesAudit(): void
    {
        $actor = $this->user('cr_domain_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('cr_domain_owner@example.com', UserRole::Teacher);
        $home = $this->activateInstitution($actor, $owner, 'Cr Domain Home');
        $year = $this->years()->createPlanned($home, $actor, 'Cr Donem', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-15'), 'operator_setup');
        $ownerRoles = $owner->getRoles();
        $memberships = $this->countTable('institution_memberships');
        $enrollments = $this->countTable('classroom_student_enrollments');
        $assignments = $this->countTable('classroom_teacher_assignments');
        $audits = $this->auditTotal();

        $classroom = $this->classrooms()->create($year, $actor, 'Cr Sinif', GradeLevel::Grade5, 'operator_setup', '5-A', 24);
        self::assertSame(ClassroomStatus::Active, $classroom->getStatus());
        self::assertSame(GradeLevel::Grade5, $classroom->getGradeLevel());
        self::assertSame('5-A', $classroom->getSectionCode());
        self::assertSame($home->getId()->toRfc4122(), $classroom->getInstitution()->getId()->toRfc4122());
        self::assertSame($year->getId()->toRfc4122(), $classroom->getAcademicYear()->getId()->toRfc4122());
        self::assertSame($memberships, $this->countTable('institution_memberships'));
        self::assertSame($enrollments, $this->countTable('classroom_student_enrollments'));
        self::assertSame($assignments, $this->countTable('classroom_teacher_assignments'));
        self::assertSame($ownerRoles, $this->reloadUser($owner->getId())->getRoles());
        self::assertSame($audits + 1, $this->auditTotal());
        self::assertSame(1, $this->auditCount(SecurityAuditAction::ClassroomCreated->value, $classroom->getId()->toRfc4122()));
    }

    public function testFailedWritesLeaveNoPartialClassroomOrAudit(): void
    {
        $actor = $this->user('cr_fail_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('cr_fail_owner@example.com', UserRole::Teacher);
        $home = $this->activateInstitution($actor, $owner, 'Cr Fail Home');
        $years = $this->years();
        $planned = $years->createPlanned($home, $actor, 'Cr Fail Planned', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-15'), 'operator_setup');
        $years->close($planned, $actor, 'lifecycle_change');
        $classrooms = $this->countTable('classrooms');
        $audits = $this->auditTotal();

        try {
            $this->classrooms()->create($this->reloadYear($planned->getId()), $this->reloadUser($actor->getId()), 'Kapali Yil Sinifi', GradeLevel::Grade5, 'operator_setup');
            self::fail('A closed year accepted a classroom.');
        } catch (ClassroomException $e) {
            self::assertSame('year_not_operable', $e->getReason()->value);
        }
        $this->reboot();
        self::assertSame($classrooms, $this->countTable('classrooms'));
        self::assertSame($audits, $this->auditTotal());

        $actor = $this->reloadUser($actor->getId());
        $home = $this->reloadInstitution($home->getId());
        $open = $this->years()->createPlanned($home, $actor, 'Cr Fail Open', new \DateTimeImmutable('2027-09-01'), new \DateTimeImmutable('2028-06-15'), 'operator_setup');
        $this->classrooms()->create($open, $actor, 'Ilk Sinif', GradeLevel::Grade5, 'operator_setup');
        $audits = $this->auditTotal();
        $classrooms = $this->countTable('classrooms');
        try {
            $this->classrooms()->create($this->reloadYear($open->getId()), $this->reloadUser($actor->getId()), 'Ilk Sinif', GradeLevel::Grade6, 'operator_setup');
            self::fail('A duplicate classroom name was stored.');
        } catch (ClassroomException $e) {
            self::assertSame('conflict', $e->getReason()->value);
        }
        $this->reboot();
        self::assertSame($classrooms, $this->countTable('classrooms'));
        self::assertSame($audits, $this->auditTotal());

        $actor = $this->reloadUser($actor->getId());
        $this->institutionStatus()->suspend($this->reloadInstitution($home->getId()), $actor, 'operator_correction');
        $audits = $this->auditTotal();
        try {
            $this->classrooms()->create($this->reloadYear($open->getId()), $actor, 'Askida Sinif', GradeLevel::Grade5, 'operator_setup');
            self::fail('A suspended institution accepted a classroom.');
        } catch (ClassroomException $e) {
            self::assertSame('institution_not_operable', $e->getReason()->value);
        }
        $this->reboot();
        self::assertSame($audits, $this->auditTotal());
        self::assertSame(ClassroomStatus::Active, $this->reloadClassroomByName('Ilk Sinif')->getStatus());
    }

    public function testArchiveAndActivationKeepHistoricalLinks(): void
    {
        $actor = $this->user('cr_hist_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('cr_hist_owner@example.com', UserRole::Teacher);
        $student = $this->user('cr_hist_student@example.com', UserRole::Student);
        $home = $this->activateInstitution($actor, $owner, 'Cr Hist Home');
        $other = $this->activateInstitution($actor, $owner, 'Cr Hist Other');
        $first = $this->years()->createPlanned($home, $actor, 'Cr Hist Bir', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-15'), 'operator_setup');
        $this->years()->activate($first, $actor, 'lifecycle_change');
        $classroom = $this->classrooms()->create($this->reloadYear($first->getId()), $this->reloadUser($actor->getId()), 'Tarih Sinif', GradeLevel::Grade5, 'operator_setup', 'A', 24);
        $membership = $this->memberships()->addMember($this->reloadInstitution($home->getId()), $this->reloadUser($actor->getId()), $this->reloadUser($student->getId()), InstitutionMembershipRole::Student, 'operator_setup');
        $enrollment = $this->enrollments()->enroll($this->reloadClassroom($classroom->getId()), $this->reloadUser($actor->getId()), $membership, 'operator_setup');
        $classroomId = $classroom->getId();
        $yearId = $first->getId()->toRfc4122();

        $classroom = $this->reloadClassroom($classroomId);
        $updatedAt = $classroom->getUpdatedAt()->getTimestamp();
        $this->classrooms()->revise($classroom, $this->reloadUser($actor->getId()), 'Tarih Sinif Yeni', 20, $updatedAt, 'operator_correction');
        $classroom = $this->reloadClassroom($classroomId);
        self::assertSame('Tarih Sinif Yeni', $classroom->getName());
        self::assertSame($yearId, $classroom->getAcademicYear()->getId()->toRfc4122());
        self::assertSame(GradeLevel::Grade5, $classroom->getGradeLevel());
        self::assertSame('A', $classroom->getSectionCode());
        self::assertSame(1, $this->auditCount(SecurityAuditAction::ClassroomUpdated->value, $classroomId->toRfc4122()));

        $second = $this->years()->createPlanned($this->reloadInstitution($home->getId()), $this->reloadUser($actor->getId()), 'Cr Hist Iki', new \DateTimeImmutable('2027-09-01'), new \DateTimeImmutable('2028-06-15'), 'operator_setup');
        $foreign = $this->years()->createPlanned($this->reloadInstitution($other->getId()), $this->reloadUser($actor->getId()), 'Cr Hist Diger', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-15'), 'operator_setup');
        $this->years()->activate($this->reloadYear($foreign->getId()), $this->reloadUser($actor->getId()), 'lifecycle_change');
        $this->years()->activate($this->reloadYear($second->getId()), $this->reloadUser($actor->getId()), 'lifecycle_change');

        $classroom = $this->reloadClassroom($classroomId);
        self::assertSame($yearId, $classroom->getAcademicYear()->getId()->toRfc4122());
        self::assertSame($classroomId->toRfc4122(), $this->enrollmentClassroom($enrollment->getId()));
        self::assertSame(ClassroomStatus::Active, $classroom->getStatus());
        $audits = $this->auditTotal();
        try {
            $this->classrooms()->revise($classroom, $this->reloadUser($actor->getId()), 'Tasinan Sinif', 20, $classroom->getUpdatedAt()->getTimestamp(), 'operator_correction');
            self::fail('A closed academic year accepted a classroom revision.');
        } catch (ClassroomException $e) {
            self::assertSame('year_not_operable', $e->getReason()->value);
        }
        $this->reboot();
        self::assertSame($audits, $this->auditTotal());
        self::assertSame('Tarih Sinif Yeni', $this->reloadClassroom($classroomId)->getName());

        $classroom = $this->reloadClassroom($classroomId);
        $this->classrooms()->archive($classroom, $this->reloadUser($actor->getId()), 'lifecycle_change');
        $classroom = $this->reloadClassroom($classroomId);
        self::assertSame(ClassroomStatus::Archived, $classroom->getStatus());
        self::assertSame($yearId, $classroom->getAcademicYear()->getId()->toRfc4122());
        self::assertSame($classroomId->toRfc4122(), $this->enrollmentClassroom($enrollment->getId()));
        self::assertSame(1, $this->auditCount(SecurityAuditAction::ClassroomArchived->value, $classroomId->toRfc4122()));

        $audits = $this->auditTotal();
        try {
            $this->classrooms()->archive($classroom, $this->reloadUser($actor->getId()), 'lifecycle_change');
            self::fail('An archived classroom was archived again.');
        } catch (ClassroomException $e) {
            self::assertContains($e->getReason()->value, ['invalid_transition', 'classroom_not_operable']);
        }
        $this->reboot();
        self::assertSame($audits, $this->auditTotal());
        self::assertSame(ClassroomStatus::Archived, $this->reloadClassroom($classroomId)->getStatus());
    }

    public function testListQueryCountDoesNotGrowAndCountersFollowActiveRules(): void
    {
        $actor = $this->user('cr_query_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('cr_query_owner@example.com', UserRole::Teacher);
        $teacher = $this->user('cr_query_teacher@example.com', UserRole::Teacher);
        $assistant = $this->user('cr_query_assistant@example.com', UserRole::Teacher);
        $student = $this->user('cr_query_student@example.com', UserRole::Student);
        $endedStudent = $this->user('cr_query_ended@example.com', UserRole::Student);
        $home = $this->activateInstitution($actor, $owner, 'Cr Query Home');
        $year = $this->years()->createPlanned($home, $actor, 'Cr Query Yil', new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-15'), 'operator_setup');
        $this->years()->activate($year, $actor, 'lifecycle_change');
        $actor = $this->reloadUser($actor->getId());
        $home = $this->reloadInstitution($home->getId());
        $year = $this->reloadYear($year->getId());
        $first = $this->classrooms()->create($year, $actor, 'Cr Bound Bir', GradeLevel::Grade5, 'operator_setup', 'BIR', 24);
        $teacherMembership = $this->memberships()->addMember($home, $actor, $this->reloadUser($teacher->getId()), InstitutionMembershipRole::Teacher, 'operator_setup');
        $assistantMembership = $this->memberships()->addMember($this->reloadInstitution($home->getId()), $this->reloadUser($actor->getId()), $this->reloadUser($assistant->getId()), InstitutionMembershipRole::Teacher, 'operator_setup');
        $studentMembership = $this->memberships()->addMember($this->reloadInstitution($home->getId()), $this->reloadUser($actor->getId()), $this->reloadUser($student->getId()), InstitutionMembershipRole::Student, 'operator_setup');
        $endedMembership = $this->memberships()->addMember($this->reloadInstitution($home->getId()), $this->reloadUser($actor->getId()), $this->reloadUser($endedStudent->getId()), InstitutionMembershipRole::Student, 'operator_setup');
        $this->assignments()->assign($this->reloadClassroom($first->getId()), $this->reloadUser($actor->getId()), $teacherMembership, TeacherAssignmentRole::HomeroomTeacher, 'operator_setup');
        $endedAssignment = $this->assignments()->assign($this->reloadClassroom($first->getId()), $this->reloadUser($actor->getId()), $assistantMembership, TeacherAssignmentRole::AssistantTeacher, 'operator_setup');
        $this->assignments()->endAssignment($endedAssignment, $this->reloadUser($actor->getId()), 'operator_correction');
        $this->enrollments()->enroll($this->reloadClassroom($first->getId()), $this->reloadUser($actor->getId()), $studentMembership, 'operator_setup');
        $endedEnrollment = $this->enrollments()->enroll($this->reloadClassroom($first->getId()), $this->reloadUser($actor->getId()), $endedMembership, 'operator_setup');
        $this->enrollments()->endEnrollment($endedEnrollment, $this->reloadUser($actor->getId()), 'operator_correction');

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
        $query->listClassrooms($actorId, ['q' => 'Cr Bound']);
        $before = $counter->count;
        $one = $query->listClassrooms($actorId, ['q' => 'Cr Bound', 'page_size' => 1]);
        $oneQueries = $counter->count - $before;
        self::assertCount(1, $one->items);
        $summary = $query->summarize($actorId);

        $this->classrooms()->create($this->reloadYear($year->getId()), $this->reloadUser($actor->getId()), 'Cr Bound Iki', GradeLevel::Grade6, 'operator_setup');
        $this->classrooms()->create($this->reloadYear($year->getId()), $this->reloadUser($actor->getId()), 'Cr Bound Uc', GradeLevel::Grade7, 'operator_setup', 'UC');
        $quiet = $this->activateInstitution($this->reloadUser($actor->getId()), $this->reloadUser($owner->getId()), 'Cr Query Quiet');
        self::assertSame(InstitutionStatus::Active, $quiet->getStatus());

        $before = $counter->count;
        $three = $query->listClassrooms($actorId, ['q' => 'Cr Bound', 'page_size' => 1]);
        $threeQueries = $counter->count - $before;
        self::assertSame($oneQueries, $threeQueries);
        self::assertLessThan(12, $threeQueries);
        self::assertSame(3, $three->totalCount);
        self::assertCount(1, $three->items);

        $filtered = $query->summarize($actorId);
        self::assertSame($summary->total + 2, $filtered->total);
        self::assertSame($summary->active + 2, $filtered->active);
        self::assertSame($summary->activeWithoutActiveTeacher + 2, $filtered->activeWithoutActiveTeacher);
        self::assertSame($summary->linkedInstitutions, $filtered->linkedInstitutions);

        $listed = $query->listClassrooms($actorId, ['q' => 'Cr Bound Bir']);
        $row = $listed->items[0] ?? null;
        self::assertInstanceOf(AdminClassroomListItemView::class, $row);
        self::assertSame(1, $row->activeStudentCount);
        self::assertSame('Ad Soyad · Sınıf öğretmeni', $row->teacherSummary);
        self::assertSame('BIR', $row->sectionCode);
        self::assertStringNotContainsString('@', $row->teacherSummary);
        $empty = $query->listClassrooms($actorId, ['q' => 'Cr Bound Iki']);
        $emptyRow = $empty->items[0] ?? null;
        self::assertInstanceOf(AdminClassroomListItemView::class, $emptyRow);
        self::assertSame('Öğretmen atanmamış', $emptyRow->teacherSummary);
        self::assertSame(0, $emptyRow->activeStudentCount);

        $detail = $query->getDetail($actorId, $first->getId());
        self::assertSame(1, $detail->activeTeacherCount);
        self::assertSame(1, $detail->endedTeacherCount);
        self::assertSame(1, $detail->activeStudentCount);
        self::assertSame(1, $detail->endedStudentCount);
        self::assertSame('Ad Soyad', $detail->teachers[0]->name ?? null);

        $byCode = $query->listClassrooms($actorId, ['q' => 'UC']);
        self::assertSame(1, $byCode->totalCount);
        $missing = $query->listClassrooms($actorId, ['q' => 'Cr Bound Olmayan']);
        self::assertSame(0, $missing->totalCount);
        self::assertSame($filtered->total, $query->summarize($actorId)->total);

        try {
            $query->listClassrooms($actorId, ['status' => 'draft']);
            self::fail('An unknown status was accepted.');
        } catch (CommerceException $e) {
            self::assertSame('invalid_input', $e->getReason()->value);
        }
    }

    private function reboot(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
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

    private function reloadClassroom(Uuid $id): Classroom
    {
        $this->em->clear();
        $classroom = $this->em->find(Classroom::class, $id);
        self::assertInstanceOf(Classroom::class, $classroom);

        return $classroom;
    }

    private function reloadClassroomByName(string $name): Classroom
    {
        $classroom = $this->em->getRepository(Classroom::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Classroom::class, $classroom);

        return $classroom;
    }

    private function enrollmentClassroom(Uuid $id): string
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT classroom_id FROM classroom_student_enrollments WHERE id = ?',
            [$id->toBinary()],
        );
        self::assertIsString($value);

        return Uuid::fromBinary($value)->toRfc4122();
    }

    private function countTable(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function auditTotal(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM security_audit_events');
    }

    private function auditCount(string $action, string $id): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM security_audit_events WHERE action = ? AND metadata LIKE ?',
            [$action, '%'.$id.'%'],
        );
    }

    private function years(): AcademicYearManager
    {
        $manager = static::getContainer()->get(AcademicYearManager::class);
        self::assertInstanceOf(AcademicYearManager::class, $manager);

        return $manager;
    }

    private function classrooms(): ClassroomManager
    {
        $manager = static::getContainer()->get(ClassroomManager::class);
        self::assertInstanceOf(ClassroomManager::class, $manager);

        return $manager;
    }

    private function memberships(): InstitutionMembershipManager
    {
        $manager = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $manager);

        return $manager;
    }

    private function assignments(): ClassroomTeacherAssignmentManager
    {
        $manager = static::getContainer()->get(ClassroomTeacherAssignmentManager::class);
        self::assertInstanceOf(ClassroomTeacherAssignmentManager::class, $manager);

        return $manager;
    }

    private function enrollments(): ClassroomStudentEnrollmentManager
    {
        $manager = static::getContainer()->get(ClassroomStudentEnrollmentManager::class);
        self::assertInstanceOf(ClassroomStudentEnrollmentManager::class, $manager);

        return $manager;
    }

    private function institutionStatus(): InstitutionStatusManager
    {
        $manager = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $manager);

        return $manager;
    }

    private function query(): AdminClassroomQuery
    {
        $query = static::getContainer()->get(AdminClassroomQuery::class);
        self::assertInstanceOf(AdminClassroomQuery::class, $query);

        return $query;
    }
}
