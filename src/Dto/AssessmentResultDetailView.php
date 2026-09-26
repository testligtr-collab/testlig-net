<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Authorized read-only attempt detail. Answer keys never leave this projection.
 */
final readonly class AssessmentResultDetailView
{
    /**
     * @param list<AssessmentResultQuestionView> $questions
     */
    public function __construct(
        public string $studentName,
        public string $title,
        public string $statusLabel,
        public string $startedAt,
        public ?string $completedAt,
        public bool $revealed,
        public ?int $correct,
        public ?int $incorrect,
        public ?int $unanswered,
        public ?string $earned,
        public ?string $total,
        public ?string $percentage,
        public array $questions,
    ) {
    }
}
