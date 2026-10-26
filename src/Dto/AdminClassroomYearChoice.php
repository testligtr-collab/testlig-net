<?php

declare(strict_types=1);

namespace App\Dto;

final class AdminClassroomYearChoice
{
    public function __construct(
        public readonly string $id,
        public readonly string $institutionId,
        public readonly string $label,
    ) {
    }
}
