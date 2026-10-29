<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Enum\GradeLevel;
use App\Enum\QuestionDifficulty;
use App\Exception\QuestionException;
use App\Question\Content\QuestionContentDocument;
use App\Question\Content\QuestionContentValidator;
use App\Question\Content\QuestionPlainText;
use Symfony\Component\HttpFoundation\Request;

/**
 * Parses the single-correct choice editor. Raw HTML is rejected before persistence.
 */
final class QuestionSingleChoiceForm
{
    public function __construct(
        private readonly QuestionContentValidator $contentValidator,
    ) {
    }

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

        $difficulty = $this->difficulty($request->request->getString('difficulty'));
        $stem = $this->fieldDocument(
            $request->request->getString('stem_document'),
            $request->request->getString('stem'),
            'Stem is required.',
            'stem_markup',
            true,
        );
        if (!$stem instanceof QuestionContentDocument) {
            throw QuestionException::invalidInput('Stem is required.');
        }
        $texts = $this->optionDocuments($request);
        $correct = $request->request->getInt('correct');
        if ($correct < 1 || $correct > \count($texts)) {
            throw QuestionException::invalidInput('Exactly one correct option is required.');
        }

        /** @var list<array{stableKey: string, content: QuestionContentDocument, position: int}> $options */
        $options = [];
        foreach ($texts as $index => $document) {
            $options[] = $this->optionRow($document, $index + 1);
        }

        $explanation = $this->fieldDocument(
            $request->request->getString('explanation_document'),
            $request->request->getString('explanation'),
            'Explanation is invalid.',
            'explanation_markup',
            false,
        );

        $outcomeId = strtolower($request->request->getString('outcome_id'));
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $outcomeId)) {
            throw QuestionException::invalidInput('Outcome is required.');
        }

        return [
            'grade' => $grade,
            'difficulty' => $difficulty,
            'stem' => $stem,
            'explanation' => $explanation?->export(),
            'options' => $options,
            'answerSpec' => $this->answerSpec($correct),
            'outcomeId' => $this->plainString($outcomeId),
        ];
    }

    private function difficulty(string $value): QuestionDifficulty
    {
        $difficulty = QuestionDifficulty::tryFrom($value);
        if (!$difficulty instanceof QuestionDifficulty || !\in_array($difficulty->value, [
            QuestionDifficulty::Easy->value,
            QuestionDifficulty::Medium->value,
            QuestionDifficulty::Hard->value,
        ], true)) {
            throw QuestionException::invalidInput('Difficulty is required.');
        }

        return $difficulty;
    }

    /**
     * @return array{stableKey: string, content: QuestionContentDocument, position: int}
     */
    private function optionRow(QuestionContentDocument $content, int $position): array
    {
        return [
            'stableKey' => $this->plainString('opt_'.$position),
            'content' => $content,
            'position' => $position,
        ];
    }

    /**
     * @return array{correctStableKey: string}
     */
    private function answerSpec(int $correct): array
    {
        return ['correctStableKey' => $this->plainString('opt_'.$correct)];
    }

    private function plainString(string $value): string
    {
        return $value;
    }

    /**
     * @return list<QuestionContentDocument>
     */
    private function optionDocuments(Request $request): array
    {
        $rawOptions = $request->request->all('options');
        $rawDocuments = $request->request->all('options_document');
        $documents = [];
        $count = max(\count($rawOptions), \count($rawDocuments));
        for ($index = 0; $index < $count; ++$index) {
            $documentRaw = $rawDocuments[$index] ?? '';
            $plain = $rawOptions[$index] ?? '';
            if (!\is_string($documentRaw) || !\is_string($plain)) {
                throw QuestionException::invalidInput('Option text is required.');
            }
            if (!\array_key_exists($index, $rawOptions) && !\array_key_exists($index, $rawDocuments)) {
                continue;
            }
            $document = $this->fieldDocument($documentRaw, $plain, 'Option text is required.', 'option_markup', true);
            if (!$document instanceof QuestionContentDocument) {
                throw QuestionException::invalidInput('Option text is required.');
            }
            $documents[] = $document;
        }
        $total = \count($documents);
        if ($total < 2 || $total > 6) {
            throw QuestionException::invalidInput('Option count must be 2 to 6.');
        }
        $seen = [];
        foreach ($documents as $document) {
            $key = mb_strtolower(implode("\n", QuestionPlainText::lines($document->toArray())));
            if ('' === $key || isset($seen[$key])) {
                throw QuestionException::invalidInput('' === $key ? 'Option text is required.' : 'Duplicate option text.');
            }
            $seen[$key] = true;
        }

        return $documents;
    }

    private function fieldDocument(string $documentRaw, string $plain, string $emptyMessage, string $markupCode, bool $required): ?QuestionContentDocument
    {
        $documentRaw = trim($documentRaw);
        if ('' !== $documentRaw) {
            return $this->structured($documentRaw, $emptyMessage, $markupCode, $required);
        }
        $plain = trim($plain);
        if ('' === $plain) {
            if (!$required) {
                return null;
            }
            throw QuestionException::invalidInput($emptyMessage);
        }
        if (1 === preg_match('/[<>]/', $plain) || str_contains(strtolower($plain), 'javascript:')) {
            throw QuestionException::contentInvalid($markupCode);
        }
        $document = QuestionContentDocument::paragraph($plain);
        $this->contentValidator->validate($document);

        return $document;
    }

    private function structured(string $raw, string $emptyMessage, string $markupCode, bool $required): ?QuestionContentDocument
    {
        if (\strlen($raw) > 30000 || str_contains(strtolower($raw), '<script') || str_contains($raw, '<')) {
            throw QuestionException::contentInvalid($markupCode);
        }
        try {
            $decoded = json_decode($raw, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw QuestionException::contentInvalid($markupCode);
        }
        if (!\is_array($decoded)) {
            throw QuestionException::contentInvalid($markupCode);
        }
        try {
            $document = QuestionContentDocument::fromArray($decoded);
            $this->contentValidator->validate($document);
        } catch (\InvalidArgumentException) {
            throw QuestionException::contentInvalid($markupCode);
        } catch (QuestionException $exception) {
            if ('Invalid math expression.' === $exception->getMessage()) {
                throw QuestionException::contentInvalid(str_replace('markup', 'math', $markupCode));
            }
            throw QuestionException::contentInvalid($markupCode);
        }
        if ([] === QuestionPlainText::lines($document->toArray())) {
            if (!$required) {
                return null;
            }
            throw QuestionException::invalidInput($emptyMessage);
        }

        return $document;
    }
}
