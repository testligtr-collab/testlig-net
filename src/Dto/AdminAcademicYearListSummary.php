<?php

declare(strict_types=1);

namespace App\Dto;

final class AdminAcademicYearListSummary
{
    public function __construct(
        public readonly int $total,
        public readonly int $active,
        public readonly int $activeInstitutionsWithoutActiveYear,
        public readonly int $linkedInstitutions,
    ) {
    }
}
