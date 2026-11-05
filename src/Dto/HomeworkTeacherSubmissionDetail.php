<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Teacher view of one submitted homework. Immutable presentation DTO.
 */
final readonly class HomeworkTeacherSubmissionDetail
{
    public function __construct(
        public string $submissionId,
        public string $definitionId,
        public string $assignmentId,
        public string $title,
        public string $description,
        public string $classroomName,
        public string $studentDisplayName,
        public string $submittedAtLocal,
        public string $body,
        public ?string $currentEvaluationId,
        public ?string $evaluationLabelKey,
        public ?string $evaluationLabelTr,
        public ?string $feedback,
        public ?string $evaluatedAtLocal,
    ) {
    }
}
