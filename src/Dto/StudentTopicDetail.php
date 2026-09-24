<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Student topic page read model (typed block views; no revision UUID / storageKey / raw JSON).
 */
final class StudentTopicDetail
{
    /**
     * @param list<CatalogTopicLessonListItem> $lessons
     */
    public function __construct(
        public readonly CatalogSubjectListItem $subject,
        public readonly CatalogUnitListItem $unit,
        public readonly CatalogTopicListItem $topic,
        public readonly array $lessons,
    ) {
    }
}
