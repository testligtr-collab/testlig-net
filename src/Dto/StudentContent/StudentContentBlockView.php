<?php

declare(strict_types=1);

namespace App\Dto\StudentContent;

/**
 * Immutable student-safe content block view (no ids, storageKey, or raw JSON).
 */
final class StudentContentBlockView
{
    public const TYPE_HEADING = 'heading';
    public const TYPE_PARAGRAPH = 'paragraph';
    public const TYPE_LIST = 'list';
    public const TYPE_QUOTE = 'quote';
    public const TYPE_MATH = 'math';
    public const TYPE_CALLOUT = 'callout';
    public const TYPE_VIDEO = 'video';
    public const TYPE_DOCUMENT = 'document';

    public const LIST_UNORDERED = 'unordered';
    public const LIST_ORDERED = 'ordered';

    /**
     * @param list<string>|null $items
     * @param list<self>|null   $children
     */
    private function __construct(
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly ?int $level = null,
        public readonly ?array $items = null,
        public readonly ?string $listStyle = null,
        public readonly ?string $latex = null,
        public readonly ?string $variant = null,
        public readonly ?array $children = null,
        public readonly ?string $description = null,
        public readonly ?string $embedSrc = null,
        public readonly ?string $fileName = null,
        public readonly ?string $fileSizeLabel = null,
        public readonly ?string $openPath = null,
    ) {
    }

    public static function heading(int $level, string $text): self
    {
        return new self(self::TYPE_HEADING, text: $text, level: $level);
    }

    public static function paragraph(string $text): self
    {
        return new self(self::TYPE_PARAGRAPH, text: $text);
    }

    /**
     * @param list<string> $items
     */
    public static function list(array $items, string $listStyle = self::LIST_UNORDERED): self
    {
        return new self(self::TYPE_LIST, items: $items, listStyle: $listStyle);
    }

    public static function quote(string $text): self
    {
        return new self(self::TYPE_QUOTE, text: $text);
    }

    public static function math(string $latex): self
    {
        return new self(self::TYPE_MATH, latex: $latex);
    }

    /**
     * @param list<self> $children
     */
    public static function callout(array $children, string $variant = 'info'): self
    {
        return new self(self::TYPE_CALLOUT, variant: $variant, children: $children);
    }

    public static function video(string $title, string $embedSrc, string $description): self
    {
        return new self(self::TYPE_VIDEO, text: $title, description: $description, embedSrc: $embedSrc);
    }

    public static function document(string $label, string $fileName, string $fileSizeLabel, string $openPath): self
    {
        return new self(self::TYPE_DOCUMENT, text: $label, fileName: $fileName, fileSizeLabel: $fileSizeLabel, openPath: $openPath);
    }
}
