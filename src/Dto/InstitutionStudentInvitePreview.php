<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionStudentInvitePreview
{
    public function __construct(
        public string $institutionName,
        public string $classroomName,
        public string $expiresAtLabel,
    ) {
    }
}
