<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Unfiltered institution-application totals. These are not the filtered page count.
 */
final class AdminInstitutionApplicationSummary
{
    public function __construct(
        public readonly int $total,
        public readonly int $pending,
        public readonly int $approved,
        public readonly int $rejected,
        public readonly int $withdrawn,
        public readonly int $superseded,
    ) {
    }
}
