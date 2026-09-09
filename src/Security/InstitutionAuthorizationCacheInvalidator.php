<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Uid\Uuid;

/**
 * Request-scoped institution authorization snapshot invalidation.
 *
 * Cache is a performance optimization only; DB snapshots remain the source of truth.
 * Callers must invoke these methods only after a successful domain transaction commit.
 */
interface InstitutionAuthorizationCacheInvalidator
{
    public function invalidateUser(Uuid $userId): void;

    public function invalidateInstitution(Uuid $institutionId): void;

    public function invalidateMembership(Uuid $userId, Uuid $institutionId): void;

    public function invalidateInstitutionMemberships(Uuid $institutionId): void;

    public function invalidateClassroom(Uuid $classroomId): void;

    public function invalidateTeacherAssignment(Uuid $userId, Uuid $classroomId): void;

    public function invalidateStudentEnrollment(Uuid $userId, Uuid $classroomId): void;

    public function invalidateClassroomCourse(Uuid $classroomCourseId): void;

    public function invalidateCourseTeacherAssignment(Uuid $userId, Uuid $classroomCourseId): void;

    public function invalidateCurriculumProgram(Uuid $curriculumProgramId): void;

    public function reset(): void;
}
