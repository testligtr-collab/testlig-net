<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionAssignableTeacher
{
    public function __construct(
        public string $reference,
        public string $name,
    ) {
    }
}
