<?php

declare(strict_types=1);

namespace App\LearningContent\Content;

use App\Exception\LearningContentException;
use Symfony\Component\Uid\Uuid;

/**
 * Allowlist validator for structured learning content (no HTML/script/external embeds).
 */
final class LearningContentDocumentValidator
{
    private const ALLOWED_TYPES = [
        'paragraph',
        'heading',
        'list',
        'quote',
        'math',
        'image_reference',
        'video_reference',
        'audio_reference',
        'document_reference',
        'callout',
        'interactive_reference',
    ];

    private const MEDIA_REFERENCE_TYPES = [
        'image_reference',
        'video_reference',
        'audio_reference',
        'document_reference',
        'interactive_reference',
    ];

    public function validate(LearningContentDocument $document): void
    {
        if (LearningContentDocument::SCHEMA_VERSION !== $document->schemaVersion) {
            throw LearningContentException::contentInvalid('Unsupported content schemaVersion.');
        }

        $blocks = $document->blocks;
        if (\count($blocks) > LearningContentDocument::MAX_BLOCKS) {
            throw LearningContentException::contentInvalid('Content exceeds max block count.');
        }

        $encoded = json_encode($document->toArray(), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        if (\strlen($encoded) > LearningContentDocument::MAX_PAYLOAD_BYTES) {
            throw LearningContentException::contentInvalid('Content exceeds max payload size.');
        }

        $totalChars = 0;
        foreach ($blocks as $block) {
            $this->validateBlock($block, 1, $totalChars);
        }

        if ($totalChars > LearningContentDocument::MAX_TOTAL_CHARS) {
            throw LearningContentException::contentInvalid('Content exceeds max character budget.');
        }
    }

    /**
     * @param array<string, mixed> $block
     */
    private function validateBlock(array $block, int $depth, int &$totalChars): void
    {
        if ($depth > LearningContentDocument::MAX_NESTING) {
            throw LearningContentException::contentInvalid('Content exceeds max nesting depth.');
        }

        $type = $block['type'] ?? null;
        if (!\is_string($type) || !\in_array($type, self::ALLOWED_TYPES, true)) {
            throw LearningContentException::contentInvalid('Unsupported content block type.');
        }

        $keys = array_keys($block);
        sort($keys);

        match ($type) {
            'paragraph' => $this->assertParagraph($block, $keys, $totalChars),
            'heading' => $this->assertHeading($block, $keys, $totalChars),
            'list' => $this->assertList($block, $keys, $totalChars),
            'quote' => $this->assertQuote($block, $keys, $totalChars),
            'math' => $this->assertMath($block, $keys, $totalChars),
            'callout' => $this->assertCallout($block, $keys, $depth, $totalChars),
            'image_reference',
            'video_reference',
            'audio_reference',
            'document_reference',
            'interactive_reference' => $this->assertMediaReference($type, $block, $keys),
        };
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string|int>     $keys
     */
    private function assertParagraph(array $block, array $keys, int &$totalChars): void
    {
        if ($keys !== ['text', 'type']) {
            throw LearningContentException::contentInvalid('Invalid paragraph block.');
        }
        $text = $block['text'] ?? null;
        if (!\is_string($text) || '' === trim($text)) {
            throw LearningContentException::contentInvalid('Paragraph text is required.');
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
            throw LearningContentException::contentInvalid('Invalid heading block.');
        }
        $level = $block['level'] ?? null;
        $text = $block['text'] ?? null;
        if (!\is_int($level) || $level < 1 || $level > 3) {
            throw LearningContentException::contentInvalid('Heading level must be 1..3.');
        }
        if (!\is_string($text) || '' === trim($text)) {
            throw LearningContentException::contentInvalid('Heading text is required.');
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
            throw LearningContentException::contentInvalid('Invalid list block.');
        }
        $items = $block['items'] ?? null;
        if (!\is_array($items) || [] === $items || \count($items) > 50) {
            throw LearningContentException::contentInvalid('List items are invalid.');
        }
        foreach ($items as $item) {
            if (!\is_string($item) || '' === trim($item)) {
                throw LearningContentException::contentInvalid('List items must be non-empty strings.');
            }
            $this->assertSafeText($item);
            $totalChars += mb_strlen($item);
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string|int>     $keys
     */
    private function assertQuote(array $block, array $keys, int &$totalChars): void
    {
        if ($keys !== ['text', 'type']) {
            throw LearningContentException::contentInvalid('Invalid quote block.');
        }
        $text = $block['text'] ?? null;
        if (!\is_string($text) || '' === trim($text)) {
            throw LearningContentException::contentInvalid('Quote text is required.');
        }
        $this->assertSafeText($text);
        $totalChars += mb_strlen($text);
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string|int>     $keys
     */
    private function assertMath(array $block, array $keys, int &$totalChars): void
    {
        if ($keys !== ['latex', 'type']) {
            throw LearningContentException::contentInvalid('Invalid math block.');
        }
        $latex = $block['latex'] ?? null;
        if (!\is_string($latex) || '' === trim($latex)) {
            throw LearningContentException::contentInvalid('Math latex is required.');
        }
        $this->assertSafeText($latex);
        $totalChars += mb_strlen($latex);
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string|int>     $keys
     */
    private function assertCallout(array $block, array $keys, int $depth, int &$totalChars): void
    {
        if ($keys !== ['blocks', 'type', 'variant'] && $keys !== ['blocks', 'type']) {
            throw LearningContentException::contentInvalid('Invalid callout block.');
        }
        if (isset($block['variant'])) {
            $variant = $block['variant'];
            if (!\is_string($variant) || !\in_array($variant, ['info', 'warning', 'tip', 'note'], true)) {
                throw LearningContentException::contentInvalid('Callout variant is invalid.');
            }
            $this->assertSafeText($variant);
        }
        $nested = $block['blocks'] ?? null;
        if (!\is_array($nested) || [] === $nested || \count($nested) > 20) {
            throw LearningContentException::contentInvalid('Callout blocks are invalid.');
        }
        foreach ($nested as $child) {
            if (!\is_array($child)) {
                throw LearningContentException::contentInvalid('Callout child must be a block.');
            }
            $childType = $child['type'] ?? null;
            if (\is_string($childType) && 'callout' === $childType) {
                throw LearningContentException::contentInvalid('Nested callouts are not allowed.');
            }
            $this->validateBlock($child, $depth + 1, $totalChars);
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param list<string|int>     $keys
     */
    private function assertMediaReference(string $type, array $block, array $keys): void
    {
        if ($keys !== ['mediaId', 'type']) {
            throw LearningContentException::contentInvalid(\sprintf('Invalid %s block.', $type));
        }
        if (!\in_array($type, self::MEDIA_REFERENCE_TYPES, true)) {
            throw LearningContentException::contentInvalid('Unsupported media reference type.');
        }
        $mediaId = $block['mediaId'] ?? null;
        if (!\is_string($mediaId) || !Uuid::isValid($mediaId)) {
            throw LearningContentException::contentInvalid(\sprintf('%s.mediaId must be a UUID string.', $type));
        }
        // External URL embeds are forbidden — only UUID mediaIds are accepted.
        if (str_contains($mediaId, '://') || str_contains($mediaId, '/')) {
            throw LearningContentException::contentInvalid('External URL embeds are forbidden.');
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
            || str_contains($lower, 'onclick=')
            || str_contains($lower, '<script')
            || str_contains($lower, '<iframe')
            || str_contains($lower, 'data:')
        ) {
            throw LearningContentException::contentInvalid('Content must not contain HTML, script, iframe, or inline handlers.');
        }
        if (1 === preg_match('#https?://#i', $text)) {
            throw LearningContentException::contentInvalid('External URL embeds are forbidden.');
        }
    }
}
