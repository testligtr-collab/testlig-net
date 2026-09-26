<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * bcmath summary of the attempts the actor is allowed to see.
 *
 * Average, highest, and lowest use completed runs only.
 * Percentage scale matches DecimalScoreCalculator (4).
 */
final readonly class AssessmentResultSummary
{
    public function __construct(
        public int $participants,
        public ?string $averagePercentage,
        public ?string $highestPercentage,
        public ?string $lowestPercentage,
        public int $completed,
        public int $inProgress,
    ) {
    }
}
