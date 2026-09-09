<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\TeacherAssignmentRole;
use App\Enum\TeacherAssignmentStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\ClassroomManager;
use App\Service\ClassroomStudentEnrollmentManager;
use App\Service\ClassroomTeacherAssignmentManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * DBAL inserts that violate composite tenant/guard FKs must be rejected by MariaDB.
 */
final class AcademicClassroomCompositeFkConstraintTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserFactory $factory;
    private UserRepository $users;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->factory = $factory;
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;
        $this->cleanup();
    }

    public function testActiveYearGuardRejectsCrossInstitutionYear(): void
    {
        [$ownerA, $sa, $instA] = $this->activeOwnerInstitution('fk-a');
        [, , $instB] = $this->activeOwnerInstitution('fk-b');
        unset($sa);
        $yearA = $this->yearManager()->createPlanned(
            $instA,
            $ownerA,
            'Year A',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'ya',
        );
        $this->yearManager()->activate($yearA, $ownerA, 'act_a');

        $this->expectForeignKeyViolation(function () use ($instB, $yearA): void {
            $this->em->getConnection()->insert('institution_active_academic_year_guards', [
                'institution_id' => $instB->getId()->toBinary(),
                'academic_year_id' => $yearA->getId()->toBinary(),
            ]);
        });
    }

    public function testClassroomRejectsYearFromOtherInstitution(): void
    {
        [$ownerA, , $instA] = $this->activeOwnerInstitution('fk-cls-a');
        [, , $instB] = $this->activeOwnerInstitution('fk-cls-b');
        $yearA = $this->yearManager()->createPlanned(
            $instA,
            $ownerA,
            'Year A',
            new \DateTimeImmutable('2024-09-01'),
            new \DateTimeImmutable('2025-06-15'),
            'ya',
        );
        $now = '2024-09-01 10:00:00';
        $id = new UuidV7();

        $this->expectForeignKeyViolation(function () use ($instB, $yearA, $id, $now): void {
            $this->em->getConnection()->insert('classrooms', [
                'id' => $id->toBinary(),
                'institution_id' => $instB->getId()->toBinary(),
                'academic_year_id' => $yearA->getId()->toBinary(),
                'name' => 'Bad',
                'normalized_name' => 'bad',
                'grade_level' => GradeLevel::Grade9->value,
                'section_code' => null,
                'status' => 'active',
                'capacity' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function testTeacherAssignmentRejectsMembershipFromOtherInstitution(): void
    {
        [$ownerA, , , , $classroom] = $this->readyClassroom('fk-asg-a');
        [$ownerB, , $instB] = $this->activeOwnerInstitution('fk-asg-b');
        $teacherB = $this->activeUser('fk-asg-b-t@example.com');
        $tmB = $this->membershipManager()->addMember(
            $instB,
            $ownerB,
            $teacherB,
            InstitutionMembershipRole::Teacher,
            'add_tb',
        );
        $now = '2024-09-01 10:00:00';
        $id = new UuidV7();

        $this->expectForeignKeyViolation(function () use ($classroom, $tmB, $id, $now): void {
            $this->em->getConnection()->insert('classroom_teacher_assignments', [
                'id' => $id->toBinary(),
                'institution_id' => $classroom->getInstitution()->getId()->toBinary(),
                'classroom_id' => $classroom->getId()->toBinary(),
                'teacher_membership_id' => $tmB->getId()->toBinary(),
                'role' => TeacherAssignmentRole::AssistantTeacher->value,
                'status' => TeacherAssignmentStatus::Active->value,
                'assigned_at' => $now,
                'ended_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
        unset($ownerA);
    }

    public function testHomeroomGuardRejectsAssignmentFromOtherClassroom(): void
    {
        [$owner, , $institution, $year, $classroom1] = $this->readyClassroom('fk-hr-1');
        $classroom2 = $this->classroomManager()->create($year, $owner, 'Room 2', GradeLevel::Grade9, 'c2');
        $tm = $this->membershipManager()->addMember(
            $institution,
            $owner,
            $this->activeUser('fk-hr-t@example.com'),
            InstitutionMembershipRole::Teacher,
            'add_t',
        );
        $assignment = $this->teacherManager()->assign(
            $classroom1,
            $owner,
            $tm,
            TeacherAssignmentRole::AssistantTeacher,
            'asg',
        );

        $this->expectForeignKeyViolation(function () use ($classroom2, $assignment): void {
            $this->em->getConnection()->insert('classroom_homeroom_guards', [
                'classroom_id' => $classroom2->getId()->toBinary(),
                'assignment_id' => $assignment->getId()->toBinary(),
            ]);
        });
    }

    public function testEnrollmentGuardRejectsMismatchedEnrollmentKeys(): void
    {
        [$owner, , $institution, $year, $classroom] = $this->readyClassroom('fk-enr');
        $sm1 = $this->membershipManager()->addMember(
            $institution,
            $owner,
            $this->activeUser('fk-enr-s1@example.com'),
            InstitutionMembershipRole::Student,
            's1',
        );
        $sm2 = $this->membershipManager()->addMember(
            $institution,
            $owner,
            $this->activeUser('fk-enr-s2@example.com'),
            InstitutionMembershipRole::Student,
            's2',
        );
        $e1 = $this->enrollmentManager()->enroll($classroom, $owner, $sm1, 'e1');

        $this->expectForeignKeyViolation(function () use ($year, $sm2, $e1): void {
            $this->em->getConnection()->insert('academic_year_student_enrollment_guards', [
                'academic_year_id' => $year->getId()->toBinary(),
                'student_membership_id' => $sm2->getId()->toBinary(),
                'enrollment_id' => $e1->getId()->toBinary(),
            ]);
        });
    }

    /**
     * @param callable(): void $callback
     */
    private function expectForeignKeyViolation(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected foreign key / constraint violation');
        } catch (DbalException $e) {
            $message = strtolower($e->getMessage());
            self::assertTrue(
                str_contains($message, 'foreign key')
                || str_contains($message, 'constraint')
                || str_contains($message, '1452')
                || str_contains($message, 'integrity constraint'),
                'Unexpected DBAL error: '.$e->getMessage(),
            );
        }
    }

    /**
     * @return array{0: User, 1: User, 2: Institution}
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
     * @return array{0: User, 1: User, 2: Institution, 3: AcademicYear, 4: Classroom}
     */
    private function readyClassroom(string $prefix): array
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
            30,
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

    private function membershipManager(): InstitutionMembershipManager
    {
        $service = static::getContainer()->get(InstitutionMembershipManager::class);
        self::assertInstanceOf(InstitutionMembershipManager::class, $service);

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

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        foreach ([
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
        ] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
    }
}
