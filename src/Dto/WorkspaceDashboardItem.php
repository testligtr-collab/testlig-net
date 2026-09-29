<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * A short owned or reviewable row. The href is the only identifier on the page.
 */
final readonly class WorkspaceDashboardItem
{
    public function __construct(
        public string $title,
        public string $kind,
        public string $status,
        public string $updatedAt,
        public string $href,
    ) {
    }
}
