<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * List row for a teacher's homework definition. Never carries the description body.
 * assigned=true means a classroom HomeworkAssignment exists (UI label "Atandı").
 */
final readonly class HomeworkDraftRow
{
    public function __construct(
        public string $id,
        public string $title,
        public \DateTimeImmutable $dueAt,
        public string $classroomName,
        public string $institutionName,
        public bool $assigned = false,
        public ?int $recipientCount = null,
    ) {
    }
}
