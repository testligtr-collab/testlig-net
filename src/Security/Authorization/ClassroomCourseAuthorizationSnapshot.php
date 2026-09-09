<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Enum\ClassroomCourseStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only authorization projection for a classroom course.
 */
final readonly class ClassroomCourseAuthorizationSnapshot
{
    public function __construct(
        public Uuid $id,
        public Uuid $institutionId,
        public Uuid $classroomId,
        public Uuid $subjectId,
        public Uuid $curriculumProgramId,
        public ClassroomCourseStatus $status,
    ) {
    }

    public function isActive(): bool
    {
        return ClassroomCourseStatus::Active === $this->status;
    }
}
