<?php

declare(strict_types=1);

namespace App\Dto;

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
    ) {
    }
}
