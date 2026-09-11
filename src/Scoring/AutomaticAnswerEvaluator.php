<?php

declare(strict_types=1);

namespace App\Scoring;

use App\Enum\ItemScoreOutcome;
use App\Enum\QuestionType;
use App\Enum\ScoringMethod;

/**
 * Evaluates a decrypted student payload against an answer-key payload (no float math).
 */
final class AutomaticAnswerEvaluator
{
    public function __construct(
        private readonly DecimalScoreCalculator $scoreCalculator,
    ) {
    }

    /**
     * @param array<string, mixed>      $answerKeyPayload
     * @param array<string, mixed>|null $studentPayload   null = unanswered
     */
    public function evaluate(
        QuestionType $type,
        array $answerKeyPayload,
        ?array $studentPayload,
        string $maximumPoints,
        string $penaltyPoints,
    ): ItemEvaluationResult {
        $maximumPoints = $this->scoreCalculator->normalizePoints($maximumPoints);
        $penaltyPoints = $this->scoreCalculator->normalizePoints($penaltyPoints);

        if (null === $studentPayload) {
            return $this->result(
                ItemScoreOutcome::Unanswered,
                ScoringMethod::Automatic,
                bcadd('0', '0', DecimalScoreCalculator::POINTS_SCALE),
                bcadd('0', '0', DecimalScoreCalculator::POINTS_SCALE),
                false,
            );
        }

        $answerType = $studentPayload['answerType'] ?? null;
        if (!\is_string($answerType) || $answerType !== $type->value) {
            return $this->incorrect($penaltyPoints);
        }

        return match ($type) {
            QuestionType::SingleChoice => $this->evaluateSingleChoice($answerKeyPayload, $studentPayload, $maximumPoints, $penaltyPoints),
            QuestionType::MultipleChoice => $this->evaluateMultipleChoice($answerKeyPayload, $studentPayload, $maximumPoints, $penaltyPoints),
            QuestionType::TrueFalse => $this->evaluateTrueFalse($answerKeyPayload, $studentPayload, $maximumPoints, $penaltyPoints),
            QuestionType::Numeric => $this->evaluateNumeric($answerKeyPayload, $studentPayload, $maximumPoints, $penaltyPoints),
            QuestionType::ShortAnswer => $this->evaluateShortAnswer($answerKeyPayload, $studentPayload, $maximumPoints, $penaltyPoints),
        };
    }

    /**
     * @param array<string, mixed> $answerKeyPayload
     * @param array<string, mixed> $studentPayload
     * @param numeric-string       $maximumPoints
     * @param numeric-string       $penaltyPoints
     */
    private function evaluateSingleChoice(
        array $answerKeyPayload,
        array $studentPayload,
        string $maximumPoints,
        string $penaltyPoints,
    ): ItemEvaluationResult {
        $selected = $studentPayload['selectedStableKey'] ?? null;
        $correct = $answerKeyPayload['correctStableKey'] ?? null;
        if (!\is_string($selected) || !\is_string($correct)) {
            return $this->incorrect($penaltyPoints);
        }

        return $selected === $correct
            ? $this->correct($maximumPoints)
            : $this->incorrect($penaltyPoints);
    }

    /**
     * @param array<string, mixed> $answerKeyPayload
     * @param array<string, mixed> $studentPayload
     * @param numeric-string       $maximumPoints
     * @param numeric-string       $penaltyPoints
     */
    private function evaluateMultipleChoice(
        array $answerKeyPayload,
        array $studentPayload,
        string $maximumPoints,
        string $penaltyPoints,
    ): ItemEvaluationResult {
        $selected = $studentPayload['selectedStableKeys'] ?? null;
        $correct = $answerKeyPayload['correctStableKeys'] ?? null;
        if (!\is_array($selected) || !\is_array($correct)) {
            return $this->incorrect($penaltyPoints);
        }

        $studentKeys = [];
        $seen = [];
        foreach ($selected as $key) {
            if (!\is_string($key)) {
                return $this->incorrect($penaltyPoints);
            }
            if (isset($seen[$key])) {
                return $this->incorrect($penaltyPoints);
            }
            $seen[$key] = true;
            $studentKeys[] = $key;
        }

        $correctKeys = [];
        foreach ($correct as $key) {
            if (!\is_string($key)) {
                return $this->incorrect($penaltyPoints);
            }
            $correctKeys[] = $key;
        }

        sort($studentKeys, \SORT_STRING);
        sort($correctKeys, \SORT_STRING);

        return $studentKeys === $correctKeys
            ? $this->correct($maximumPoints)
            : $this->incorrect($penaltyPoints);
    }

    /**
     * @param array<string, mixed> $answerKeyPayload
     * @param array<string, mixed> $studentPayload
     * @param numeric-string       $maximumPoints
     * @param numeric-string       $penaltyPoints
     */
    private function evaluateTrueFalse(
        array $answerKeyPayload,
        array $studentPayload,
        string $maximumPoints,
        string $penaltyPoints,
    ): ItemEvaluationResult {
        $selected = $studentPayload['selected'] ?? null;
        $correct = $answerKeyPayload['correct'] ?? null;
        if (!\is_bool($selected) || !\is_bool($correct)) {
            return $this->incorrect($penaltyPoints);
        }

        return $selected === $correct
            ? $this->correct($maximumPoints)
            : $this->incorrect($penaltyPoints);
    }

