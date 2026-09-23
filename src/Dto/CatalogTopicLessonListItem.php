<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Student-visible catalog topic lesson placement (navigation only; no content body).
 */
final class CatalogTopicLessonListItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $summary,
        public readonly int $position,
    ) {
    }
}
