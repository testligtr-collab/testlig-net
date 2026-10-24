<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Unfiltered teacher-account totals. These are not the filtered page count.
 */
final class AdminTeacherDirectorySummary
{
    public function __construct(
        public readonly int $total,
        public readonly int $active,
        public readonly int $verified,
        public readonly int $withProfile,
    ) {
    }
}
