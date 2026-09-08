<?php

declare(strict_types=1);

namespace App\Security;

use App\Enum\ClassroomStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\TeacherAssignmentRole;
use App\Enum\TeacherAssignmentStatus;
use App\Enum\UserStatus;
use App\Security\Authorization\ClassroomAuthorizationSnapshot;
use App\Security\Authorization\InstitutionAuthorizationSnapshot;
use App\Security\Authorization\MembershipAuthorizationSnapshot;
use App\Security\Authorization\StudentEnrollmentAuthorizationSnapshot;
use App\Security\Authorization\TeacherAssignmentAuthorizationSnapshot;
use App\Security\Authorization\UserAuthorizationSnapshot;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped DB authorization snapshots for InstitutionVoter and ClassroomVoter.
 *
 * Reads scalar projections via DBAL — never returns Doctrine-managed entities.
 * Cache is per request ({@see ResetInterface}) and must be invalidated after successful
 * domain commits via {@see InstitutionAuthorizationCacheInvalidator}.
 */
final class RequestScopedInstitutionAuthLookup implements InstitutionAuthorizationCacheInvalidator, ResetInterface
{
    /** @var array<string, UserAuthorizationSnapshot|null> */
    private array $users = [];

    /** @var array<string, InstitutionAuthorizationSnapshot|null> */
    private array $institutions = [];

    /** @var array<string, MembershipAuthorizationSnapshot|null> */
    private array $memberships = [];

    /** @var array<string, ClassroomAuthorizationSnapshot|null> */
    private array $classrooms = [];

    /** @var array<string, TeacherAssignmentAuthorizationSnapshot|null> */
    private array $teacherAssignments = [];

    /** @var array<string, StudentEnrollmentAuthorizationSnapshot|null> */
    private array $studentEnrollments = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function getUserSnapshot(Uuid $userId): ?UserAuthorizationSnapshot
    {
        $key = $userId->toRfc4122();
        if (!\array_key_exists($key, $this->users)) {
            $this->users[$key] = $this->fetchUserSnapshot($userId);
        }

        return $this->users[$key];
    }

    public function getInstitutionSnapshot(Uuid $institutionId): ?InstitutionAuthorizationSnapshot
    {
        $key = $institutionId->toRfc4122();
        if (!\array_key_exists($key, $this->institutions)) {
            $this->institutions[$key] = $this->fetchInstitutionSnapshot($institutionId);
        }

        return $this->institutions[$key];
    }

    public function getMembershipSnapshot(Uuid $userId, Uuid $institutionId): ?MembershipAuthorizationSnapshot
    {
        $key = $this->membershipKey($userId, $institutionId);
        if (!\array_key_exists($key, $this->memberships)) {
            $this->memberships[$key] = $this->fetchMembershipSnapshot($userId, $institutionId);
        }

        return $this->memberships[$key];
    }

    public function getClassroomSnapshot(Uuid $classroomId): ?ClassroomAuthorizationSnapshot
    {
        $key = $classroomId->toRfc4122();
        if (!\array_key_exists($key, $this->classrooms)) {
            $this->classrooms[$key] = $this->fetchClassroomSnapshot($classroomId);
        }

        return $this->classrooms[$key];
    }

    public function getTeacherAssignmentSnapshot(Uuid $userId, Uuid $classroomId): ?TeacherAssignmentAuthorizationSnapshot
    {
        $key = $this->assignmentKey($userId, $classroomId);
        if (!\array_key_exists($key, $this->teacherAssignments)) {
            $this->teacherAssignments[$key] = $this->fetchTeacherAssignmentSnapshot($userId, $classroomId);
        }

        return $this->teacherAssignments[$key];
    }

    public function getStudentEnrollmentSnapshot(Uuid $userId, Uuid $classroomId): ?StudentEnrollmentAuthorizationSnapshot
    {
        $key = $this->enrollmentKey($userId, $classroomId);
        if (!\array_key_exists($key, $this->studentEnrollments)) {
            $this->studentEnrollments[$key] = $this->fetchStudentEnrollmentSnapshot($userId, $classroomId);
        }

        return $this->studentEnrollments[$key];
    }

    public function invalidateUser(Uuid $userId): void
    {
        $this->safe(function () use ($userId): void {
            unset($this->users[$userId->toRfc4122()]);
            $prefix = $userId->toRfc4122().'|';
            foreach (array_keys($this->memberships) as $key) {
                if (str_starts_with($key, $prefix)) {
                    unset($this->memberships[$key]);
                }
            }
            foreach (array_keys($this->teacherAssignments) as $key) {
                if (str_starts_with($key, $prefix)) {
                    unset($this->teacherAssignments[$key]);
                }
            }
            foreach (array_keys($this->studentEnrollments) as $key) {
                if (str_starts_with($key, $prefix)) {
                    unset($this->studentEnrollments[$key]);
                }
            }
        });
    }

