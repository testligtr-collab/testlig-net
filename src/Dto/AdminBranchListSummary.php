<?php

declare(strict_types=1);

namespace App\Dto;

final class AdminBranchListSummary
{
    public function __construct(
        public readonly int $total,
        public readonly int $active,
        public readonly int $pending,
        public readonly int $linkedInstitutions,
    ) {
    }
}
