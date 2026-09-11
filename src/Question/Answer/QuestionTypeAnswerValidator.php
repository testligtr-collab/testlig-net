<?php

declare(strict_types=1);

namespace App\Question\Answer;

use App\Enum\QuestionType;
use App\Exception\QuestionException;
use App\Question\Content\QuestionContentDocument;
use App\Question\Content\QuestionContentValidator;

/**
 * Central answer-key policy for supported question types.
 *
 * @phpstan-type OptionSpec array{stableKey: string, content: QuestionContentDocument|array<string, mixed>, position: int}
 * @phpstan-type AnswerSpec array<string, mixed>
 */
final class QuestionTypeAnswerValidator
{
    public const ANSWER_PAYLOAD_VERSION = 1;

    public function __construct(
        private readonly QuestionContentValidator $contentValidator,
    ) {
    }

    /**
     * @param list<OptionSpec> $options
     * @param AnswerSpec       $answerSpec
     *
     * @return array{options: list<array{stableKey: string, content: array<string, mixed>, position: int}>, answerPayload: array<string, mixed>}
     */
    public function validateAndNormalize(QuestionType $type, array $options, array $answerSpec): array
    {
        return match ($type) {
            QuestionType::SingleChoice => $this->singleChoice($options, $answerSpec),
            QuestionType::MultipleChoice => $this->multipleChoice($options, $answerSpec),
            QuestionType::TrueFalse => $this->trueFalse($options, $answerSpec),
            QuestionType::Numeric => $this->numeric($options, $answerSpec),
            QuestionType::ShortAnswer => $this->shortAnswer($options, $answerSpec),
        };
    }

    /**
     * @param list<OptionSpec> $options
     * @param AnswerSpec       $answerSpec
     *
     * @return array{options: list<array{stableKey: string, content: array<string, mixed>, position: int}>, answerPayload: array<string, mixed>}
     */
    private function singleChoice(array $options, array $answerSpec): array
    {
        $normalizedOptions = $this->normalizeOptions($options, minCount: 2);
        $correct = $answerSpec['correctStableKey'] ?? null;
        if (!\is_string($correct) || '' === $correct) {
            throw QuestionException::answerInvalid('single_choice requires exactly one correctStableKey.');
        }
        $keys = array_column($normalizedOptions, 'stableKey');
        if (!\in_array($correct, $keys, true)) {
            throw QuestionException::answerInvalid('correctStableKey must reference an option.');
        }

        return [
            'options' => $normalizedOptions,
            'answerPayload' => [
                'version' => self::ANSWER_PAYLOAD_VERSION,
                'answerType' => QuestionType::SingleChoice->value,
                'correctStableKey' => $correct,
            ],
        ];
    }

    /**
     * @param list<OptionSpec> $options
     * @param AnswerSpec       $answerSpec
     *
     * @return array{options: list<array{stableKey: string, content: array<string, mixed>, position: int}>, answerPayload: array<string, mixed>}
     */
    private function multipleChoice(array $options, array $answerSpec): array
    {
        $normalizedOptions = $this->normalizeOptions($options, minCount: 2);
        $correctKeys = $answerSpec['correctStableKeys'] ?? null;
        if (!\is_array($correctKeys) || [] === $correctKeys) {
            throw QuestionException::answerInvalid('multiple_choice requires at least one correctStableKey.');
        }
        $keys = array_column($normalizedOptions, 'stableKey');
        $unique = [];
        foreach ($correctKeys as $key) {
            if (!\is_string($key) || !\in_array($key, $keys, true)) {
                throw QuestionException::answerInvalid('correctStableKeys must reference options.');
            }
            $unique[$key] = true;
        }
        if (\count($unique) === \count($keys)) {
            throw QuestionException::answerInvalid('multiple_choice cannot mark all options correct.');
        }
        $sorted = array_keys($unique);
        sort($sorted);

        return [
            'options' => $normalizedOptions,
            'answerPayload' => [
                'version' => self::ANSWER_PAYLOAD_VERSION,
                'answerType' => QuestionType::MultipleChoice->value,
                'correctStableKeys' => $sorted,
            ],
        ];
    }

    /**
     * @param list<OptionSpec> $options
     * @param AnswerSpec       $answerSpec
     *
     * @return array{options: list<array{stableKey: string, content: array<string, mixed>, position: int}>, answerPayload: array<string, mixed>}
     */
    private function trueFalse(array $options, array $answerSpec): array
    {
        if ([] !== $options) {
            throw QuestionException::answerInvalid('true_false must not include option rows.');
        }
        $correct = $answerSpec['correct'] ?? null;
        if (!\is_bool($correct)) {
            throw QuestionException::answerInvalid('true_false requires boolean correct.');
        }

        return [
            'options' => [],
            'answerPayload' => [
                'version' => self::ANSWER_PAYLOAD_VERSION,
                'answerType' => QuestionType::TrueFalse->value,
                'correct' => $correct,
            ],
        ];
    }

