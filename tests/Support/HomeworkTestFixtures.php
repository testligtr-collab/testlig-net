<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Dto\HomeworkDraftInput;
use App\Dto\StudentProfileRequest;
use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\ClassroomStudentEnrollment;
use App\Entity\ClassroomTeacherAssignment;
use App\Entity\HomeworkAssignment;
use App\Entity\HomeworkDefinition;
use App\Entity\HomeworkSubmission;
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
use App\Service\StudentProfileManager;
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
        // activeUser defaults to ROLE_STUDENT; student routes require a completed profile.
        $this->completeStudentOnboarding($teacher, $classroom);

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
        $profiles = static::getContainer()->get(StudentProfileManager::class);
        self::assertInstanceOf(StudentProfileManager::class, $profiles);
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
            // HTTP /ogrenci/* routes require completed onboarding (StudentOnboardingSubscriber).
            $dto = new StudentProfileRequest();
            $dto->gradeLevel = $ctx['classroom']->getGradeLevel();
            $profiles->completeOnboarding($student, $dto);
            $memberships[] = $membership;
        }

        return $memberships;
    }

    /**
     * Fixture teachers are created with ROLE_STUDENT; student-area GETs still need onboarding done.
     */
    private function completeStudentOnboarding(User $user, Classroom $classroom): void
    {
        $profiles = static::getContainer()->get(StudentProfileManager::class);
        self::assertInstanceOf(StudentProfileManager::class, $profiles);
        $dto = new StudentProfileRequest();
        $dto->gradeLevel = $classroom->getGradeLevel();
        $profiles->completeOnboarding($user, $dto);
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

    private function homeworkSubmissionManager(): \App\Service\Homework\HomeworkSubmissionManager
    {
        $manager = static::getContainer()->get(\App\Service\Homework\HomeworkSubmissionManager::class);
        self::assertInstanceOf(\App\Service\Homework\HomeworkSubmissionManager::class, $manager);

        return $manager;
    }

    private function homeworkStudentQuery(): \App\Service\Homework\HomeworkStudentQuery
    {
        $query = static::getContainer()->get(\App\Service\Homework\HomeworkStudentQuery::class);
        self::assertInstanceOf(\App\Service\Homework\HomeworkStudentQuery::class, $query);

        return $query;
    }

    private function submissionRowCount(?Uuid $assignmentId = null): int
    {
        if (!$this->em->getConnection()->createSchemaManager()->tablesExist(['homework_submissions'])) {
            return 0;
        }
        if (null === $assignmentId) {
            return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM homework_submissions');
        }

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM homework_submissions WHERE assignment_id = :id',
            ['id' => $assignmentId->toBinary()],
        );
    }

    /**
     * @return list<array{action: string, metadata: string}>
     */
    private function submissionAuditRows(): array
    {
        /** @var list<array{action: string, metadata: string}> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT action, metadata FROM security_audit_events WHERE action LIKE 'homework_submission_%' ORDER BY occurred_at ASC, id ASC",
        );

        return $rows;
    }

    private function homeworkEvaluationManager(): \App\Service\Homework\HomeworkEvaluationManager
    {
        $manager = static::getContainer()->get(\App\Service\Homework\HomeworkEvaluationManager::class);
        self::assertInstanceOf(\App\Service\Homework\HomeworkEvaluationManager::class, $manager);

        return $manager;
    }

    private function homeworkTeacherSubmissionQuery(): \App\Service\Homework\HomeworkTeacherSubmissionQuery
    {
        $query = static::getContainer()->get(\App\Service\Homework\HomeworkTeacherSubmissionQuery::class);
        self::assertInstanceOf(\App\Service\Homework\HomeworkTeacherSubmissionQuery::class, $query);

        return $query;
    }

    /**
     * Creates a draft in the context classroom and assigns it through the managers (the only
     * supported write path). Defaults match draftInput(): due 2026-11-12 18:30 Europe/Istanbul,
     * so the caller must have frozen the clock before 2026-11-12 15:30 UTC (see freezeClock()).
     *
     * @param array{teacher: User, classroom: Classroom} $ctx
     */
    private function assignClassroomHomework(
        array $ctx,
        string $title = 'Kesirler çalışma kâğıdı',
        string $dueDate = '2026-11-12',
        string $dueTime = '18:30',
        string $description = 'Sayfa 12 ve 13’teki soruları çöz.',
    ): HomeworkAssignment {
        $definition = $this->homeworkManager()->createDraft(
            $ctx['teacher'],
            $this->draftInput($ctx['classroom'], $title, $description, $dueDate, $dueTime),
        );

        return $this->assignHomework($ctx['teacher'], $definition->getId(), 1);
    }

    /**
     * One-shot student submit through HomeworkSubmissionManager (first write expects revision 0).
     */
    private function studentSubmit(User $student, HomeworkAssignment $assignment, string $body = 'Öğrencinin gönderdiği yanıt'): HomeworkSubmission
    {
        return $this->homeworkSubmissionManager()->submit($student, $assignment->getId(), $body, 0, true);
    }

    /**
     * Student draft (never submitted) through HomeworkSubmissionManager.
     */
    private function studentDraft(User $student, HomeworkAssignment $assignment, string $body = 'Yarım kalan taslak'): HomeworkSubmission
    {
        return $this->homeworkSubmissionManager()->saveDraft($student, $assignment->getId(), $body, 0);
    }

    /**
     * Ends the student's active enrollment (student "left the class"). Submitted work stays.
     *
     * @param array{owner: User, classroom: Classroom} $ctx
     */
    private function endStudentEnrollment(array $ctx, InstitutionMembership $studentMembership, string $reasonCode = 'left_class'): void
    {
        $this->enrollmentManager()->endEnrollment(
            $this->activeEnrollment($ctx['classroom'], $studentMembership),
            $ctx['owner'],
            $reasonCode,
        );
    }

    private function evaluationRowCount(?Uuid $submissionId = null): int
    {
        if (!$this->em->getConnection()->createSchemaManager()->tablesExist(['homework_evaluations'])) {
            return 0;
        }
        if (null === $submissionId) {
            return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM homework_evaluations');
        }

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM homework_evaluations WHERE submission_id = :id',
            ['id' => $submissionId->toBinary()],
        );
    }

    /**
     * Raw evaluation rows of one submission, oldest first.
     *
     * @return list<array{id: string, label: string, feedback: ?string, supersedes: ?string, recorded_at: string, evaluator: string}>
     */
    private function evaluationRows(Uuid $submissionId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, label, feedback, supersedes_evaluation_id, recorded_at, evaluated_by_user_id FROM homework_evaluations WHERE submission_id = :id ORDER BY recorded_at ASC, id ASC',
            ['id' => $submissionId->toBinary()],
        );

        return array_map(static fn (array $row): array => [
            'id' => Uuid::fromBinary((string) $row['id'])->toRfc4122(),
            'label' => (string) $row['label'],
            'feedback' => null === $row['feedback'] ? null : (string) $row['feedback'],
            'supersedes' => null === $row['supersedes_evaluation_id'] ? null : Uuid::fromBinary((string) $row['supersedes_evaluation_id'])->toRfc4122(),
            'recorded_at' => (string) $row['recorded_at'],
            'evaluator' => Uuid::fromBinary((string) $row['evaluated_by_user_id'])->toRfc4122(),
        ], $rows);
    }

    /**
     * Raw current-evaluation pointer of a submission (null when never evaluated).
     */
    private function currentEvaluationId(Uuid $submissionId): ?string
    {
        $value = $this->em->getConnection()->fetchOne(
            'SELECT current_evaluation_id FROM homework_submissions WHERE id = :id',
            ['id' => $submissionId->toBinary()],
        );
        if (false === $value || null === $value) {
            return null;
        }

        return Uuid::fromBinary((string) $value)->toRfc4122();
    }

    /**
     * @return list<array{action: string, metadata: string}>
     */
    private function evaluationAuditRows(): array
    {
        /** @var list<array{action: string, metadata: string}> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT action, metadata FROM security_audit_events WHERE action = 'homework_evaluation_recorded' ORDER BY occurred_at ASC, id ASC",
        );

        return $rows;
    }

    private function cleanupHomework(): void
    {
        $connection = $this->em->getConnection();
        // Evaluations and the submission pointer reference each other (RESTRICT): break the cycle first.
        if ($connection->createSchemaManager()->tablesExist(['homework_evaluations'])) {
            $connection->executeStatement('UPDATE homework_submissions SET current_evaluation_id = NULL');
            $connection->executeStatement('UPDATE homework_evaluations SET supersedes_evaluation_id = NULL');
            $connection->executeStatement('DELETE FROM homework_evaluations');
        }
        foreach (['homework_submissions', 'homework_recipients', 'homework_assignments'] as $table) {
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
