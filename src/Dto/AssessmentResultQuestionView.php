<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Plain result text for one scored question.
 */
final readonly class AssessmentResultQuestionView
{
    /**
     * @param list<string>               $stem
     * @param list<string>               $explanation
     * @param list<array<string, mixed>> $stemBlocks
     * @param list<array<string, mixed>> $explanationBlocks
     */
    public function __construct(
        public int $position,
        public array $stem,
        public string $studentAnswer,
        public string $correctAnswer,
        public string $label,
        public array $explanation,
        public string $points,
        public array $stemBlocks,
        public array $explanationBlocks,
    ) {
    }
}