    /**
     * @param list<OptionSpec> $options
     * @param AnswerSpec       $answerSpec
     *
     * @return array{options: list<array{stableKey: string, content: array<string, mixed>, position: int}>, answerPayload: array<string, mixed>}
     */
    private function numeric(array $options, array $answerSpec): array
    {
        if ([] !== $options) {
            throw QuestionException::answerInvalid('numeric must not include option rows.');
        }
        $value = $answerSpec['value'] ?? null;
        if (!\is_string($value)) {
            throw QuestionException::answerInvalid('numeric requires a canonical numeric string value.');
        }
        $normalized = $this->normalizeDecimal($value);
        $tolerance = $answerSpec['tolerance'] ?? null;
        if (null !== $tolerance) {
            if (!\is_string($tolerance)) {
                throw QuestionException::answerInvalid('tolerance must be a canonical numeric string.');
            }
            $toleranceNormalized = $this->normalizeDecimal($tolerance);
            if (str_starts_with($toleranceNormalized, '-')) {
                throw QuestionException::answerInvalid('tolerance must be >= 0.');
            }
        } else {
            $toleranceNormalized = null;
        }

        $payload = [
            'version' => self::ANSWER_PAYLOAD_VERSION,
            'answerType' => QuestionType::Numeric->value,
            'value' => $normalized,
            'tolerance' => $toleranceNormalized,
        ];

        return ['options' => [], 'answerPayload' => $payload];
    }

    /**
     * @param list<OptionSpec> $options
     * @param AnswerSpec       $answerSpec
     *
     * @return array{options: list<array{stableKey: string, content: array<string, mixed>, position: int}>, answerPayload: array<string, mixed>}
     */
    private function shortAnswer(array $options, array $answerSpec): array
    {
        if ([] !== $options) {
            throw QuestionException::answerInvalid('short_answer must not include option rows.');
        }
        $accepted = $answerSpec['acceptedAnswers'] ?? null;
        $caseSensitive = $answerSpec['caseSensitive'] ?? false;
        if (!\is_array($accepted) || [] === $accepted) {
            throw QuestionException::answerInvalid('short_answer requires non-empty acceptedAnswers.');
        }
        if (!\is_bool($caseSensitive)) {
            throw QuestionException::answerInvalid('caseSensitive must be boolean.');
        }
        $normalizedAccepted = [];
        foreach ($accepted as $answer) {
            if (!\is_string($answer)) {
                throw QuestionException::answerInvalid('acceptedAnswers must be strings.');
            }
            $normalized = $this->normalizeShortAnswer($answer);
            if ('' === $normalized) {
                throw QuestionException::answerInvalid('acceptedAnswers cannot be empty after normalization.');
            }
            $normalizedAccepted[$caseSensitive ? $normalized : mb_strtolower($normalized)] = $normalized;
        }
        $list = array_values($normalizedAccepted);
        sort($list);

        return [
            'options' => [],
            'answerPayload' => [
                'version' => self::ANSWER_PAYLOAD_VERSION,
                'answerType' => QuestionType::ShortAnswer->value,
                'acceptedAnswers' => $list,
                'caseSensitive' => $caseSensitive,
            ],
        ];
    }

    /**
     * @param list<array{stableKey?: mixed, content?: mixed, position?: mixed}> $options
     *
     * @return list<array{stableKey: string, content: array<string, mixed>, position: int}>
     */
    private function normalizeOptions(array $options, int $minCount): array
    {
        if (\count($options) < $minCount) {
            throw QuestionException::answerInvalid(\sprintf('At least %d options are required.', $minCount));
        }

        $normalized = [];
        $seenKeys = [];
        $seenPositions = [];
        foreach ($options as $option) {
            $stableKey = $option['stableKey'] ?? null;
            $position = $option['position'] ?? null;
            $content = $option['content'] ?? null;
            if (!\is_string($stableKey) || 1 !== preg_match('/^[a-z0-9_]{2,32}$/', $stableKey)) {
                throw QuestionException::answerInvalid('Option stableKey must match [a-z0-9_]{2,32}.');
            }
            if (!\is_int($position) || $position < 1) {
                throw QuestionException::answerInvalid('Option position must be >= 1.');
            }
            if (isset($seenKeys[$stableKey]) || isset($seenPositions[$position])) {
                throw QuestionException::answerInvalid('Duplicate option stableKey or position.');
            }
            $document = $content instanceof QuestionContentDocument
                ? $content
                : QuestionContentDocument::fromArray(\is_array($content) ? $content : []);
            $this->contentValidator->validate($document);
            $seenKeys[$stableKey] = true;
            $seenPositions[$position] = true;
            $normalized[] = [
                'stableKey' => $stableKey,
                'content' => $document->toArray(),
                'position' => $position,
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return $normalized;
    }

    private function normalizeDecimal(string $raw): string
    {
        $raw = trim($raw);
        if (str_contains($raw, ',') || 1 === preg_match('/[eE]/', $raw)) {
            throw QuestionException::answerInvalid('Invalid decimal value.');
        }
        if (1 !== preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $raw)) {
            throw QuestionException::answerInvalid('Invalid decimal value.');
        }
        if (\strlen($raw) > 64) {
            throw QuestionException::answerInvalid('Invalid decimal value.');
        }
        if (!str_contains($raw, '.')) {
            return '-0' === $raw ? '0' : $raw;
        }
        $raw = rtrim(rtrim($raw, '0'), '.');

        return '' === $raw || '-' === $raw || '-0' === $raw ? '0' : $raw;
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
