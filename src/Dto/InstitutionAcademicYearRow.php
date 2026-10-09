<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionAcademicYearRow
{
    public function __construct(
        public string $reference,
        public string $name,
        public string $statusLabel,
        public string $startsOn,
        public string $endsOn,
        public bool $canActivate,
    ) {
    }
}
