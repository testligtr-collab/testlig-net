<?php

declare(strict_types=1);

namespace App\Dto;

use App\Dto\StudentContent\StudentContentBlockView;

/**
 * Student-visible catalog topic lesson (meta + normalized typed body blocks).
 *
 * No placement/revision UUID, storageKey, audit notes, or raw JSON.
 */
final class CatalogTopicLessonListItem
{
    /**
     * @param list<StudentContentBlockView> $blocks
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $summary,
        public readonly int $position,
        public readonly array $blocks = [],
    ) {
    }
}
