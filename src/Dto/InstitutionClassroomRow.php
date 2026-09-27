<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionClassroomRow
{
    public function __construct(
        public string $reference,
        public string $name,
        public string $gradeLabel,
        public string $academicYearName,
        public ?string $sectionCode,
        public ?string $capacityLabel,
        public string $statusLabel,
        public string $createdAtLabel,
        public string $updatedAtLabel,
        public string $updatedAtToken,
        public bool $canEdit,
        public int $teacherCount,
        public int $studentCount,
    ) {
    }
}
