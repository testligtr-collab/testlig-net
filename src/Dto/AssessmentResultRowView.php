<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One authorized result row. Rank is the stable position in the filtered list.
 */
final readonly class AssessmentResultRowView
{
    public function __construct(
        public int $rank,
        public string $studentName,
        public string $title,
        public string $statusLabel,
        public string $startedAt,
        public ?string $completedAt,
        public bool $scored,
        public ?int $correct,
        public ?int $incorrect,
        public ?int $unanswered,
        public ?string $earned,
        public ?string $total,
        public ?string $percentage,
    ) {
    }
}
