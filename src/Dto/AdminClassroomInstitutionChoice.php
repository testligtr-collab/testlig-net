<?php

declare(strict_types=1);

namespace App\Dto;

final class AdminClassroomInstitutionChoice
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
    }
}
