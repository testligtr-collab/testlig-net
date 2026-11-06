<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ClassroomStatus;

final class AdminClassroomDetailView
{
    /**
     * @param list<AdminClassroomTeacherView> $teachers
     */
    public function __construct(
        public readonly string $id,
        public readonly string $shortRef,
        public readonly string $name,
        public readonly ?string $sectionCode,
        public readonly string $gradeLabel,
        public readonly ClassroomStatus $status,
        public readonly ?int $capacity,
        public readonly int $updatedAt,
        public readonly string $institutionId,
        public readonly string $institutionName,
        public readonly string $academicYearId,
        public readonly string $academicYearName,
        public readonly array $teachers,
        public readonly int $activeTeacherCount,
        public readonly int $endedTeacherCount,
        public readonly int $activeStudentCount,
        public readonly int $endedStudentCount,
        public readonly bool $canRevise,
        public readonly bool $canArchive,
    ) {
    }
}
