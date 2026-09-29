<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One real count on the workspace home. No trend, target, or chart payload.
 */
final readonly class WorkspaceDashboardCard
{
    public function __construct(
        public string $key,
        public string $label,
        public int $value,
        public string $hint,
        public ?string $href,
    ) {
    }
}
