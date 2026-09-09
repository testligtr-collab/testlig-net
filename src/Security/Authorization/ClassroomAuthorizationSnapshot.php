<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Enum\ClassroomStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only authorization projection for a classroom.
 */
final readonly class ClassroomAuthorizationSnapshot
{
    public function __construct(
        public Uuid $id,
        public Uuid $institutionId,
        public Uuid $academicYearId,
        public ClassroomStatus $status,
    ) {
    }

    public function isActive(): bool
    {
        return ClassroomStatus::Active === $this->status;
    }
}