    /**
     * @param array<string, mixed> $answerKeyPayload
     * @param array<string, mixed> $studentPayload
     * @param numeric-string       $maximumPoints
     * @param numeric-string       $penaltyPoints
     */
    private function evaluateNumeric(
        array $answerKeyPayload,
        array $studentPayload,
        string $maximumPoints,
        string $penaltyPoints,
    ): ItemEvaluationResult {
        $studentRaw = $studentPayload['value'] ?? null;
        if (!\is_string($studentRaw)) {
            return $this->incorrect($penaltyPoints);
        }

        $studentValue = $this->tryNormalizeDecimal($studentRaw);
        $correctRaw = $answerKeyPayload['value'] ?? null;
        if (null === $studentValue || !\is_string($correctRaw)) {
            return $this->incorrect($penaltyPoints);
        }
        $correctValue = $this->tryNormalizeDecimal($correctRaw);
        if (null === $correctValue) {
            return $this->incorrect($penaltyPoints);
        }

        $toleranceRaw = $answerKeyPayload['tolerance'] ?? null;
        if (null === $toleranceRaw) {
            return 0 === bccomp($studentValue, $correctValue, 12)
                ? $this->correct($maximumPoints)
                : $this->incorrect($penaltyPoints);
        }

        if (!\is_string($toleranceRaw)) {
            return $this->incorrect($penaltyPoints);
        }
        $tolerance = $this->tryNormalizeDecimal($toleranceRaw);
        if (null === $tolerance || str_starts_with($tolerance, '-')) {
            return $this->incorrect($penaltyPoints);
        }

        $diff = bcsub($studentValue, $correctValue, 12);
        if (str_starts_with($diff, '-')) {
            $diff = bcsub('0', $diff, 12);
        }

        return 1 !== bccomp($diff, $tolerance, 12)
            ? $this->correct($maximumPoints)
            : $this->incorrect($penaltyPoints);
    }

    /**
     * @param array<string, mixed> $answerKeyPayload
     * @param array<string, mixed> $studentPayload
     * @param numeric-string       $maximumPoints
     * @param numeric-string       $penaltyPoints
     */
    private function evaluateShortAnswer(
        array $answerKeyPayload,
        array $studentPayload,
        string $maximumPoints,
        string $penaltyPoints,
    ): ItemEvaluationResult {
        $accepted = $answerKeyPayload['acceptedAnswers'] ?? null;
        $caseSensitive = $answerKeyPayload['caseSensitive'] ?? false;
        if (!\is_array($accepted) || [] === $accepted) {
            return $this->result(
                ItemScoreOutcome::ManualPending,
                ScoringMethod::Manual,
                bcadd('0', '0', DecimalScoreCalculator::POINTS_SCALE),
                bcadd('0', '0', DecimalScoreCalculator::POINTS_SCALE),
                true,
            );
        }
        if (!\is_bool($caseSensitive)) {
            $caseSensitive = false;
        }

        $text = $studentPayload['text'] ?? null;
        if (!\is_string($text)) {
            return $this->incorrect($penaltyPoints);
        }

        $studentNormalized = $this->normalizeShortAnswer($text);
        if (!$caseSensitive) {
            $studentNormalized = mb_strtolower($studentNormalized);
        }

        foreach ($accepted as $acceptedAnswer) {
            if (!\is_string($acceptedAnswer)) {
                continue;
            }
            $candidate = $this->normalizeShortAnswer($acceptedAnswer);
            if (!$caseSensitive) {
                $candidate = mb_strtolower($candidate);
            }
            if ($studentNormalized === $candidate) {
                return $this->correct($maximumPoints);
            }
        }

        return $this->incorrect($penaltyPoints);
    }

    /**
     * @param numeric-string $maximumPoints
     */
    private function correct(string $maximumPoints): ItemEvaluationResult
    {
        return $this->result(
            ItemScoreOutcome::Correct,
            ScoringMethod::Automatic,
            $this->scoreCalculator->awardedForCorrect($maximumPoints),
            bcadd('0', '0', DecimalScoreCalculator::POINTS_SCALE),
            false,
        );
    }

    /**
     * @param numeric-string $penaltyPoints
     */
    private function incorrect(string $penaltyPoints): ItemEvaluationResult
    {
        return $this->result(
            ItemScoreOutcome::Incorrect,
            ScoringMethod::Automatic,
            $this->scoreCalculator->awardedForIncorrect($penaltyPoints),
            $this->scoreCalculator->normalizePoints($penaltyPoints),
            false,
        );
    }

    /**
     * @param numeric-string $awardedPoints
     * @param numeric-string $penaltyApplied
     */
    private function result(
        ItemScoreOutcome $outcome,
        ScoringMethod $scoringMethod,
        string $awardedPoints,
        string $penaltyApplied,
        bool $manualPending,
    ): ItemEvaluationResult {
        return new ItemEvaluationResult(
            $outcome,
            $scoringMethod,
            $awardedPoints,
            $penaltyApplied,
            $manualPending,
        );
    }

    /**
     * @return numeric-string|null
     */
    private function tryNormalizeDecimal(string $raw): ?string
    {
        $raw = trim(str_replace(',', '.', $raw));
        if ('' === $raw || !is_numeric($raw) || 1 !== preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
            return null;
        }
        if (str_contains($raw, '.')) {
            $trimmed = rtrim(rtrim($raw, '0'), '.');
            $raw = '' === $trimmed || '-' === $trimmed ? '0' : $trimmed;
            if (!is_numeric($raw)) {
                return null;
            }
        }

        return $raw;
    }

    private function normalizeShortAnswer(string $value): string
    {
        $value = trim($value);
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
            if (\is_string($normalized)) {
                return trim($normalized);
            }
        }

        return $value;
    }
}
