<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\QuestionType;

/**
 * Safe correct-answer presentation for student review (never raw answer-key field names).
 */
final class CorrectAnswerPresentation
{
    /**
     * @param list<string>|null $stableKeys
     * @param list<string>|null $acceptedTexts
     */
    private function __construct(
        private readonly QuestionType $questionType,
        private readonly ?string $stableKey = null,
        private readonly ?array $stableKeys = null,
        private readonly ?bool $booleanValue = null,
        private readonly ?string $numericValue = null,
        private readonly ?string $tolerance = null,
        private readonly ?array $acceptedTexts = null,
    ) {
    }

    public static function singleChoice(string $stableKey): self
    {
        return new self(QuestionType::SingleChoice, stableKey: $stableKey);
    }

    /**
     * @param list<string> $stableKeys
     */
    public static function multipleChoice(array $stableKeys): self
    {
        return new self(QuestionType::MultipleChoice, stableKeys: $stableKeys);
    }

    public static function trueFalse(bool $booleanValue): self
    {
        return new self(QuestionType::TrueFalse, booleanValue: $booleanValue);
    }

    public static function numeric(string $numericValue, ?string $tolerance): self
    {
        return new self(QuestionType::Numeric, numericValue: $numericValue, tolerance: $tolerance);
    }

    /**
     * @param list<string> $acceptedTexts
     */
    public static function shortAnswer(array $acceptedTexts): self
    {
        return new self(QuestionType::ShortAnswer, acceptedTexts: $acceptedTexts);
    }

    public function getQuestionType(): QuestionType
    {
        return $this->questionType;
    }

    public function getStableKey(): ?string
    {
        return $this->stableKey;
    }

    /**
     * @return list<string>|null
     */
    public function getStableKeys(): ?array
    {
        return $this->stableKeys;
    }

    public function getBooleanValue(): ?bool
    {
        return $this->booleanValue;
    }

    public function getNumericValue(): ?string
    {
        return $this->numericValue;
    }

    public function getTolerance(): ?string
    {
        return $this->tolerance;
    }

    /**
     * @return list<string>|null
     */
    public function getAcceptedTexts(): ?array
    {
        return $this->acceptedTexts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['questionType' => $this->questionType->value];

        return match ($this->questionType) {
            QuestionType::SingleChoice => $out + ['stableKey' => $this->stableKey],
            QuestionType::MultipleChoice => $out + ['stableKeys' => $this->stableKeys ?? []],
            QuestionType::TrueFalse => $out + ['booleanValue' => $this->booleanValue],
            QuestionType::Numeric => $out + [
                'numericValue' => $this->numericValue,
                'tolerance' => $this->tolerance,
            ],
            QuestionType::ShortAnswer => $out + ['acceptedTexts' => $this->acceptedTexts ?? []],
        };
    }
}
