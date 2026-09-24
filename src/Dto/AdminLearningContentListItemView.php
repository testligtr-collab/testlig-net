<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\GradeLevel;
use App\Enum\LearningContentStatus;
use App\Enum\LearningContentType;
use Symfony\Component\Uid\Uuid;

final class AdminLearningContentListItemView
{
    public function __construct(
        public readonly Uuid $id,
        public readonly string $title,
        public readonly string $code,
        public readonly LearningContentType $contentType,
        public readonly LearningContentStatus $status,
        public readonly GradeLevel $gradeLevel,
        public readonly string $subjectName,
        public readonly Uuid $subjectId,
        public readonly string $authorName,
        public readonly Uuid $createdById,
        public readonly ?string $accessClass,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }
}
