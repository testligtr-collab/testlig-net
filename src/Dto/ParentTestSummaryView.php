<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Parent-safe attempt summary. No answer key, selection, ciphertext, or ids.
 */
final readonly class ParentTestSummaryView
{
    public function __construct(
        public string $title,
        public string $subjectName,
        public string $statusLabel,
        public ?string $whenLabel,
        public bool $showScores,
        public ?string $points,
        public ?string $maximumPoints,
        public ?string $percentage,
        public ?int $correctCount,
        public ?int $incorrectCount,
        public ?int $blankCount,
    ) {
    }
}
