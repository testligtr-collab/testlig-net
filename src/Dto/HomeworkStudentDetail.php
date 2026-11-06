<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Student homework detail. Immutable presentation DTO; no entity leakage.
 */
final readonly class HomeworkStudentDetail
{
    public function __construct(
        public string $assignmentId,
        public string $title,
        public string $description,
        public string $classroomName,
        public string $dueAtLocal,
        public bool $duePassed,
        public string $statusKey,
        public string $statusLabel,
        public string $body,
        public int $revisionNumber,
        public bool $writable,
        public bool $submitted,
        public ?string $submittedAtLocal,
        public string $closedReason,
        public ?string $evaluationLabelKey = null,
        public ?string $evaluationLabelTr = null,
        public ?string $feedback = null,
    ) {
    }
}
