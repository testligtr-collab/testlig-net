<?php

declare(strict_types=1);

namespace App\Question\Content;

use App\Exception\QuestionException;
use Symfony\Component\Uid\Uuid;

/**
 * Allowlist validator for structured question content (no HTML/script).
 */
final class QuestionContentValidator
{
    private const ALLOWED_TYPES = ['paragraph', 'heading', 'list', 'math', 'image_reference'];

    public function validate(QuestionContentDocument $document): void
    {
        if (QuestionContentDocument::SCHEMA_VERSION !== $document->schemaVersion) {
            throw QuestionException::contentInvalid('Unsupported content schemaVersion.');
        }

        $blocks = $document->blocks;
        if (\count($blocks) > QuestionContentDocument::MAX_BLOCKS) {
            throw QuestionException::contentInvalid('Content exceeds max block count.');
        }

        $totalChars = 0;
        foreach ($blocks as $block) {
            $this->validateBlock($block, 1, $totalChars);
        }

        if ($totalChars > QuestionContentDocument::MAX_TOTAL_CHARS) {
            throw QuestionException::contentInvalid('Content exceeds max character budget.');
        }
    }

    /**
     * @param array<string, mixed> $block
     */
    private function validateBlock(array $block, int $depth, int &$totalChars): void
    {
        if ($depth > QuestionContentDocument::MAX_NESTING) {
            throw QuestionException::contentInvalid('Content exceeds max nesting depth.');
        }

        $type = $block['type'] ?? null;
        if (!\is_string($type) || !\in_array($type, self::ALLOWED_TYPES, true)) {
            throw QuestionException::contentInvalid('Unsupported content block type.');
        }

        $keys = array_keys($block);
        sort($keys);

        match ($type) {
            'paragraph' => $this->assertParagraph($block, $keys, $totalChars),
            'heading' => $this->assertHeading($block, $keys, $totalChars),
            'list' => $this->assertList($block, $keys, $totalChars),
            'math' => $this->assertMath($block, $keys, $totalChars),
            'image_reference' => $this->assertImageReference($block, $keys),
        };
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string|int>     $keys
     */
    private function assertParagraph(array $block, array $keys, int &$totalChars): void
    {
        if ($keys === ['text', 'type']) {
            $text = $block['text'] ?? null;
            if (!\is_string($text) || '' === trim($text)) {
                throw QuestionException::contentInvalid('Paragraph text is required.');
            }
            $this->assertSafeText($text);
            $totalChars += mb_strlen($text);

            return;
        }
        if ($keys !== ['inlines', 'type']) {
            throw QuestionException::contentInvalid('Invalid paragraph block.');
        }
        $inlines = $block['inlines'] ?? null;
        if (!\is_array($inlines) || [] === $inlines || \count($inlines) > 40) {
            throw QuestionException::contentInvalid('Invalid paragraph block.');
        }
        foreach ($inlines as $inline) {
            if (!\is_array($inline)) {
                throw QuestionException::contentInvalid('Invalid paragraph block.');
            }
            $this->assertInline($inline, $totalChars);
        }
    }

    /**
     * @param array<string, mixed> $inline
     */
    private function assertInline(array $inline, int &$totalChars): void
    {
        $type = $inline['type'] ?? null;
        $keys = array_keys($inline);
        sort($keys);
        if ('text' === $type && ($keys === ['text', 'type'] || $keys === ['marks', 'text', 'type'])) {
            $text = $inline['text'] ?? null;
            if (!\is_string($text) || '' === $text) {
                throw QuestionException::contentInvalid('Paragraph text is required.');
            }
            $this->assertSafeText($text);
            $totalChars += mb_strlen($text);
            if ($keys === ['marks', 'text', 'type']) {
                $this->assertMarks($inline['marks'] ?? null);
            }

            return;
        }
        if ('math' === $type && $keys === ['latex', 'type']) {
            $this->assertLatex($inline['latex'] ?? null, $totalChars);

            return;
        }

        throw QuestionException::contentInvalid('Invalid paragraph block.');
    }

    private function assertMarks(mixed $marks): void
    {
        if (!\is_array($marks) || [] === $marks || \count($marks) > 3) {
            throw QuestionException::contentInvalid('Invalid paragraph block.');
        }
        $seen = [];
        foreach ($marks as $mark) {
            if (!\is_string($mark) || !\in_array($mark, ['bold', 'italic', 'underline'], true) || isset($seen[$mark])) {
                throw QuestionException::contentInvalid('Invalid paragraph block.');
            }
            $seen[$mark] = true;
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string|int>     $keys
     */
    private function assertHeading(array $block, array $keys, int &$totalChars): void
    {
        if ($keys !== ['level', 'text', 'type']) {
            throw QuestionException::contentInvalid('Invalid heading block.');
        }
        $level = $block['level'] ?? null;
        $text = $block['text'] ?? null;
        if (!\is_int($level) || $level < 1 || $level > 3) {
            throw QuestionException::contentInvalid('Heading level must be 1..3.');
        }
        if (!\is_string($text) || '' === trim($text)) {
            throw QuestionException::contentInvalid('Heading text is required.');
        }
        $this->assertSafeText($text);
        $totalChars += mb_strlen($text);
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string|int>     $keys
     */
    private function assertList(array $block, array $keys, int &$totalChars): void
    {
        if ($keys !== ['items', 'type'] && $keys !== ['items', 'style', 'type']) {
            throw QuestionException::contentInvalid('Invalid list block.');
        }
        if ($keys === ['items', 'style', 'type'] && !\in_array($block['style'] ?? null, ['bullet', 'ordered'], true)) {
            throw QuestionException::contentInvalid('Invalid list block.');
        }
        $items = $block['items'] ?? null;
        if (!\is_array($items) || [] === $items || \count($items) > 50) {
            throw QuestionException::contentInvalid('List items are invalid.');
        }
        foreach ($items as $item) {
            if (!\is_string($item) || '' === trim($item)) {
                throw QuestionException::contentInvalid('List items must be non-empty strings.');
            }
            $this->assertSafeText($item);
            $totalChars += mb_strlen($item);
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string|int>     $keys
     */
    private function assertMath(array $block, array $keys, int &$totalChars): void
    {
        if ($keys !== ['latex', 'type']) {
            throw QuestionException::contentInvalid('Invalid math block.');
        }
        $this->assertLatex($block['latex'] ?? null, $totalChars);
    }

    private function assertLatex(mixed $latex, int &$totalChars): void
    {
        if (!\is_string($latex) || '' === trim($latex)) {
            throw QuestionException::contentInvalid('Math latex is required.');
        }
        $this->assertSafeText($latex);
        try {
            QuestionMathExpression::assertSafe($latex);
        } catch (\InvalidArgumentException) {
            throw QuestionException::contentInvalid('Invalid math expression.');
        }
        $totalChars += mb_strlen($latex);
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string|int>     $keys
     */
    private function assertImageReference(array $block, array $keys): void
    {
        if ($keys !== ['mediaId', 'type']) {
            throw QuestionException::contentInvalid('Invalid image_reference block.');
        }
        $mediaId = $block['mediaId'] ?? null;
        if (!\is_string($mediaId) || !Uuid::isValid($mediaId)) {
            throw QuestionException::contentInvalid('image_reference.mediaId must be a UUID string.');
        }
    }

    private function assertSafeText(string $text): void
    {
        $lower = strtolower($text);
        if (
            str_contains($lower, 'javascript:')
            || str_contains($lower, 'onerror=')
            || str_contains($lower, 'onload=')
            || 1 === preg_match('/<\/?[a-z][^<>]*>/i', $text)
        ) {
            throw QuestionException::contentInvalid('Content must not contain HTML or executable markup.');
        }
    }
}
