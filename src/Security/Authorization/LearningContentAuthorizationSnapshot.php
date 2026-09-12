<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Enum\LearningContentScope;
use App\Enum\LearningContentStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only authorization projection for learning content.
 */
final readonly class LearningContentAuthorizationSnapshot
{
    public function __construct(
        public Uuid $id,
        public LearningContentScope $scope,
        public ?Uuid $institutionId,
        public Uuid $subjectId,
        public Uuid $createdById,
        public LearningContentStatus $status,
    ) {
    }

    public function isPlatform(): bool
    {
        return LearningContentScope::Platform === $this->scope;
    }

    public function isPublished(): bool
    {
        return LearningContentStatus::Published === $this->status;
    }

    public function isArchived(): bool
    {
        return LearningContentStatus::Archived === $this->status;
    }
}
