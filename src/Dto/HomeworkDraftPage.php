<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Paginated teacher homework list plus real draft/assigned counters for the same auth scope.
 */
final readonly class HomeworkDraftPage
{
    /**
     * @param list<HomeworkDraftRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $page,
        public int $pageCount,
        public int $draftCount = 0,
        public int $assignedCount = 0,
    ) {
    }
}
