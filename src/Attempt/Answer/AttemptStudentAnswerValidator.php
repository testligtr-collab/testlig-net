<?php

declare(strict_types=1);

namespace App\Attempt\Answer;

use App\Enum\QuestionType;
use App\Exception\AssessmentAttemptException;
use App\Repository\QuestionRevisionOptionRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Validates student answer payloads against immutable question revisions.
 * Does not load or compare answer keys.
 *
 * @phpstan-type StudentAnswerPayload array{
 *     version: int,
 *     answerType: string,
 *     selectedStableKey?: string,
 *     selectedStableKeys?: list<string>,
 *     selected?: bool,
 *     value?: string,
 *     text?: string
 * }
 */
final class AttemptStudentAnswerValidator
{
    public const PAYLOAD_VERSION = 1;
    public const SHORT_ANSWER_MAX_LENGTH = 2000;
    /**
     * Absolute magnitude bound as canonical numeric string (bcmath; no PHP float).
     * Matches prior 1.0e12 policy without float conversion.
     */
    public const NUMERIC_ABS_MAX = '1000000000000';

    public const NUMERIC_MAX_LENGTH = 64;

    public function __construct(
        private readonly QuestionRevisionOptionRepository $options,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return StudentAnswerPayload
     */
    public function validateAndNormalize(QuestionType $type, Uuid $questionRevisionId, array $payload): array
    {
        $version = $payload['version'] ?? null;
        $answerType = $payload['answerType'] ?? null;
        if (self::PAYLOAD_VERSION !== $version) {
            throw AssessmentAttemptException::answerInvalid('Answer payload version is invalid.');
        }
        if (!\is_string($answerType) || $answerType !== $type->value) {
            throw AssessmentAttemptException::answerInvalid('Answer payload type mismatch.');
        }

        return match ($type) {
            QuestionType::SingleChoice => $this->normalizeSingleChoice($questionRevisionId, $payload),
            QuestionType::MultipleChoice => $this->normalizeMultipleChoice($questionRevisionId, $payload),
            QuestionType::TrueFalse => $this->normalizeTrueFalse($payload),
            QuestionType::Numeric => $this->normalizeNumeric($payload),
            QuestionType::ShortAnswer => $this->normalizeShortAnswer($payload),
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return StudentAnswerPayload
     */
    private function normalizeSingleChoice(Uuid $revisionId, array $payload): array
    {
        $key = $payload['selectedStableKey'] ?? null;
        if (!\is_string($key) || 1 !== preg_match('/^[a-z0-9_]{2,32}$/', $key)) {
            throw AssessmentAttemptException::answerInvalid('selectedStableKey is invalid.');
        }
        $this->assertStableKeyExists($revisionId, $key);

        return [
            'version' => self::PAYLOAD_VERSION,
            'answerType' => QuestionType::SingleChoice->value,
            'selectedStableKey' => $key,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return StudentAnswerPayload
     */
    private function normalizeMultipleChoice(Uuid $revisionId, array $payload): array
    {
        $keys = $payload['selectedStableKeys'] ?? null;
        if (!\is_array($keys) || [] === $keys) {
            throw AssessmentAttemptException::answerInvalid('selectedStableKeys must be a non-empty list.');
        }

        $normalized = [];
        $seen = [];
        foreach ($keys as $key) {
            if (!\is_string($key) || 1 !== preg_match('/^[a-z0-9_]{2,32}$/', $key)) {
                throw AssessmentAttemptException::answerInvalid('selectedStableKeys contains an invalid key.');
            }
            if (isset($seen[$key])) {
                throw AssessmentAttemptException::answerInvalid('selectedStableKeys must not contain duplicates.');
            }
            $seen[$key] = true;
            $this->assertStableKeyExists($revisionId, $key);
            $normalized[] = $key;
        }
        sort($normalized, \SORT_STRING);

        return [
            'version' => self::PAYLOAD_VERSION,
            'answerType' => QuestionType::MultipleChoice->value,
            'selectedStableKeys' => $normalized,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return StudentAnswerPayload
     */
    private function normalizeTrueFalse(array $payload): array
    {
        $selected = $payload['selected'] ?? null;
        if (!\is_bool($selected)) {
            throw AssessmentAttemptException::answerInvalid('selected must be a boolean.');
        }

        return [
            'version' => self::PAYLOAD_VERSION,
            'answerType' => QuestionType::TrueFalse->value,
            'selected' => $selected,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return StudentAnswerPayload
     */
    private function normalizeNumeric(array $payload): array
    {
        $value = $payload['value'] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            throw AssessmentAttemptException::answerInvalid('numeric value is required.');
        }
        $value = trim($value);
        if (\strlen($value) > self::NUMERIC_MAX_LENGTH) {
            throw AssessmentAttemptException::answerInvalid('numeric value exceeds maximum length.');
        }
        if (1 !== preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
            throw AssessmentAttemptException::answerInvalid('numeric value format is invalid.');
        }
        if (1 === preg_match('/[eE]/', $value)
            || 0 === strcasecmp($value, 'nan')
            || 0 === strcasecmp($value, 'inf')
            || 0 === strcasecmp($value, '+inf')
            || 0 === strcasecmp($value, '-inf')
        ) {
            throw AssessmentAttemptException::answerInvalid('numeric value format is invalid.');
        }

        $abs = str_starts_with($value, '-') ? substr($value, 1) : $value;
        /** @var numeric-string $abs */
        $abs = $abs;
        if (1 === bccomp($abs, self::NUMERIC_ABS_MAX, 0)) {
            throw AssessmentAttemptException::answerInvalid('numeric value is out of range.');
        }

        return [
            'version' => self::PAYLOAD_VERSION,
            'answerType' => QuestionType::Numeric->value,
            'value' => $value,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return StudentAnswerPayload
     */
    private function normalizeShortAnswer(array $payload): array
    {
        $text = $payload['text'] ?? null;
        if (!\is_string($text)) {
            throw AssessmentAttemptException::answerInvalid('short_answer text must be a string.');
        }
        $text = trim($text);
        if ('' === $text) {
            throw AssessmentAttemptException::answerInvalid('short_answer text must not be empty.');
        }
        if (mb_strlen($text) > self::SHORT_ANSWER_MAX_LENGTH) {
            throw AssessmentAttemptException::answerInvalid('short_answer text exceeds maximum length.');
        }

        return [
            'version' => self::PAYLOAD_VERSION,
            'answerType' => QuestionType::ShortAnswer->value,
            'text' => $text,
        ];
    }

    private function assertStableKeyExists(Uuid $revisionId, string $stableKey): void
    {
        if (!$this->options->existsForRevisionAndStableKey($revisionId, $stableKey)) {
            throw AssessmentAttemptException::answerInvalid('selectedStableKey does not belong to the question revision.');
        }
    }
}