    public function invalidateInstitution(Uuid $institutionId): void
    {
        $this->safe(function () use ($institutionId): void {
            unset($this->institutions[$institutionId->toRfc4122()]);
            $this->dropMembershipsForInstitution($institutionId);
            $this->classrooms = [];
            $this->teacherAssignments = [];
            $this->studentEnrollments = [];
        });
    }

    public function invalidateMembership(Uuid $userId, Uuid $institutionId): void
    {
        $this->safe(function () use ($userId, $institutionId): void {
            unset($this->memberships[$this->membershipKey($userId, $institutionId)]);
            $prefix = $userId->toRfc4122().'|';
            foreach (array_keys($this->teacherAssignments) as $key) {
                if (str_starts_with($key, $prefix)) {
                    unset($this->teacherAssignments[$key]);
                }
            }
            foreach (array_keys($this->studentEnrollments) as $key) {
                if (str_starts_with($key, $prefix)) {
                    unset($this->studentEnrollments[$key]);
                }
            }
        });
    }

    public function invalidateInstitutionMemberships(Uuid $institutionId): void
    {
        $this->safe(function () use ($institutionId): void {
            $this->dropMembershipsForInstitution($institutionId);
            $this->teacherAssignments = [];
            $this->studentEnrollments = [];
        });
    }

    public function invalidateClassroom(Uuid $classroomId): void
    {
        $this->safe(function () use ($classroomId): void {
            unset($this->classrooms[$classroomId->toRfc4122()]);
            $suffix = '|'.$classroomId->toRfc4122();
            foreach (array_keys($this->teacherAssignments) as $key) {
                if (str_ends_with($key, $suffix)) {
                    unset($this->teacherAssignments[$key]);
                }
            }
            foreach (array_keys($this->studentEnrollments) as $key) {
                if (str_ends_with($key, $suffix)) {
                    unset($this->studentEnrollments[$key]);
                }
            }
        });
    }

    public function invalidateTeacherAssignment(Uuid $userId, Uuid $classroomId): void
    {
        $this->safe(function () use ($userId, $classroomId): void {
            unset($this->teacherAssignments[$this->assignmentKey($userId, $classroomId)]);
        });
    }

    public function invalidateStudentEnrollment(Uuid $userId, Uuid $classroomId): void
    {
        $this->safe(function () use ($userId, $classroomId): void {
            unset($this->studentEnrollments[$this->enrollmentKey($userId, $classroomId)]);
        });
    }

    public function reset(): void
    {
        $this->users = [];
        $this->institutions = [];
        $this->memberships = [];
        $this->classrooms = [];
        $this->teacherAssignments = [];
        $this->studentEnrollments = [];
    }

    private function dropMembershipsForInstitution(Uuid $institutionId): void
    {
        $suffix = '|'.$institutionId->toRfc4122();
        foreach (array_keys($this->memberships) as $key) {
            if (str_ends_with($key, $suffix)) {
                unset($this->memberships[$key]);
            }
        }
    }

    private function membershipKey(Uuid $userId, Uuid $institutionId): string
    {
        return $userId->toRfc4122().'|'.$institutionId->toRfc4122();
    }

    private function assignmentKey(Uuid $userId, Uuid $classroomId): string
    {
        return $userId->toRfc4122().'|'.$classroomId->toRfc4122();
    }

    private function enrollmentKey(Uuid $userId, Uuid $classroomId): string
    {
        return $userId->toRfc4122().'|'.$classroomId->toRfc4122();
    }

    /**
     * Invalidation must not break the request; fall back to full reset.
     */
    private function safe(callable $operation): void
    {
        try {
            $operation();
        } catch (\Throwable) {
            $this->reset();
        }
    }

