<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Enum\CourseTeacherAssignmentStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only authorization projection for an active course teacher assignment.
 */
final readonly class CourseTeacherAssignmentAuthorizationSnapshot
{
    public function __construct(
        public Uuid $id,
        public Uuid $classroomCourseId,
        public Uuid $userId,
        public Uuid $membershipId,
        public CourseTeacherAssignmentStatus $status,
    ) {
    }

    public function isActive(): bool
    {
        return CourseTeacherAssignmentStatus::Active === $this->status;
    }
}
