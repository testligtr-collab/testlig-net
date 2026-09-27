<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionAccountMembership
{
    /**
     * @param list<string> $classroomNames
     */
    public function __construct(
        public string $institutionName,
        public string $roleLabel,
        public string $statusLabel,
        public array $classroomNames,
    ) {
    }
}
