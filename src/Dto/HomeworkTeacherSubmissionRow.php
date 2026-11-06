<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Teacher list row for a recipient. Never carries draft body.
 */
final readonly class HomeworkTeacherSubmissionRow
{
    public function __construct(
        public string $recipientId,
        public string $studentDisplayName,
        public string $stateKey,
        public string $stateLabel,
        public ?string $submissionId,
        public ?string $submittedAtLocal,
        public ?string $evaluationLabelKey,
        public ?string $evaluationLabelTr,
        public bool $canInspect,
    ) {
    }
}
