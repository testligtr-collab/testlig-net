<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Exception\QuestionException;
use App\Question\Content\QuestionContentDocument;
use Symfony\Component\Uid\Uuid;

/**
 * Places server-owned images on the stem, an option, or the explanation.
 * Each slot is independent. The client cannot choose a media identifier.
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
            $cleanOptions[] = [
                'stableKey' => $option['stableKey'],
                'content' => $this->withoutClientImages($option['content']),
                'position' => $option['position'],
            ];
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
                'explanation' => $explanationDocument?->export(),
                'options' => $cleanOptions,
            ];
        }
        $slot = $this->slot($target, \count($cleanOptions));
        if ('explanation' === $slot) {
            $base = $explanationDocument ?? new QuestionContentDocument(QuestionContentDocument::SCHEMA_VERSION, []);
            $explanationDocument = $this->append($base, $block);
        } elseif (str_starts_with($slot, 'option:')) {
            $index = (int) substr($slot, 7) - 1;
            $current = $cleanOptions[$index];
            $cleanOptions[$index] = [
                'stableKey' => $current['stableKey'],
                'content' => $this->append($current['content'], $block),
                'position' => $current['position'],
            ];
        } else {
            $stem = $this->append($stem, $block);
        }

        return [
            'stem' => $stem,
            'explanation' => $explanationDocument?->export(),
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
     * @param array<string, mixed>|null                                                       $explanation
     * @param list<array{stableKey: string, content: QuestionContentDocument, position: int}> $options
     * @param array<string, array{type: string, mediaId: string}>                             $blocksBySlot
     *
     * @return array{stem: QuestionContentDocument, explanation: array<string, mixed>|null, options: list<array{stableKey: string, content: QuestionContentDocument, position: int}>}
     */
    public function place(
        QuestionContentDocument $stem,
        ?array $explanation,
        array $options,
        array $blocksBySlot,
    ): array {
        $stem = $this->withoutClientImages($stem);
        $explanationDocument = null === $explanation ? null : $this->withoutClientImages(QuestionContentDocument::fromArray($explanation));
        $cleanOptions = [];
        foreach ($options as $option) {
            $cleanOptions[] = [
                'stableKey' => $option['stableKey'],
                'content' => $this->withoutClientImages($option['content']),
                'position' => $option['position'],
            ];
        }
        foreach ($blocksBySlot as $slot => $block) {
            $mediaId = $block['mediaId'];
            if (!Uuid::isValid($mediaId)) {
                throw QuestionException::imageRejected('question_image_scope');
            }
            $reference = ['type' => 'image_reference', 'mediaId' => $mediaId];
            if ('stem' === $slot) {
                $stem = $this->append($stem, $reference);
                continue;
            }
            if ('explanation' === $slot) {
                $base = $explanationDocument ?? new QuestionContentDocument(QuestionContentDocument::SCHEMA_VERSION, []);
                $explanationDocument = $this->append($base, $reference);
                continue;
            }
            if (!str_starts_with($slot, 'option:')) {
                throw QuestionException::invalidInput('Image target is invalid.');
            }
            $key = substr($slot, 7);
            $found = false;
            foreach ($cleanOptions as $index => $option) {
                if ($option['stableKey'] !== $key) {
                    continue;
                }
                $cleanOptions[$index] = [
                    'stableKey' => $option['stableKey'],
                    'content' => $this->append($option['content'], $reference),
                    'position' => $option['position'],
                ];
                $found = true;
                break;
            }
            if (!$found) {
                throw QuestionException::invalidInput('Image target is invalid.');
            }
        }

        return [
            'stem' => $stem,
            'explanation' => $explanationDocument?->export(),
            'options' => $cleanOptions,
        ];
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
