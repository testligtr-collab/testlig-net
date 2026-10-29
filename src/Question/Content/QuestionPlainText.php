<?php

declare(strict_types=1);

namespace App\Question\Content;

/**
 * Plain lines from a question content document. Skips unknown blocks.
 */
final class QuestionPlainText
{
    /**
     * @param array<string, mixed>|null $document
     *
     * @return list<string>
     */
    public static function lines(?array $document): array
    {
        if (null === $document) {
            return [];
        }
        $blocks = $document['blocks'] ?? null;
        if (!\is_array($blocks)) {
            return [];
        }
        $lines = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $text = $block['text'] ?? null;
            if (\is_string($text) && '' !== trim($text)) {
                $lines[] = $text;
            }
            $inlines = $block['inlines'] ?? null;
            if (\is_array($inlines)) {
                $parts = [];
                foreach ($inlines as $inline) {
                    if (!\is_array($inline)) {
                        continue;
                    }
                    $piece = $inline['text'] ?? $inline['latex'] ?? null;
                    if (\is_string($piece) && '' !== trim($piece)) {
                        $parts[] = $piece;
                    }
                }
                if ([] !== $parts) {
                    $lines[] = implode(' ', $parts);
                }
            }
            $latex = $block['latex'] ?? null;
            if (\is_string($latex) && '' !== trim($latex)) {
                $lines[] = $latex;
            }
            $items = $block['items'] ?? null;
            if (\is_array($items)) {
                foreach ($items as $item) {
                    if (\is_string($item) && '' !== trim($item)) {
                        $lines[] = $item;
                    }
                }
            }
        }

        return $lines;
    }
}
