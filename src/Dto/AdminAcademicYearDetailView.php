<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\AcademicYearStatus;
use App\Enum\InstitutionStatus;

final class AdminAcademicYearDetailView
{
    public function __construct(
        public readonly string $id,
        public readonly string $shortRef,
        public readonly string $name,
        public readonly AcademicYearStatus $status,
        public readonly string $rangeLabel,
        public readonly string $institutionId,
        public readonly string $institutionName,
        public readonly InstitutionStatus $institutionStatus,
        public readonly ?string $activeYearId,
        public readonly ?string $activeYearName,
        public readonly bool $canActivate,
    ) {
    }
}
