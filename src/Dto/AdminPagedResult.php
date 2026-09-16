<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Bounded page of admin list projections.
 *
 * @template T
 */
final class AdminPagedResult
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly int $totalCount,
    ) {
    }

    public function totalPages(): int
    {
        if ($this->pageSize < 1) {
            return 0;
        }

        return (int) max(1, (int) ceil($this->totalCount / $this->pageSize));
    }
}
