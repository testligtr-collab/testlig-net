<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\AcademicYearStatus;

final class AdminAcademicYearListItemView
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly AcademicYearStatus $status,
        public readonly string $rangeLabel,
        public readonly string $institutionId,
        public readonly string $institutionName,
    ) {
    }
}
