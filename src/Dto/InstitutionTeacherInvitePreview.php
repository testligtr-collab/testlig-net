<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionTeacherInvitePreview
{
    public function __construct(
        public string $institutionName,
        public string $expiresAtLabel,
        public bool $accountExists,
    ) {
    }
}
