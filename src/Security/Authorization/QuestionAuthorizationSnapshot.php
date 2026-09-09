<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Enum\QuestionScope;
use App\Enum\QuestionStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only authorization projection for a question.
 */
final readonly class QuestionAuthorizationSnapshot
{
    public function __construct(
        public Uuid $id,
        public QuestionScope $scope,
        public ?Uuid $institutionId,
        public Uuid $subjectId,
        public Uuid $createdById,
        public QuestionStatus $status,
    ) {
    }

    public function isPlatform(): bool
    {
        return QuestionScope::Platform === $this->scope;
    }

    public function isPublished(): bool
    {
        return QuestionStatus::Published === $this->status;
    }

    public function isArchived(): bool
    {
        return QuestionStatus::Archived === $this->status;
    }
}
