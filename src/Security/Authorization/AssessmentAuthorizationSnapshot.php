<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only authorization projection for an assessment.
 */
final readonly class AssessmentAuthorizationSnapshot
{
    public function __construct(
        public Uuid $id,
        public AssessmentScope $scope,
        public ?Uuid $institutionId,
        public Uuid $createdById,
        public AssessmentStatus $status,
        public int $currentRevisionNumber,
        public ?int $publishedRevisionNumber,
    ) {
    }

    public function isPlatform(): bool
    {
        return AssessmentScope::Platform === $this->scope;
    }

    public function isPublished(): bool
    {
        return AssessmentStatus::Published === $this->status;
    }

    public function isArchived(): bool
    {
        return AssessmentStatus::Archived === $this->status;
    }
}