    private function fetchUserSnapshot(Uuid $userId): ?UserAuthorizationSnapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, status, email_verified_at, global_roles FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId->toBinary()],
        );
        if (false === $row) {
            return null;
        }

        $decoded = json_decode((string) $row['global_roles'], true, 512, \JSON_THROW_ON_ERROR);
        $roles = [];
        if (\is_array($decoded)) {
            foreach ($decoded as $role) {
                if (\is_string($role) && '' !== $role) {
                    $roles[] = $role;
                }
            }
        }
        $roles = array_values(array_unique($roles));
        if (!\in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }
        sort($roles);

        return new UserAuthorizationSnapshot(
            id: $this->uuidFromBinary($row['id']),
            status: UserStatus::from((string) $row['status']),
            emailVerified: null !== $row['email_verified_at'],
            roles: $roles,
        );
    }

    private function fetchInstitutionSnapshot(Uuid $institutionId): ?InstitutionAuthorizationSnapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, status FROM institutions WHERE id = :id LIMIT 1',
            ['id' => $institutionId->toBinary()],
        );
        if (false === $row) {
            return null;
        }

        return new InstitutionAuthorizationSnapshot(
            id: $this->uuidFromBinary($row['id']),
            status: InstitutionStatus::from((string) $row['status']),
        );
    }

    private function fetchMembershipSnapshot(Uuid $userId, Uuid $institutionId): ?MembershipAuthorizationSnapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, institution_id, user_id, role, status
             FROM institution_memberships
             WHERE user_id = :userId AND institution_id = :institutionId
             LIMIT 1',
            [
                'userId' => $userId->toBinary(),
                'institutionId' => $institutionId->toBinary(),
            ],
        );
        if (false === $row) {
            return null;
        }

        return new MembershipAuthorizationSnapshot(
            id: $this->uuidFromBinary($row['id']),
            institutionId: $this->uuidFromBinary($row['institution_id']),
            userId: $this->uuidFromBinary($row['user_id']),
            role: InstitutionMembershipRole::from((string) $row['role']),
            status: InstitutionMembershipStatus::from((string) $row['status']),
        );
    }

    private function fetchClassroomSnapshot(Uuid $classroomId): ?ClassroomAuthorizationSnapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, institution_id, academic_year_id, status FROM classrooms WHERE id = :id LIMIT 1',
            ['id' => $classroomId->toBinary()],
        );
        if (false === $row) {
            return null;
        }

        return new ClassroomAuthorizationSnapshot(
            id: $this->uuidFromBinary($row['id']),
            institutionId: $this->uuidFromBinary($row['institution_id']),
            academicYearId: $this->uuidFromBinary($row['academic_year_id']),
            status: ClassroomStatus::from((string) $row['status']),
        );
    }

    private function fetchTeacherAssignmentSnapshot(Uuid $userId, Uuid $classroomId): ?TeacherAssignmentAuthorizationSnapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT a.id, a.classroom_id, a.teacher_membership_id, a.role, a.status, m.user_id
             FROM classroom_teacher_assignments a
             INNER JOIN institution_memberships m ON m.id = a.teacher_membership_id
             WHERE a.classroom_id = :classroomId
               AND m.user_id = :userId
               AND a.status = :status
             LIMIT 1',
            [
                'classroomId' => $classroomId->toBinary(),
                'userId' => $userId->toBinary(),
                'status' => TeacherAssignmentStatus::Active->value,
            ],
        );
        if (false === $row) {
            return null;
        }

        return new TeacherAssignmentAuthorizationSnapshot(
            id: $this->uuidFromBinary($row['id']),
            classroomId: $this->uuidFromBinary($row['classroom_id']),
            userId: $this->uuidFromBinary($row['user_id']),
            membershipId: $this->uuidFromBinary($row['teacher_membership_id']),
            role: TeacherAssignmentRole::from((string) $row['role']),
            status: TeacherAssignmentStatus::from((string) $row['status']),
        );
    }

    private function fetchStudentEnrollmentSnapshot(Uuid $userId, Uuid $classroomId): ?StudentEnrollmentAuthorizationSnapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT e.id, e.classroom_id, e.student_membership_id, e.status, m.user_id
             FROM classroom_student_enrollments e
             INNER JOIN institution_memberships m ON m.id = e.student_membership_id
             WHERE e.classroom_id = :classroomId
               AND m.user_id = :userId
               AND e.status = :status
             LIMIT 1',
            [
                'classroomId' => $classroomId->toBinary(),
                'userId' => $userId->toBinary(),
                'status' => StudentEnrollmentStatus::Active->value,
            ],
        );
        if (false === $row) {
            return null;
        }

        return new StudentEnrollmentAuthorizationSnapshot(
            id: $this->uuidFromBinary($row['id']),
            classroomId: $this->uuidFromBinary($row['classroom_id']),
            userId: $this->uuidFromBinary($row['user_id']),
            membershipId: $this->uuidFromBinary($row['student_membership_id']),
            status: StudentEnrollmentStatus::from((string) $row['status']),
        );
    }

    private function uuidFromBinary(mixed $binary): Uuid
    {
        return Uuid::fromBinary((string) $binary);
    }
}
