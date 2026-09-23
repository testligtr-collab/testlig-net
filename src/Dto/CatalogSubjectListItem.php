<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\CatalogSubject;
use App\Enum\CatalogPublicationStatus;
use App\Enum\GradeLevel;

final class CatalogSubjectListItem
{
    public function __construct(
        public readonly string $id,
        public readonly GradeLevel $gradeLevel,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly CatalogPublicationStatus $status,
        public readonly int $position,
    ) {
    }

    public static function fromEntity(CatalogSubject $subject): self
    {
        return new self(
            $subject->getId()->toRfc4122(),
            $subject->getGradeLevel(),
            $subject->getName(),
            $subject->getSlug(),
            $subject->getDescription(),
            $subject->getStatus(),
            $subject->getPosition(),
        );
    }
}
