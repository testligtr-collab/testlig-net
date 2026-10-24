<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Filter-independent institution totals for the admin list.
 */
final class AdminInstitutionListSummary
{
    public function __construct(
        public readonly int $total,
        public readonly int $active,
        public readonly int $pending,
        public readonly int $activeMemberships,
    ) {
    }
}
