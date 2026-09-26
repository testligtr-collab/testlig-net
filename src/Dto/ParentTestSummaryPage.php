<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class ParentTestSummaryPage
{
    /**
     * @param list<ParentTestSummaryView> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $pages,
        public string $childReference,
        public string $childName,
    ) {
    }
}
