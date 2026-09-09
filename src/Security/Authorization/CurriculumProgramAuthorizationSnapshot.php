<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Enum\CurriculumStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only authorization projection for a curriculum program.
 */
final readonly class CurriculumProgramAuthorizationSnapshot
{
    public function __construct(
        public Uuid $id,
        public CurriculumStatus $status,
    ) {
    }

    public function isPublished(): bool
    {
        return CurriculumStatus::Published === $this->status;
    }
}
