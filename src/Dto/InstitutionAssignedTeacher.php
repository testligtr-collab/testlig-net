<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionAssignedTeacher
{
    public function __construct(
        public string $name,
        public string $roleLabel,
        public string $assignmentReference,
    ) {
    }
}
