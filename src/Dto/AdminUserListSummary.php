<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Unfiltered user totals for the admin list. These are not page-row counts.
 */
final class AdminUserListSummary
{
    public function __construct(
        public readonly int $total,
        public readonly int $active,
        public readonly int $pendingVerification,
        public readonly int $suspended,
    ) {
    }
}
