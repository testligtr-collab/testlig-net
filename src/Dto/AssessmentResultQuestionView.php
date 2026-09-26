<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Plain result text for one scored question.
 */
final readonly class AssessmentResultQuestionView
{
    /**
     * @param list<string> $stem
     * @param list<string> $explanation
     */
    public function __construct(
        public int $position,
        public array $stem,
        public string $studentAnswer,
        public string $correctAnswer,
        public string $label,
        public array $explanation,
    ) {
    }
}
