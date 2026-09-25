<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Enum\GradeLevel;
use App\Enum\QuestionDifficulty;
use App\Exception\QuestionException;
use App\Question\Content\QuestionContentDocument;
use Symfony\Component\HttpFoundation\Request;

/**
 * Parses the single-correct choice editor. Raw HTML is rejected before persistence.
 */
final class QuestionSingleChoiceForm
{
    /**
     * @return array{
     *     grade: GradeLevel,
     *     difficulty: QuestionDifficulty,
     *     stem: QuestionContentDocument,
     *     explanation: array<string, mixed>|null,
     *     options: list<array{stableKey: string, content: QuestionContentDocument, position: int}>,
     *     answerSpec: array{correctStableKey: string},
     *     outcomeId: string
     * }
     */
    public function parse(Request $request): array
    {
        $grade = GradeLevel::tryFrom($request->request->getInt('grade'));
        if (!$grade instanceof GradeLevel) {
            throw QuestionException::invalidInput('Grade is required.');
        }

        $difficulty = match ($request->request->getString('difficulty')) {
            QuestionDifficulty::Easy->value => QuestionDifficulty::Easy,
            QuestionDifficulty::Medium->value => QuestionDifficulty::Medium,
            QuestionDifficulty::Hard->value => QuestionDifficulty::Hard,
            default => throw QuestionException::invalidInput('Difficulty is required.'),
        };

        $stem = $this->plainText($request->request->getString('stem'), 'Stem is required.');
        $texts = $this->optionTexts($request);
        $correct = $request->request->getInt('correct');
        if ($correct < 1 || $correct > \count($texts)) {
            throw QuestionException::invalidInput('Exactly one correct option is required.');
        }

        $options = [];
        foreach ($texts as $index => $text) {
            $position = $index + 1;
            $options[] = [
                'stableKey' => 'opt_'.$position,
                'content' => QuestionContentDocument::paragraph($text),
                'position' => $position,
            ];
        }

        $explanationRaw = trim($request->request->getString('explanation'));
        $explanation = null;
        if ('' !== $explanationRaw) {
            $explanation = QuestionContentDocument::paragraph($this->plainText($explanationRaw, 'Explanation is invalid.'))->toArray();
        }

        $outcomeId = strtolower($request->request->getString('outcome_id'));
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $outcomeId)) {
            throw QuestionException::invalidInput('Outcome is required.');
        }

        return [
            'grade' => $grade,
            'difficulty' => $difficulty,
            'stem' => QuestionContentDocument::paragraph($stem),
            'explanation' => $explanation,
            'options' => $options,
            'answerSpec' => ['correctStableKey' => 'opt_'.$correct],
            'outcomeId' => $outcomeId,
        ];
    }

    /**
     * @return list<string>
     */
    private function optionTexts(Request $request): array
    {
        $rawOptions = $request->request->all('options');
        $texts = [];
        foreach ($rawOptions as $raw) {
            if (!\is_string($raw)) {
                throw QuestionException::invalidInput('Option text is required.');
            }
            $text = trim($raw);
            if ('' === $text) {
                throw QuestionException::invalidInput('Option text is required.');
            }
            $texts[] = $this->plainText($text, 'Option text is required.');
        }
        $count = \count($texts);
        if ($count < 2 || $count > 6) {
            throw QuestionException::invalidInput('Option count must be 2 to 6.');
        }
        $seen = [];
        foreach ($texts as $text) {
            $key = mb_strtolower($text);
            if (isset($seen[$key])) {
                throw QuestionException::invalidInput('Duplicate option text.');
            }
            $seen[$key] = true;
        }

        return $texts;
    }

    private function plainText(string $value, string $emptyMessage): string
    {
        $value = trim($value);
        if ('' === $value) {
            throw QuestionException::invalidInput($emptyMessage);
        }
        if (1 === preg_match('/[<>]/', $value) || str_contains(strtolower($value), 'javascript:')) {
            throw QuestionException::contentInvalid('Markup is not allowed.');
        }

        return $value;
    }
}
