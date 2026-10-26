<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ClassroomStatus;

final class AdminClassroomListItemView
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $sectionCode,
        public readonly string $gradeLabel,
        public readonly ClassroomStatus $status,
        public readonly string $institutionId,
        public readonly string $institutionName,
        public readonly string $academicYearId,
        public readonly string $academicYearName,
        public readonly string $teacherSummary,
        public readonly int $activeStudentCount,
    ) {
    }
}
