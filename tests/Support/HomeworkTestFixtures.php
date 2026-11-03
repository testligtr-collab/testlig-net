<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Dto\HomeworkDraftInput;
use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\ClassroomStudentEnrollment;
use App\Entity\ClassroomTeacherAssignment;
use App\Entity\HomeworkAssignment;
use App\Entity\HomeworkDefinition;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\TeacherAssignmentRole;
use App\Enum\UserStatus;
use App\Service\Homework\HomeworkAssignmentManager;
use App\Service\Homework\HomeworkDefinitionManager;
use App\Service\Homework\HomeworkDefinitionQuery;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/**
 * Teacher draft homework fixtures built on the Stage 2.10 readyClassroom pattern.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait HomeworkTestFixtures
{
    use AssessmentDeliveryTestFixtures;

    /**
     * @return array{
     *     owner: User,
     *     sa: User,
     *     institution: Institution,
     *     year: AcademicYear,
     *     classroom: Classroom,
     *     teacher: User,
     *     teacherMembership: InstitutionMembership,
     *     assignment: ClassroomTeacherAssignment
     * }
     */
    private function homeworkContext(string $prefix): array
    {
        [$owner, $sa, $institution, $year, $classroom] = $this->readyClassroom($prefix);
        $teacher = $this->activeUser($prefix.'-teacher@example.com');
        $membership = $this->membershipManager()->addMember(
            $institution,
            $owner,
            $teacher,
            InstitutionMembershipRole::Teacher,
            'add_teacher',
        );
        $assignment = $this->teacherManager()->assign(
            $classroom,
            $owner,
            $membership,
            TeacherAssignmentRole::HomeroomTeacher,
            'assign_teacher',
        );

        return [
            'owner' => $owner,
            'sa' => $sa,
            'institution' => $institution,
            'year' => $year,
            'classroom' => $classroom,
            'teacher' => $teacher,
            'teacherMembership' => $membership,
            'assignment' => $assignment,
        ];
    }

    /**
     * Another teacher in the same institution, assigned to the same classroom (co-teacher).
     *
     * @param array{owner: User, institution: Institution, classroom: Classroom} $ctx
     *
     * @return array{user: User, membership: InstitutionMembership, assignment: ClassroomTeacherAssignment}
     */
    private function addCoTeacher(array $ctx, string $email): array
    {
        $user = $this->activeUser($email);
        $membership = $this->membershipManager()->addMember(
            $ctx['institution'],
            $ctx['owner'],
            $user,
            InstitutionMembershipRole::Teacher,
            'add_co_teacher',
        );
        $assignment = $this->teacherManager()->assign(
            $ctx['classroom'],
            $ctx['owner'],
            $membership,
            TeacherAssignmentRole::AssistantTeacher,
            'assign_co_teacher',
        );

        return ['user' => $user, 'membership' => $membership, 'assignment' => $assignment];
    }

    private function draftInput(
        Classroom $classroom,
        string $title = 'Kesirler çalışma kâğıdı',
        string $description = 'Sayfa 12 ve 13’teki soruları çöz.',
        string $dueDate = '2026-11-12',
        string $dueTime = '18:30',
        ?int $expectedRevision = null,
    ): HomeworkDraftInput {
        return new HomeworkDraftInput($title, $description, $classroom->getId()->toRfc4122(), $dueDate, $dueTime, $expectedRevision);
    }

    private function freezeClock(string $utc = '2026-11-10 09:00:00'): MockClock
    {
        $clock = new MockClock(new \DateTimeImmutable($utc, new \DateTimeZone('UTC')));
        Clock::set($clock);

        return $clock;
    }

    private function resetClock(): void
    {
        Clock::set(new NativeClock());
    }

    private function homeworkManager(): HomeworkDefinitionManager
    {
        $manager = static::getContainer()->get(HomeworkDefinitionManager::class);
        self::assertInstanceOf(HomeworkDefinitionManager::class, $manager);

        return $manager;
    }

    private function homeworkQuery(): HomeworkDefinitionQuery
    {
        $query = static::getContainer()->get(HomeworkDefinitionQuery::class);
        self::assertInstanceOf(HomeworkDefinitionQuery::class, $query);

        return $query;
    }

    /**
     * Persist a draft without the manager (bulk fixtures). Due date is a UTC wall-clock string.
     *
     * @param array{teacher: User, teacherMembership: InstitutionMembership, classroom: Classroom} $ctx
     */
    private function persistDraft(array $ctx, string $title, string $dueUtc = '2026-12-01 10:00:00', ?Classroom $classroom = null): HomeworkDefinition
    {
        $definition = HomeworkDefinition::createDraft(
            $ctx['teacher'],
            $ctx['teacherMembership'],
            $classroom ?? $ctx['classroom'],
            $title,
            'Açıklama '.$title,
            new \DateTimeImmutable($dueUtc, new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-11-01 08:00:00', new \DateTimeZone('UTC')),
        );
        $this->em->persist($definition);
        $this->em->flush();

        return $definition;
    }

    private function setUserStatusRaw(User $user, UserStatus $status): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = :status WHERE id = :id',
            ['status' => $status->value, 'id' => $user->getId()->toBinary()],
        );
    }

    private function clearEmailVerificationRaw(User $user): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = :id',
            ['id' => $user->getId()->toBinary()],
        );
    }

    private function homeworkRowCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM homework_definitions');
    }

    /**
     * @return list<array{action: string, metadata: string}>
     */
    private function homeworkAuditRows(): array
    {
        /** @var list<array{action: string, metadata: string}> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT action, metadata FROM security_audit_events WHERE action LIKE 'homework_definition_%' ORDER BY occurred_at ASC, id ASC",
        );

        return $rows;
    }

    private function storedDueAt(HomeworkDefinition $definition): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT due_at FROM homework_definitions WHERE id = :id',
            ['id' => $definition->getId()->toBinary()],
        );
    }

    private function homeworkAssignmentManager(): HomeworkAssignmentManager
    {
        $manager = static::getContainer()->get(HomeworkAssignmentManager::class);
        self::assertInstanceOf(HomeworkAssignmentManager::class, $manager);

        return $manager;
    }

    /**
     * Creates and enrolls $n active students in the context classroom. Call before freezing the
     * clock; enrollment itself is not clock-sensitive.
     *
     * @param array{owner: User, institution: Institution, classroom: Classroom} $ctx
     *
     * @return list<InstitutionMembership>
     */
    private function enrollStudents(array $ctx, int $n): array
    {
        $memberships = [];
        for ($i = 1; $i <= $n; ++$i) {
            $student = $this->activeUser(\sprintf('hwstu-%s-%d@example.com', bin2hex(random_bytes(4)), $i));
            $membership = $this->membershipManager()->addMember(
                $ctx['institution'],
                $ctx['owner'],
                $student,
                InstitutionMembershipRole::Student,
                'add_student',
            );
            $this->enrollmentManager()->enroll($ctx['classroom'], $ctx['owner'], $membership, 'enroll_student');
            $memberships[] = $membership;
        }

        return $memberships;
    }

    /**
     * The active enrollment of a student membership in a classroom.
     */
    private function activeEnrollment(Classroom $classroom, InstitutionMembership $membership): ClassroomStudentEnrollment
    {
        $enrollment = $this->em->getRepository(ClassroomStudentEnrollment::class)->findOneBy([
            'classroom' => $classroom,
            'studentMembership' => $membership,
            'status' => StudentEnrollmentStatus::Active,
        ]);
        self::assertInstanceOf(ClassroomStudentEnrollment::class, $enrollment);

        return $enrollment;
    }

    /**
     * Assigns a draft through HomeworkAssignmentManager (the only supported write path).
     */
    private function assignHomework(User $teacher, Uuid $definitionId, int $revision, bool $confirmed = true): HomeworkAssignment
    {
        return $this->homeworkAssignmentManager()->assignToClassroom($teacher, $definitionId, $revision, $confirmed);
    }

    private function assignmentRowCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM homework_assignments');
    }

    private function recipientRowCount(?Uuid $assignmentId = null): int
    {
        if (null === $assignmentId) {
            return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM homework_recipients');
        }

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM homework_recipients WHERE assignment_id = :id',
            ['id' => $assignmentId->toBinary()],
        );
    }

    /**
     * @return list<array{action: string, metadata: string}>
     */
    private function assignmentAuditRows(): array
    {
        /** @var list<array{action: string, metadata: string}> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT action, metadata FROM security_audit_events WHERE action = 'homework_assignment_created' ORDER BY occurred_at ASC, id ASC",
        );

        return $rows;
    }

    private function cleanupHomework(): void
    {
        $connection = $this->em->getConnection();
        foreach (['homework_recipients', 'homework_assignments'] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
        if ($connection->createSchemaManager()->tablesExist(['homework_definitions'])) {
            $connection->executeStatement('DELETE FROM homework_definitions');
        }
        $this->cleanupDeliveryFixtures();
    }
}
