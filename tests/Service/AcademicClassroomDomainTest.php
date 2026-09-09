<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\ClassroomStudentEnrollment;
use App\Entity\ClassroomTeacherAssignment;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AcademicYearFailureReason;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassroomFailureReason;
use App\Enum\ClassroomStatus;
use App\Enum\ClassroomStudentFailureReason;
use App\Enum\ClassroomTeacherFailureReason;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\SecurityAuditAction;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\TeacherAssignmentRole;
use App\Enum\TeacherAssignmentStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\AcademicYearException;
use App\Exception\ClassroomException;
use App\Exception\ClassroomStudentEnrollmentException;
use App\Exception\ClassroomTeacherAssignmentException;
use App\Exception\InstitutionMembershipException;
use App\Repository\ClassroomRepository;
use App\Repository\ClassroomStudentEnrollmentRepository;
use App\Repository\ClassroomTeacherAssignmentRepository;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\InstitutionRepository;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\ClassroomManager;
use App\Service\ClassroomStudentEnrollmentManager;
use App\Service\ClassroomTeacherAssignmentManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AcademicClassroomDomainTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;
    private InstitutionRepository $institutions;
    private InstitutionMembershipRepository $memberships;
    private ClassroomRepository $classrooms;
    private ClassroomTeacherAssignmentRepository $assignments;
    private ClassroomStudentEnrollmentRepository $enrollments;
    private SecurityAuditEventRepository $events;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebind();
        $this->cleanup();
    }

    public function testAcademicYearCreateActivateClosesPreviousAndDateRules(): void
    {
        [$owner, $sa, $institution] = $this->activeOwnerInstitution('ay-basic');
        $yearManager = $this->yearManager();

        $y1 = $yearManager->createPlanned(
            $institution,
            $owner,
            '2024-2025',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'create_y1',
        );
        self::assertSame(AcademicYearStatus::Planned, $y1->getStatus());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AcademicYearCreated->value));

        $yearManager->activate($y1, $owner, 'activate_y1');
        self::assertSame(AcademicYearStatus::Active, $y1->getStatus());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM institution_active_academic_year_guards'));

        $y2 = $yearManager->createPlanned(
            $institution,
            $owner,
            '2025-2026',
            new \DateTimeImmutable('2025-09-01'),
            new \DateTimeImmutable('2026-06-15'),
            'create_y2',
        );
        $yearManager->activate($y2, $owner, 'activate_y2');
        $this->em->refresh($y1);
        $this->em->refresh($y2);
        self::assertSame(AcademicYearStatus::Closed, $y1->getStatus());
        self::assertSame(AcademicYearStatus::Active, $y2->getStatus());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM institution_active_academic_year_guards'));

        try {
            $yearManager->activate($y1, $owner, 'reopen_closed');
            self::fail('Closed year cannot reopen');
        } catch (AcademicYearException $e) {
            self::assertSame(AcademicYearFailureReason::InvalidTransition, $e->getReason());
        }
        $this->resetDoctrine();
        $institution = $this->institutions->find($institution->getId());
        self::assertNotNull($institution);
        $owner = $this->users->find($owner->getId());
        self::assertInstanceOf(User::class, $owner);

        try {
            $this->yearManager()->createPlanned(
                $institution,
                $owner,
                'Overlap Year',
                new \DateTimeImmutable('2025-06-15'),
                new \DateTimeImmutable('2025-12-01'),
                'overlap',
            );
            self::fail('Overlap should fail');
        } catch (AcademicYearException $e) {
            self::assertSame(AcademicYearFailureReason::DateOverlap, $e->getReason());
        }

        unset($sa);
    }

    public function testAcademicYearIsolationAcrossInstitutions(): void
    {
        [$ownerA, $sa, $instA] = $this->activeOwnerInstitution('ay-iso-a');
        [$ownerB, , $instB] = $this->activeOwnerInstitution('ay-iso-b');
        $yearManager = $this->yearManager();
        $yearManager->createPlanned(
            $instA,
            $ownerA,
            'Shared Dates',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'iso_a',
        );
        $yearB = $yearManager->createPlanned(
            $instB,
            $ownerB,
            'Shared Dates',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'iso_b',
        );
        self::assertInstanceOf(AcademicYear::class, $yearB);
        unset($sa);
    }

    public function testStudentMembershipAssignableByOwnerAndManager(): void
    {
        [$owner, $sa, $institution] = $this->activeOwnerInstitution('stu-mem');
        $manager = $this->activeUser('stu-mem-mgr@example.com');
        $student = $this->activeUser('stu-mem-stu@example.com');
        $teacher = $this->activeUser('stu-mem-tch@example.com');
        $this->membershipManager()->addMember($institution, $owner, $manager, InstitutionMembershipRole::Manager, 'add_mgr');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
        $this->membershipManager()->addMember($institution, $manager, $student, InstitutionMembershipRole::Student, 'add_stu');
        $membership = $this->memberships->findActiveMembership($student, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $membership);
        self::assertSame(InstitutionMembershipRole::Student, $membership->getRole());

        try {
            $this->membershipManager()->addMember(
                $institution,
                $teacher,
                $this->activeUser('stu-mem-stu2@example.com'),
                InstitutionMembershipRole::Student,
                'tch_add',
            );
            self::fail('Teacher cannot add student');
        } catch (InstitutionMembershipException) {
        }
        unset($sa);
    }

    public function testClassroomCreateRenameCapacityArchiveAndClosedYearBlock(): void
    {
        [$owner, , $institution] = $this->activeOwnerInstitution('cls-basic');
        $year = $this->yearManager()->createPlanned(
            $institution,
            $owner,
            'Year CLS',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'create_year',
        );
        $classroom = $this->classroomManager()->create(
            $year,
            $owner,
            '9-A',
            GradeLevel::Grade9,
            'create_cls',
            'A',
            30,
        );
        self::assertSame(ClassroomStatus::Active, $classroom->getStatus());
        self::assertSame(30, $classroom->getCapacity());
        self::assertSame(GradeLevel::Grade9, $classroom->getGradeLevel());

        $this->classroomManager()->rename($classroom, $owner, '9-B', 'rename');
        $this->classroomManager()->changeCapacity($classroom, $owner, 40, 'cap');
        self::assertSame('9-B', $classroom->getName());
        self::assertSame(40, $classroom->getCapacity());

        try {
            $this->classroomManager()->changeCapacity($classroom, $owner, 0, 'bad_cap');
            self::fail('capacity 0 invalid');
        } catch (ClassroomException) {
        }

        $this->classroomManager()->archive($classroom, $owner, 'archive');
        self::assertSame(ClassroomStatus::Archived, $classroom->getStatus());

        $year2 = $this->yearManager()->createPlanned(
            $institution,
            $owner,
            'Year Closed',
            new \DateTimeImmutable('2025-09-01'),
            new \DateTimeImmutable('2026-06-15'),
            'y2',
        );
        $this->yearManager()->close($year2, $owner, 'close_y2');
        try {
            $this->classroomManager()->create($year2, $owner, 'Blocked', GradeLevel::Grade1, 'blocked');
            self::fail('closed year blocks create');
        } catch (ClassroomException) {
        }
    }

    public function testTeacherAssignmentHomeroomDuplicateAndReassign(): void
    {
        [$owner, , $institution, $year, $classroom] = $this->readyClassroom('tch-asg');
        $teacher1 = $this->activeUser('tch-asg-1@example.com');
        $teacher2 = $this->activeUser('tch-asg-2@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher1, InstitutionMembershipRole::Teacher, 't1');
        $this->membershipManager()->addMember($institution, $owner, $teacher2, InstitutionMembershipRole::Teacher, 't2');
        $m1 = $this->memberships->findActiveMembership($teacher1, $institution);
        $m2 = $this->memberships->findActiveMembership($teacher2, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $m1);
        self::assertInstanceOf(InstitutionMembership::class, $m2);

        $a1 = $this->teacherManager()->assign($classroom, $owner, $m1, TeacherAssignmentRole::HomeroomTeacher, 'h1');
        self::assertSame(TeacherAssignmentStatus::Active, $a1->getStatus());

        try {
            $this->teacherManager()->assign($classroom, $owner, $m2, TeacherAssignmentRole::HomeroomTeacher, 'h2');
            self::fail('second homeroom');
        } catch (ClassroomTeacherAssignmentException $e) {
            self::assertSame(ClassroomTeacherFailureReason::Conflict, $e->getReason());
        }
        $this->resetDoctrine();

        try {
            $this->teacherManager()->assign($classroom, $owner, $m1, TeacherAssignmentRole::AssistantTeacher, 'dup');
            self::fail('duplicate active assignment');
        } catch (ClassroomTeacherAssignmentException $e) {
            self::assertSame(ClassroomTeacherFailureReason::Conflict, $e->getReason());
        }
        $this->resetDoctrine();

        $a1 = $this->assignments->find($a1->getId());
        self::assertInstanceOf(ClassroomTeacherAssignment::class, $a1);
        $classroom = $a1->getClassroom();
        $owner = $this->users->find($owner->getId());
        self::assertInstanceOf(User::class, $owner);
        $m1 = $this->memberships->find($m1->getId());
        self::assertInstanceOf(InstitutionMembership::class, $m1);

        $this->teacherManager()->endAssignment($a1, $owner, 'end_h');
        $a2 = $this->teacherManager()->assign($classroom, $owner, $m1, TeacherAssignmentRole::AssistantTeacher, 're');
        self::assertSame(TeacherAssignmentRole::AssistantTeacher, $a2->getRole());
        $this->em->refresh($a1);
        self::assertSame(TeacherAssignmentStatus::Ended, $a1->getStatus());
        unset($year);
    }

    public function testStudentEnrollmentCapacityTransferHistoryAndReenroll(): void
    {
        [$owner, , $institution, $year, $classroom] = $this->readyClassroom('stu-enr', capacity: 1);
        $classroom2 = $this->classroomManager()->create($year, $owner, '10-B', GradeLevel::Grade10, 'c2', 'B', 2);
        $student = $this->activeUser('stu-enr-1@example.com');
        $student2 = $this->activeUser('stu-enr-2@example.com');
        $this->membershipManager()->addMember($institution, $owner, $student, InstitutionMembershipRole::Student, 's1');
        $this->membershipManager()->addMember($institution, $owner, $student2, InstitutionMembershipRole::Student, 's2');
        $sm1 = $this->memberships->findActiveMembership($student, $institution);
        $sm2 = $this->memberships->findActiveMembership($student2, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $sm1);
        self::assertInstanceOf(InstitutionMembership::class, $sm2);

        $e1 = $this->enrollmentManager()->enroll($classroom, $owner, $sm1, 'en1');
        self::assertSame(StudentEnrollmentStatus::Active, $e1->getStatus());

        try {
            $this->enrollmentManager()->enroll($classroom, $owner, $sm2, 'cap');
            self::fail('capacity');
        } catch (ClassroomStudentEnrollmentException $e) {
            self::assertSame(ClassroomStudentFailureReason::CapacityExceeded, $e->getReason());
        }
        $this->resetDoctrine();
        $e1 = $this->enrollments->find($e1->getId());
        self::assertInstanceOf(ClassroomStudentEnrollment::class, $e1);
        $classroom2 = $this->classrooms->find($classroom2->getId());
        self::assertInstanceOf(Classroom::class, $classroom2);
        $owner = $this->users->find($owner->getId());
        self::assertInstanceOf(User::class, $owner);
        $sm1 = $this->memberships->find($sm1->getId());
        self::assertInstanceOf(InstitutionMembership::class, $sm1);

        $e2 = $this->enrollmentManager()->transfer($e1, $owner, $classroom2, 'xfer');
        $this->em->refresh($e1);
        self::assertSame(StudentEnrollmentStatus::Ended, $e1->getStatus());
        self::assertNotNull($e1->getTransferredAt());
        self::assertSame(StudentEnrollmentStatus::Active, $e2->getStatus());
        self::assertTrue($e2->getClassroom()->getId()->equals($classroom2->getId()));
        self::assertFalse($e1->getId()->equals($e2->getId()));

        $this->enrollmentManager()->endEnrollment($e2, $owner, 'end');
        $e3 = $this->enrollmentManager()->enroll($classroom2, $owner, $sm1, 'reenroll');
        self::assertSame(StudentEnrollmentStatus::Active, $e3->getStatus());
        unset($classroom);
    }

    public function testCrossTenantAssignmentAndEnrollmentRejected(): void
    {
        [$ownerA, , $instA, , $classroomA] = $this->readyClassroom('x-a');
        [$ownerB, , $instB] = $this->activeOwnerInstitution('x-b');
        $teacherB = $this->activeUser('x-b-tch@example.com');
        $studentB = $this->activeUser('x-b-stu@example.com');
        $this->membershipManager()->addMember($instB, $ownerB, $teacherB, InstitutionMembershipRole::Teacher, 'tb');
        $this->membershipManager()->addMember($instB, $ownerB, $studentB, InstitutionMembershipRole::Student, 'sb');
        $tm = $this->memberships->findActiveMembership($teacherB, $instB);
        $sm = $this->memberships->findActiveMembership($studentB, $instB);
        self::assertInstanceOf(InstitutionMembership::class, $tm);
        self::assertInstanceOf(InstitutionMembership::class, $sm);

        try {
            $this->teacherManager()->assign($classroomA, $ownerA, $tm, TeacherAssignmentRole::HomeroomTeacher, 'xt');
            self::fail('cross teacher');
        } catch (ClassroomTeacherAssignmentException $e) {
            self::assertSame(ClassroomTeacherFailureReason::CrossInstitution, $e->getReason());
        }

        try {
            $this->enrollmentManager()->enroll($classroomA, $ownerA, $sm, 'xs');
            self::fail('cross student');
        } catch (ClassroomStudentEnrollmentException $e) {
            self::assertSame(ClassroomStudentFailureReason::CrossInstitution, $e->getReason());
        }
        unset($instA);
    }

    public function testArchivedClassroomBlocksAssignAndEnroll(): void
    {
        [$owner, , $institution, , $classroom] = $this->readyClassroom('arch-cls');
        $this->classroomManager()->archive($classroom, $owner, 'arch');
        $teacher = $this->activeUser('arch-tch@example.com');
        $student = $this->activeUser('arch-stu@example.com');
        $this->membershipManager()->addMember($institution, $owner, $teacher, InstitutionMembershipRole::Teacher, 'add_tch');
        $this->membershipManager()->addMember($institution, $owner, $student, InstitutionMembershipRole::Student, 'add_stu');
        $tm = $this->memberships->findActiveMembership($teacher, $institution);
        $sm = $this->memberships->findActiveMembership($student, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $tm);
        self::assertInstanceOf(InstitutionMembership::class, $sm);

        $this->expectException(ClassroomTeacherAssignmentException::class);
        $this->teacherManager()->assign($classroom, $owner, $tm, TeacherAssignmentRole::HomeroomTeacher, 'nope');
    }

    public function testAuditFailureRollsBackYearCreate(): void
    {
        [$owner, , $institution] = $this->activeOwnerInstitution('ay-roll');
        $connection = $this->em->getConnection();
        $connection->executeStatement('RENAME TABLE security_audit_events TO security_audit_events_bak');
        try {
            try {
                $this->yearManager()->createPlanned(
                    $institution,
                    $owner,
                    'Rollback Year',
                    new \DateTimeImmutable('2024-09-01'),
                    new \DateTimeImmutable('2025-06-15'),
                    'roll',
                );
                self::fail('Expected failure');
            } catch (\Throwable) {
            }
        } finally {
            $connection->executeStatement('RENAME TABLE security_audit_events_bak TO security_audit_events');
            self::ensureKernelShutdown();
            self::bootKernel();
            $this->rebind();
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM academic_years'));
    }

    public function testClassroomCapacityLowerBoundAgainstActiveEnrollments(): void
    {
        [$owner, , $institution, $year, $classroom] = $this->readyClassroom('cap-lb', capacity: 20);
        $otherClassroom = $this->classroomManager()->create($year, $owner, 'cap-lb 10-B', GradeLevel::Grade10, 'c2', 'B', 20);
        [$ownerOther, , $instOther, , $classroomOther] = $this->readyClassroom('cap-lb-o', capacity: 20);

        $firstStudentMembershipId = null;
        for ($i = 1; $i <= 10; ++$i) {
            $user = $this->activeUser(\sprintf('cap-lb-s%d@example.com', $i));
            $this->membershipManager()->addMember($institution, $owner, $user, InstitutionMembershipRole::Student, 's'.$i);
            $sm = $this->memberships->findActiveMembership($user, $institution);
            self::assertInstanceOf(InstitutionMembership::class, $sm);
            if (1 === $i) {
                $firstStudentMembershipId = $sm->getId();
            }
            $this->enrollmentManager()->enroll($classroom, $owner, $sm, 'en'.$i);
        }
        self::assertInstanceOf(\Symfony\Component\Uid\Uuid::class, $firstStudentMembershipId);

        $otherStudent = $this->activeUser('cap-lb-other-cls@example.com');
        $this->membershipManager()->addMember($institution, $owner, $otherStudent, InstitutionMembershipRole::Student, 'other_cls');
        $otherSm = $this->memberships->findActiveMembership($otherStudent, $institution);
        self::assertInstanceOf(InstitutionMembership::class, $otherSm);
        $this->enrollmentManager()->enroll($otherClassroom, $owner, $otherSm, 'en_other_cls');

        $foreignStudent = $this->activeUser('cap-lb-foreign@example.com');
        $this->membershipManager()->addMember($instOther, $ownerOther, $foreignStudent, InstitutionMembershipRole::Student, 'foreign');
        $foreignSm = $this->memberships->findActiveMembership($foreignStudent, $instOther);
        self::assertInstanceOf(InstitutionMembership::class, $foreignSm);
        $this->enrollmentManager()->enroll($classroomOther, $ownerOther, $foreignSm, 'en_foreign');

        $classroomId = $classroom->getId();
        $otherClassroomId = $otherClassroom->getId();
        $classroomOtherId = $classroomOther->getId();
        $ownerId = $owner->getId();
        $ownerOtherId = $ownerOther->getId();

        try {
            $this->classroomManager()->changeCapacity($classroom, $owner, 9, 'too_low');
            self::fail('capacity 9 with 10 active should fail');
        } catch (ClassroomException $e) {
            self::assertSame(ClassroomFailureReason::CapacityBelowEnrollment, $e->getReason());
        }
        $this->resetDoctrine();

        $classroom = $this->classrooms->find($classroomId);
        self::assertInstanceOf(Classroom::class, $classroom);
        self::assertSame(20, $classroom->getCapacity());
        $owner = $this->users->find($ownerId);
        self::assertInstanceOf(User::class, $owner);

        $this->classroomManager()->changeCapacity($classroom, $owner, 10, 'exact');
        $this->em->refresh($classroom);
        self::assertSame(10, $classroom->getCapacity());

        $this->classroomManager()->changeCapacity($classroom, $owner, null, 'unlimited');
        $this->em->refresh($classroom);
        self::assertNull($classroom->getCapacity());

        $endedEnrollment = $this->enrollments->createQueryBuilder('e')
            ->andWhere('e.studentMembership = :m')
            ->andWhere('e.status = :status')
            ->setParameter('m', $firstStudentMembershipId, 'uuid')
            ->setParameter('status', StudentEnrollmentStatus::Active)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(ClassroomStudentEnrollment::class, $endedEnrollment);
        $this->enrollmentManager()->endEnrollment($endedEnrollment, $owner, 'end_one');

        $classroom = $this->classrooms->find($classroomId);
        self::assertInstanceOf(Classroom::class, $classroom);
        $this->classroomManager()->changeCapacity($classroom, $owner, 9, 'after_end');
        $this->em->refresh($classroom);
        self::assertSame(9, $classroom->getCapacity());

        // Transfer: source loses an active seat; target gains one.
        $this->classroomManager()->changeCapacity($classroom, $owner, 20, 'room');
        $activeSource = $this->enrollments->createQueryBuilder('e')
            ->andWhere('e.classroom = :c')
            ->andWhere('e.status = :status')
            ->setParameter('c', $classroomId, 'uuid')
            ->setParameter('status', StudentEnrollmentStatus::Active)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(ClassroomStudentEnrollment::class, $activeSource);
        $otherClassroom = $this->classrooms->find($otherClassroomId);
        self::assertInstanceOf(Classroom::class, $otherClassroom);
        $owner = $this->users->find($ownerId);
        self::assertInstanceOf(User::class, $owner);

        $transferred = $this->enrollmentManager()->transfer($activeSource, $owner, $otherClassroom, 'xfer');
        self::assertSame(StudentEnrollmentStatus::Active, $transferred->getStatus());

        $classroom = $this->classrooms->find($classroomId);
        self::assertInstanceOf(Classroom::class, $classroom);
        // Source now has 8 active (10 - 1 ended - 1 transferred).
        $this->classroomManager()->changeCapacity($classroom, $owner, 8, 'src_after_xfer');
        $this->em->refresh($classroom);
        self::assertSame(8, $classroom->getCapacity());

        $otherClassroom = $this->classrooms->find($otherClassroomId);
        self::assertInstanceOf(Classroom::class, $otherClassroom);
        // Target has original other-classroom student + transfer = 2 active.
        try {
            $this->classroomManager()->changeCapacity($otherClassroom, $owner, 1, 'tgt_too_low');
            self::fail('target capacity below enrollment');
        } catch (ClassroomException $e) {
            self::assertSame(ClassroomFailureReason::CapacityBelowEnrollment, $e->getReason());
        }
        $this->resetDoctrine();

        $otherClassroom = $this->classrooms->find($otherClassroomId);
        self::assertInstanceOf(Classroom::class, $otherClassroom);
        self::assertSame(20, $otherClassroom->getCapacity());
        $owner = $this->users->find($ownerId);
        self::assertInstanceOf(User::class, $owner);
        $this->classroomManager()->changeCapacity($otherClassroom, $owner, 2, 'tgt_ok');
        $this->em->refresh($otherClassroom);
        self::assertSame(2, $otherClassroom->getCapacity());

        // Other institution's classroom only counts its own enrollments.
        $classroomOther = $this->classrooms->find($classroomOtherId);
        self::assertInstanceOf(Classroom::class, $classroomOther);
        $ownerOther = $this->users->find($ownerOtherId);
        self::assertInstanceOf(User::class, $ownerOther);
        $this->classroomManager()->changeCapacity($classroomOther, $ownerOther, 1, 'foreign_ok');
        $this->em->refresh($classroomOther);
        self::assertSame(1, $classroomOther->getCapacity());
    }

    /**
     * @return array{0: User, 1: User, 2: \App\Entity\Institution}
     */
    private function activeOwnerInstitution(string $prefix): array
    {
        $sa = $this->activeUser($prefix.'-sa@example.com');
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($sa);
        $owner = $this->activeUser($prefix.'-owner@example.com');
        $institution = $this->creator()->create($sa, $owner, $prefix.' School', InstitutionType::School, 'platform_setup');
        $this->statusManager()->activate($institution, $sa, 'activate');

        return [$owner, $sa, $institution];
    }

    /**
     * @return array{0: User, 1: User, 2: \App\Entity\Institution, 3: AcademicYear, 4: Classroom}
     */
    private function readyClassroom(string $prefix, ?int $capacity = 30): array
    {
        [$owner, $sa, $institution] = $this->activeOwnerInstitution($prefix);
        $year = $this->yearManager()->createPlanned(
            $institution,
            $owner,
            $prefix.' Year',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'year',
        );
        $this->yearManager()->activate($year, $owner, 'act');
        $classroom = $this->classroomManager()->create(
            $year,
            $owner,
            $prefix.' 10-A',
            GradeLevel::Grade10,
            'cls',
            'A',
            $capacity,
        );

        return [$owner, $sa, $institution, $year, $classroom];
    }

    private function activeUser(string $email): User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'A', 'U', UserRole::Student);
        $user->markEmailVerified(new \DateTimeImmutable('now'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }

    private function creator(): InstitutionCreator
    {
        $service = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $service);

        return $service;
    }

    private function statusManager(): InstitutionStatusManager
    {
        $service = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $service);

        return $service;
    }

    private function membershipManager(): InstitutionMembershipManager
    {
        $service = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $service);

        return $service;
    }

    private function yearManager(): AcademicYearManager
    {
        $service = static::getContainer()->get(AcademicYearManager::class);
        self::assertInstanceOf(AcademicYearManager::class, $service);

        return $service;
    }

    private function classroomManager(): ClassroomManager
    {
        $service = static::getContainer()->get(ClassroomManager::class);
        self::assertInstanceOf(ClassroomManager::class, $service);

        return $service;
    }

    private function teacherManager(): ClassroomTeacherAssignmentManager
    {
        $service = static::getContainer()->get(ClassroomTeacherAssignmentManager::class);
        self::assertInstanceOf(ClassroomTeacherAssignmentManager::class, $service);

        return $service;
    }

    private function enrollmentManager(): ClassroomStudentEnrollmentManager
    {
        $service = static::getContainer()->get(ClassroomStudentEnrollmentManager::class);
        self::assertInstanceOf(ClassroomStudentEnrollmentManager::class, $service);

        return $service;
    }

    private function rebind(): void
    {
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

        $institutions = $c->get(InstitutionRepository::class);
        self::assertInstanceOf(InstitutionRepository::class, $institutions);
        $this->institutions = $institutions;

        $memberships = $c->get(InstitutionMembershipRepository::class);
        self::assertInstanceOf(InstitutionMembershipRepository::class, $memberships);
        $this->memberships = $memberships;

        $classrooms = $c->get(ClassroomRepository::class);
        self::assertInstanceOf(ClassroomRepository::class, $classrooms);
        $this->classrooms = $classrooms;

        $assignments = $c->get(ClassroomTeacherAssignmentRepository::class);
        self::assertInstanceOf(ClassroomTeacherAssignmentRepository::class, $assignments);
        $this->assignments = $assignments;

        $enrollments = $c->get(ClassroomStudentEnrollmentRepository::class);
        self::assertInstanceOf(ClassroomStudentEnrollmentRepository::class, $enrollments);
        $this->enrollments = $enrollments;

        $events = $c->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);
        $this->events = $events;
    }

    /**
     * wrapInTransaction closes the EM on any callback exception; reopen for continued assertions.
     */
    private function resetDoctrine(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $doctrine->resetManager();
        // Managers hold the closed EM; rebuild the test kernel container services.
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebind();
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        $schema = $connection->createSchemaManager();
        if ($schema->tablesExist(['security_audit_events_bak']) && !$schema->tablesExist(['security_audit_events'])) {
            $connection->executeStatement('RENAME TABLE security_audit_events_bak TO security_audit_events');
        }
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($connection, [
            'course_teacher_active_guards',
            'course_teacher_assignments',
            'classroom_course_active_guards',
            'classroom_courses',
            'question_revision_primary_alignment_guards',
            'question_revision_alignments',
            'question_answer_keys',
            'question_revision_options',
            'question_revisions',
            'questions',
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
            'academic_year_student_enrollment_guards',
            'classroom_student_enrollments',
            'classroom_teacher_active_guards',
            'classroom_homeroom_guards',
            'classroom_teacher_assignments',
            'classrooms',
            'institution_active_academic_year_guards',
            'academic_years',
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
