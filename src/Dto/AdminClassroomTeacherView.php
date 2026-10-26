<?php

declare(strict_types=1);

namespace App\Dto;

final class AdminClassroomTeacherView
{
    public function __construct(
        public readonly string $name,
        public readonly string $roleLabel,
        public readonly bool $homeroom,
    ) {
    }
}
