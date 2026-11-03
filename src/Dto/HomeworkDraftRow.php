<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * List row for a teacher draft. Never carries the description body.
 */
final readonly class HomeworkDraftRow
{
    public function __construct(
        public string $id,
        public string $title,
        public \DateTimeImmutable $dueAt,
        public string $classroomName,
        public string $institutionName,
    ) {
    }
}
