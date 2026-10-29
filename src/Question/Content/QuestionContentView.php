<?php

declare(strict_types=1);

namespace App\Question\Content;

/**
 * Safe view tree for question content. Media identifiers and file locations are omitted.
 */
final class QuestionContentView
{
    /**
     * @param array<string, mixed>|null $document
     *
     * @return list<array<string, mixed>>
     */
    public static function blocks(?array $document, bool $imageNote): array
    {
        $blocks = $document['blocks'] ?? null;
        if (!\is_array($blocks)) {
            return [];
        }
        $view = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $rendered = self::block($block, $imageNote);
            if (null !== $rendered) {
                $view[] = $rendered;
            }
        }

        return $view;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>|null
     */
    private static function block(array $block, bool $imageNote): ?array
    {
        $type = $block['type'] ?? null;
        if ('paragraph' === $type) {
            $inlines = self::inlines($block);
            if ([] === $inlines) {
                return null;
            }

            return ['kind' => 'paragraph', 'inlines' => $inlines];
        }
        if ('heading' === $type && \is_string($block['text'] ?? null)) {
            return ['kind' => 'paragraph', 'inlines' => [['kind' => 'text', 'text' => $block['text'], 'marks' => []]]];
        }
        if ('list' === $type && \is_array($block['items'] ?? null)) {
            $items = [];
            foreach ($block['items'] as $item) {
                if (\is_string($item) && '' !== trim($item)) {
                    $items[] = $item;
                }
            }
            if ([] === $items) {
                return null;
            }

            return [
                'kind' => 'list',
                'ordered' => 'ordered' === ($block['style'] ?? 'bullet'),
                'items' => $items,
            ];
        }
        if ('math' === $type && \is_string($block['latex'] ?? null)) {
            return ['kind' => 'math', 'node' => QuestionMathExpression::view($block['latex'])];
        }
        if ('image_reference' === $type) {
            if (!$imageNote) {
                return null;
            }

            return [
                'kind' => 'image',
                'note' => 'Görsel tarama ve onay tamamlanmadan hazır sayılmaz.',
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return list<array<string, mixed>>
     */
    private static function inlines(array $block): array
    {
        $inlines = $block['inlines'] ?? null;
        if (\is_array($inlines)) {
            $view = [];
            foreach ($inlines as $inline) {
                if (!\is_array($inline)) {
                    continue;
                }
                $rendered = self::inline($inline);
                if (null !== $rendered) {
                    $view[] = $rendered;
                }
            }

            return $view;
        }
        $text = $block['text'] ?? null;
        if (!\is_string($text) || '' === trim($text)) {
            return [];
        }

        return [['kind' => 'text', 'text' => $text, 'marks' => []]];
    }

    /**
     * @param array<string, mixed> $inline
     *
     * @return array<string, mixed>|null
     */
    private static function inline(array $inline): ?array
    {
        $type = $inline['type'] ?? null;
        if ('text' === $type && \is_string($inline['text'] ?? null) && '' !== $inline['text']) {
            $marks = [];
            foreach (\is_array($inline['marks'] ?? null) ? $inline['marks'] : [] as $mark) {
                if (\in_array($mark, ['bold', 'italic', 'underline'], true)) {
                    $marks[] = $mark;
                }
            }

            return ['kind' => 'text', 'text' => $inline['text'], 'marks' => $marks];
        }
        if ('math' === $type && \is_string($inline['latex'] ?? null) && '' !== trim($inline['latex'])) {
            return ['kind' => 'math', 'node' => QuestionMathExpression::view($inline['latex'])];
        }

        return null;
    }
}
