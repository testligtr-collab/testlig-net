<?php

declare(strict_types=1);

namespace App\Dto;

final class AdminClassroomListSummary
{
    public function __construct(
        public readonly int $total,
        public readonly int $active,
        public readonly int $activeWithoutActiveTeacher,
        public readonly int $linkedInstitutions,
    ) {
    }
}
