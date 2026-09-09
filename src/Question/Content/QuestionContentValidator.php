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
        if ($keys !== ['text', 'type']) {
            throw QuestionException::contentInvalid('Invalid paragraph block.');
        }
        $text = $block['text'] ?? null;
        if (!\is_string($text) || '' === trim($text)) {
            throw QuestionException::contentInvalid('Paragraph text is required.');
        }
        $this->assertSafeText($text);
        $totalChars += mb_strlen($text);
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
        if ($keys !== ['items', 'type']) {
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
        $latex = $block['latex'] ?? null;
        if (!\is_string($latex) || '' === trim($latex)) {
            throw QuestionException::contentInvalid('Math latex is required.');
        }
        $this->assertSafeText($latex);
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
            str_contains($lower, '<')
            || str_contains($lower, '>')
            || str_contains($lower, 'javascript:')
            || str_contains($lower, 'onerror=')
            || str_contains($lower, 'onload=')
            || str_contains($lower, '<script')
            || str_contains($lower, '<iframe')
        ) {
            throw QuestionException::contentInvalid('Content must not contain HTML or executable markup.');
        }
    }
}
