<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionClassroomOption
{
    public function __construct(
        public string $reference,
        public string $label,
    ) {
    }
}
