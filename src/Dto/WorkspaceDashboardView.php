<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Read-only workspace home. Counts stay inside the caller's owner or review scope.
 */
final readonly class WorkspaceDashboardView
{
    /**
     * @param list<WorkspaceDashboardCard>   $cards
     * @param list<WorkspaceDashboardAction> $actions
     * @param list<WorkspaceDashboardItem>   $recent
     * @param list<WorkspaceDashboardItem>   $pending
     */
    public function __construct(
        public string $intro,
        public array $cards,
        public array $actions,
        public array $recent,
        public array $pending,
        public bool $showContent,
        public bool $canReview,
    ) {
    }
}
