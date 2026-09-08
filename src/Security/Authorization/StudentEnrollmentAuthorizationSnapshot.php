<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Enum\StudentEnrollmentStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only authorization projection for an active student classroom enrollment.
 */
final readonly class StudentEnrollmentAuthorizationSnapshot
{
    public function __construct(
        public Uuid $id,
        public Uuid $classroomId,
        public Uuid $userId,
        public Uuid $membershipId,
        public StudentEnrollmentStatus $status,
    ) {
    }

    public function isActive(): bool
    {
        return StudentEnrollmentStatus::Active === $this->status;
    }
}
