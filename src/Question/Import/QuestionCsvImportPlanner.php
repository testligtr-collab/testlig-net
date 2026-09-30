<?php

declare(strict_types=1);

namespace App\Question\Import;

use App\Enum\GradeLevel;

/**
 * Turns parsed records into a dry-run plan. It does not write domain rows.
 */
final class QuestionCsvImportPlanner
{
    public function __construct(
        private readonly QuestionCsvParser $parser,
        private readonly QuestionCsvImportCatalog $catalog,
    ) {
    }

    /**
     * @param list<array{line: int, values: array<string, string>, column_error: bool}> $records
     * @param array<string, true>                                                       $preserveCreateCodes
     */
    public function plan(array $records, array $preserveCreateCodes = []): QuestionCsvImportPlan
    {
        $prepared = [];
        $subjectCodes = [];
        $outcomeCodes = [];
        $questionCodes = [];
        foreach ($records as $record) {
            $code = $this->parser->normalizeQuestionCode($record['values']['code']);
            $subject = $this->parser->normalizeCatalogCode($record['values']['subject_code']);
            $outcome = $this->parser->normalizeCatalogCode($record['values']['learning_outcome_code']);
            if (null !== $code) {
                $questionCodes[$code] = $code;
            }
            if (null !== $subject) {
                $subjectCodes[$subject] = $subject;
            }
            if (null !== $outcome) {
                $outcomeCodes[$outcome] = $outcome;
            }
            $prepared[] = ['record' => $record, 'code' => $code, 'subject' => $subject, 'outcome' => $outcome];
        }

        $lookup = $this->catalog->lookup(
            array_values($subjectCodes),
            array_values($outcomeCodes),
            array_values($questionCodes),
        );

        $counts = [];
        foreach ($prepared as $row) {
            if (null !== $row['code']) {
                $counts[$row['code']] = ($counts[$row['code']] ?? 0) + 1;
            }
        }

        $views = [];
        $creates = [];
        $created = 0;
        $skipped = 0;
        $errors = 0;
        $conflicts = 0;
        foreach ($prepared as $row) {
            $view = $this->classify($row['record'], $row['code'], $row['subject'], $row['outcome'], $counts, $lookup, $preserveCreateCodes);
            $views[] = [
                'line' => $view['line'],
                'code' => $view['code'],
                'status' => $view['status'],
                'message' => $view['message'],
                'correct' => $view['correct'],
                'stem' => $view['stem'],
            ];
            if ('create' === $view['status']) {
                $creates[$view['line']] = $view['payload'];
                ++$created;
            } elseif ('skip' === $view['status']) {
                ++$skipped;
            } elseif ('conflict' === $view['status']) {
                ++$conflicts;
            } else {
                ++$errors;
            }
        }

        return new QuestionCsvImportPlan($views, $creates, $created, $skipped, $errors, $conflicts);
    }

