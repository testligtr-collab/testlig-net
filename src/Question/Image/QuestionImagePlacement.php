<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Exception\QuestionException;
use App\Question\Content\QuestionContentDocument;
use Symfony\Component\Uid\Uuid;

/**
 * Places the one revision image on the stem, an option, or the explanation.
 * The client cannot choose a media identifier.
 */
final class QuestionImagePlacement
{
    /**
     * @param array<string, mixed>|null                                                       $explanation
     * @param list<array{stableKey: string, content: QuestionContentDocument, position: int}> $options
     * @param array{type: string, mediaId: string}|null                                       $existing
     *
     * @return array{stem: QuestionContentDocument, explanation: array<string, mixed>|null, options: list<array{stableKey: string, content: QuestionContentDocument, position: int}>}
     */
    public function apply(
        QuestionContentDocument $stem,
        ?array $explanation,
        array $options,
        ?array $existing,
        ?Uuid $stagedId,
        string $target,
    ): array {
        $stem = $this->withoutClientImages($stem);
        $explanationDocument = null === $explanation ? null : $this->withoutClientImages(QuestionContentDocument::fromArray($explanation));
        $cleanOptions = [];
        foreach ($options as $option) {
            $option['content'] = $this->withoutClientImages($option['content']);
            $cleanOptions[] = $option;
        }
        $block = null;
        if ($stagedId instanceof Uuid) {
            $block = ['type' => 'image_reference', 'mediaId' => $stagedId->toRfc4122()];
        } elseif (null !== $existing) {
            $block = $existing;
        }
        if (null === $block) {
            return [
                'stem' => $stem,
                'explanation' => $explanationDocument?->toArray(),
                'options' => $cleanOptions,
            ];
        }
        $slot = $this->slot($target, \count($cleanOptions));
        if ('explanation' === $slot) {
            $base = $explanationDocument ?? new QuestionContentDocument(QuestionContentDocument::SCHEMA_VERSION, []);
            $explanationDocument = $this->append($base, $block);
        } elseif (str_starts_with($slot, 'option:')) {
            $index = (int) substr($slot, 7) - 1;
            $cleanOptions[$index]['content'] = $this->append($cleanOptions[$index]['content'], $block);
        } else {
            $stem = $this->append($stem, $block);
        }

        return [
            'stem' => $stem,
            'explanation' => $explanationDocument?->toArray(),
            'options' => $cleanOptions,
        ];
    }

    private function slot(string $target, int $optionCount): string
    {
        if ('' === $target || 'stem' === $target) {
            return 'stem';
        }
        if ('explanation' === $target) {
            return 'explanation';
        }
        if (1 === preg_match('/^option:([1-6])$/', $target, $matches)) {
            $position = (int) $matches[1];
            if ($position >= 1 && $position <= $optionCount) {
                return 'option:'.$position;
            }
        }

        throw QuestionException::invalidInput('Image target is invalid.');
    }

    /**
     * @param array{type: string, mediaId: string} $block
     */
    private function append(QuestionContentDocument $document, array $block): QuestionContentDocument
    {
        $blocks = $document->blocks;
        $blocks[] = $block;

        return new QuestionContentDocument($document->schemaVersion, $blocks);
    }

    private function withoutClientImages(QuestionContentDocument $document): QuestionContentDocument
    {
        $blocks = [];
        foreach ($document->blocks as $block) {
            if ('image_reference' === ($block['type'] ?? null)) {
                throw QuestionException::imageRejected('question_image_scope');
            }
            $blocks[] = $block;
        }

        return new QuestionContentDocument($document->schemaVersion, $blocks);
    }
}
