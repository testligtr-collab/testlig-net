<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InstitutionMembership;
use App\Enum\CourseTeacherAssignmentStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\TeacherAssignmentStatus;
use App\Exception\InstitutionMembershipException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-only classroom-link invariant checks for membership lifecycle mutations.
 * Uses fresh DBAL queries (not the ORM identity map). Does not depend on
 * InstitutionMembershipManager or assignment/enrollment managers.
 */
final class MembershipClassroomLinkChecker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param string $operation One of: change_role, suspend, end
     */
    public function assertMembershipMutationAllowed(
        InstitutionMembership $locked,
        ?InstitutionMembershipRole $newRole,
        string $operation,
    ): void {
        if ('change_role' === $operation) {
            $this->assertChangeRoleAllowed($locked, $newRole);

            return;
        }
        if ('suspend' === $operation || 'end' === $operation) {
            $this->assertStatusMutationAllowed($locked);

            return;
        }

        throw new \InvalidArgumentException(
            \sprintf('Unsupported membership classroom-link operation "%s".', $operation),
        );
    }

    public function assertNoActiveTeacherAssignmentsBlocking(InstitutionMembership $membership): void
    {
        if ($this->hasBlockingOrInconsistentTeacherLinks($membership)) {
            throw InstitutionMembershipException::activeClassroomLinkConflict();
        }
    }

    public function assertNoActiveStudentEnrollmentsBlocking(InstitutionMembership $membership): void
    {
        if ($this->hasBlockingOrInconsistentStudentLinks($membership)) {
            throw InstitutionMembershipException::activeClassroomLinkConflict();
        }
    }

    private function assertChangeRoleAllowed(
        InstitutionMembership $locked,
        ?InstitutionMembershipRole $newRole,
    ): void {
        if (!$newRole instanceof InstitutionMembershipRole) {
            throw new \InvalidArgumentException('newRole is required for change_role.');
        }

        $current = $locked->getRole();
        if (InstitutionMembershipRole::Teacher === $current
            && InstitutionMembershipRole::Teacher !== $newRole) {
            $this->assertNoActiveTeacherAssignmentsBlocking($locked);
        }
        if (InstitutionMembershipRole::Student === $current
            && InstitutionMembershipRole::Student !== $newRole) {
            $this->assertNoActiveStudentEnrollmentsBlocking($locked);
        }
    }

    private function assertStatusMutationAllowed(InstitutionMembership $locked): void
    {
        $role = $locked->getRole();
        if (InstitutionMembershipRole::Teacher === $role) {
            $this->assertNoActiveTeacherAssignmentsBlocking($locked);
        }
        if (InstitutionMembershipRole::Student === $role) {
            $this->assertNoActiveStudentEnrollmentsBlocking($locked);
        }
    }

    private function hasBlockingOrInconsistentTeacherLinks(InstitutionMembership $membership): bool
    {
        $connection = $this->entityManager->getConnection();
        $membershipId = $membership->getId()->toBinary();
        $institutionId = $membership->getInstitution()->getId()->toBinary();
        $active = TeacherAssignmentStatus::Active->value;

        // Active assignment without matching guard (vice versa handled below).
        $activeWithoutGuard = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM classroom_teacher_assignments a
             LEFT JOIN classroom_teacher_active_guards g ON g.assignment_id = a.id
             WHERE a.teacher_membership_id = ?
               AND a.institution_id = ?
               AND a.status = ?
               AND g.assignment_id IS NULL',
            [$membershipId, $institutionId, $active],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
        );
        if ($activeWithoutGuard > 0) {
            return true;
        }

        // Guard present but assignment missing / not active (institution via classrooms).
        $guardMismatch = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM classroom_teacher_active_guards g
             INNER JOIN classrooms c ON c.id = g.classroom_id AND c.institution_id = ?
             LEFT JOIN classroom_teacher_assignments a ON a.id = g.assignment_id
             WHERE g.teacher_membership_id = ?
               AND (a.id IS NULL OR a.status <> ? OR a.institution_id <> ?)',
            [$institutionId, $membershipId, $active, $institutionId],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::BINARY],
        );
        if ($guardMismatch > 0) {
            return true;
        }

        // Consistent active assignment + guard blocks mutation.
        $blocking = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM classroom_teacher_assignments a
             INNER JOIN classroom_teacher_active_guards g ON g.assignment_id = a.id
             WHERE a.teacher_membership_id = ?
               AND a.institution_id = ?
               AND a.status = ?',
            [$membershipId, $institutionId, $active],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
        );
        if ($blocking > 0) {
            return true;
        }

        return $this->hasBlockingOrInconsistentCourseTeacherLinks($membershipId, $institutionId);
    }

    private function hasBlockingOrInconsistentCourseTeacherLinks(string $membershipId, string $institutionId): bool
    {
        $connection = $this->entityManager->getConnection();
        $active = CourseTeacherAssignmentStatus::Active->value;

        $activeWithoutGuard = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM course_teacher_assignments a
             LEFT JOIN course_teacher_active_guards g ON g.assignment_id = a.id
             WHERE a.teacher_membership_id = ?
               AND a.institution_id = ?
               AND a.status = ?
               AND g.assignment_id IS NULL',
            [$membershipId, $institutionId, $active],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
        );
        if ($activeWithoutGuard > 0) {
            return true;
        }

        $guardMismatch = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM course_teacher_active_guards g
             INNER JOIN classroom_courses c ON c.id = g.classroom_course_id AND c.institution_id = ?
             LEFT JOIN course_teacher_assignments a ON a.id = g.assignment_id
             WHERE g.teacher_membership_id = ?
               AND (a.id IS NULL OR a.status <> ? OR a.institution_id <> ?)',
            [$institutionId, $membershipId, $active, $institutionId],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::BINARY],
        );
        if ($guardMismatch > 0) {
            return true;
        }

        $blocking = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM course_teacher_assignments a
             INNER JOIN course_teacher_active_guards g ON g.assignment_id = a.id
             WHERE a.teacher_membership_id = ?
               AND a.institution_id = ?
               AND a.status = ?',
            [$membershipId, $institutionId, $active],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
        );

        return $blocking > 0;
    }

    private function hasBlockingOrInconsistentStudentLinks(InstitutionMembership $membership): bool
    {
        $connection = $this->entityManager->getConnection();
        $membershipId = $membership->getId()->toBinary();
        $institutionId = $membership->getInstitution()->getId()->toBinary();
        $active = StudentEnrollmentStatus::Active->value;

        $activeWithoutGuard = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM classroom_student_enrollments e
             LEFT JOIN academic_year_student_enrollment_guards g ON g.enrollment_id = e.id
             WHERE e.student_membership_id = ?
               AND e.institution_id = ?
               AND e.status = ?
               AND g.enrollment_id IS NULL',
            [$membershipId, $institutionId, $active],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
        );
        if ($activeWithoutGuard > 0) {
            return true;
        }

        $guardMismatch = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM academic_year_student_enrollment_guards g
             INNER JOIN academic_years y ON y.id = g.academic_year_id AND y.institution_id = ?
             LEFT JOIN classroom_student_enrollments e ON e.id = g.enrollment_id
             WHERE g.student_membership_id = ?
               AND (e.id IS NULL OR e.status <> ? OR e.institution_id <> ?)',
            [$institutionId, $membershipId, $active, $institutionId],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::BINARY],
        );
        if ($guardMismatch > 0) {
            return true;
        }

        $blocking = (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM classroom_student_enrollments e
             INNER JOIN academic_year_student_enrollment_guards g ON g.enrollment_id = e.id
             WHERE e.student_membership_id = ?
               AND e.institution_id = ?
               AND e.status = ?',
            [$membershipId, $institutionId, $active],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
        );

        return $blocking > 0;
    }
}