    /**
     * @param array{line: int, values: array<string, string>, column_error: bool} $record
     * @param array<string, int>                                                  $counts
     * @param array<string, true>                                                 $preserveCreateCodes
     *
     * @return array{
     *     line: int,
     *     code: string,
     *     status: string,
     *     message: string,
     *     correct: string,
     *     stem: string,
     *     payload: array{
     *         code: string,
     *         grade: int,
     *         subjectCode: string,
     *         outcomeCode: string,
     *         stem: string,
     *         options: list<string>,
     *         correctIndex: int,
     *         explanation: string
     *     }
     * }
     */
    private function classify(array $record, ?string $code, ?string $subject, ?string $outcome, array $counts, QuestionCsvImportLookup $lookup, array $preserveCreateCodes): array
    {
        $values = $record['values'];
        $blankPayload = [
            'code' => '',
            'grade' => 0,
            'subjectCode' => '',
            'outcomeCode' => '',
            'stem' => '',
            'options' => [],
            'correctIndex' => 0,
            'explanation' => '',
        ];
        $base = [
            'line' => $record['line'],
            'code' => $code ?? $values['code'],
            'correct' => '',
            'stem' => '',
            'payload' => $blankPayload,
        ];
        $fail = static function (string $message) use ($base): array {
            return $base + ['status' => 'error', 'message' => $message];
        };

        if ($record['column_error']) {
            return $fail(QuestionCsvMessages::COLUMN_COUNT);
        }
        if ('' === $values['code']) {
            return $fail(QuestionCsvMessages::CODE_REQUIRED);
        }
        if (QuestionCsvTemplate::EXAMPLE_CODE === strtolower($values['code'])) {
            return $fail(QuestionCsvMessages::EXAMPLE);
        }
        if (null === $code) {
            return $fail(QuestionCsvMessages::CODE_INVALID);
        }
        if (($counts[$code] ?? 0) > 1) {
            return $base + ['status' => 'conflict', 'message' => QuestionCsvMessages::CONFLICT];
        }
        if (isset($lookup->existingCodes[$code]) && !isset($preserveCreateCodes[$code])) {
            return $base + ['status' => 'skip', 'message' => QuestionCsvMessages::SKIP];
        }

        $grade = GradeLevel::tryFrom($this->grade($values['grade_level']));
        if (!$grade instanceof GradeLevel || (string) $grade->value !== $values['grade_level']) {
            return $fail(QuestionCsvMessages::GRADE_INVALID);
        }
        if ('' === $values['subject_code']) {
            return $fail(QuestionCsvMessages::SUBJECT_REQUIRED);
        }
        if (null === $subject) {
            return $fail(QuestionCsvMessages::SUBJECT_INVALID);
        }
        if (!\array_key_exists($subject, $lookup->subjectActive)) {
            return $fail(QuestionCsvMessages::SUBJECT_MISSING);
        }
        if (false === $lookup->subjectActive[$subject]) {
            return $fail(QuestionCsvMessages::SUBJECT_INACTIVE);
        }
        if ('' === $values['learning_outcome_code']) {
            return $fail(QuestionCsvMessages::OUTCOME_REQUIRED);
        }
        if (null === $outcome) {
            return $fail(QuestionCsvMessages::OUTCOME_INVALID);
        }

        $message = $this->outcomeMessage($lookup, $subject, $grade->value, $outcome);
        if (null !== $message) {
            return $fail($message);
        }

        $length = $this->lengthMessage($values);
        if (null !== $length) {
            return $fail($length);
        }
        $markup = $this->markupMessage($values);
        if (null !== $markup) {
            return $fail($markup);
        }
        if ('' === $values['stem']) {
            return $fail(QuestionCsvMessages::STEM_REQUIRED);
        }

        $slots = [$values['option_a'], $values['option_b'], $values['option_c'], $values['option_d']];
        $last = -1;
        foreach ($slots as $index => $text) {
            if ('' !== $text) {
                $last = $index;
            }
        }
        if ($last < 1) {
            return $fail(QuestionCsvMessages::OPTION_REQUIRED);
        }
        for ($index = 0; $index <= $last; ++$index) {
            if ('' === $slots[$index]) {
                return $fail(QuestionCsvMessages::OPTION_GAP);
            }
        }
        $options = \array_slice($slots, 0, $last + 1);
        $seen = [];
        foreach ($options as $option) {
            $key = mb_strtolower($option);
            if (isset($seen[$key])) {
                return $fail(QuestionCsvMessages::OPTION_DUPLICATE);
            }
            $seen[$key] = true;
        }

        $correct = strtoupper($values['correct_option']);
        if (!\in_array($correct, ['A', 'B', 'C', 'D'], true)) {
            return $fail(QuestionCsvMessages::CORRECT_INVALID);
        }
        $correctIndex = \ord($correct) - \ord('A');
        if (!isset($options[$correctIndex])) {
            return $fail(QuestionCsvMessages::CORRECT_MISSING);
        }

        return [
            'line' => $record['line'],
            'code' => $code,
            'status' => 'create',
            'message' => QuestionCsvMessages::CREATE,
            'correct' => $correct,
            'stem' => $values['stem'],
            'payload' => [
                'code' => $code,
                'grade' => $grade->value,
                'subjectCode' => $subject,
                'outcomeCode' => $outcome,
                'stem' => $values['stem'],
                'options' => $options,
                'correctIndex' => $correctIndex,
                'explanation' => $values['explanation'],
            ],
        ];
    }

    /**
     * @param array<string, string> $values
     */
    private function lengthMessage(array $values): ?string
    {
        foreach (['stem', 'option_a', 'option_b', 'option_c', 'option_d', 'explanation'] as $name) {
            if (mb_strlen($values[$name]) > QuestionCsvParser::MAX_FIELD_CHARS) {
                return QuestionCsvMessages::FIELD_TOO_LONG;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $values
     */
    private function markupMessage(array $values): ?string
    {
        foreach (['stem', 'option_a', 'option_b', 'option_c', 'option_d', 'explanation'] as $name) {
            $value = strtolower($values[$name]);
            if (preg_match('/[<>]/', $values[$name]) || str_contains($value, 'javascript:')) {
                return QuestionCsvMessages::MARKUP;
            }
        }

        return null;
    }

    private function outcomeMessage(QuestionCsvImportLookup $lookup, string $subject, int $grade, string $outcome): ?string
    {
        $matched = [];
        $usable = 0;
        foreach ($lookup->outcomes as $row) {
            if ($row['code'] !== $outcome) {
                continue;
            }
            if ($row['subjectCode'] !== $subject || $row['grade'] !== $grade) {
                continue;
            }
            $matched[] = $row;
            if ($row['usable']) {
                ++$usable;
            }
        }
        $anyCode = false;
        foreach ($lookup->outcomes as $row) {
            if ($row['code'] === $outcome) {
                $anyCode = true;
                break;
            }
        }
        if (!$anyCode) {
            return QuestionCsvMessages::OUTCOME_MISSING;
        }
        if ([] === $matched) {
            return QuestionCsvMessages::OUTCOME_MISMATCH;
        }
        if (0 === $usable) {
            return QuestionCsvMessages::OUTCOME_INACTIVE;
        }
        if ($usable > 1) {
            return QuestionCsvMessages::OUTCOME_AMBIGUOUS;
        }

        return null;
    }

    private function grade(string $raw): int
    {
        if (1 !== preg_match('/^[1-9][0-9]?$/', $raw)) {
            return 0;
        }

        return (int) $raw;
    }
}
