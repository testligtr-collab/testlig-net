<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionAcademicYearOption
{
    public function __construct(
        public string $reference,
        public string $name,
        public string $statusLabel,
    ) {
    }
}
