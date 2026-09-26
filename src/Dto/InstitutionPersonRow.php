<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionPersonRow
{
    public function __construct(
        public string $name,
        public string $roleLabel,
        public string $statusLabel,
        public ?string $gradeLabel,
        public ?string $classroomName,
        public ?int $assignedClassroomCount,
    ) {
    }
}
