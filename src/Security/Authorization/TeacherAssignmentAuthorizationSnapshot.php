<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Enum\TeacherAssignmentRole;
use App\Enum\TeacherAssignmentStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only authorization projection for an active teacher classroom assignment.
 */
final readonly class TeacherAssignmentAuthorizationSnapshot
{
    public function __construct(
        public Uuid $id,
        public Uuid $classroomId,
        public Uuid $userId,
        public Uuid $membershipId,
        public TeacherAssignmentRole $role,
        public TeacherAssignmentStatus $status,
    ) {
    }

    public function isActive(): bool
    {
        return TeacherAssignmentStatus::Active === $this->status;
    }
}
