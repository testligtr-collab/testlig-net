<?php

declare(strict_types=1);

namespace App\Question\Content;

/**
 * Typed structured content document for question stem/explanation/options.
 *
 * @phpstan-type BlockArray array{type: string, text?: string, level?: int, items?: list<string>, latex?: string, mediaId?: string}
 */
final class QuestionContentDocument
{
    public const SCHEMA_VERSION = 1;
    public const MAX_BLOCKS = 50;
    public const MAX_NESTING = 3;
    public const MAX_TOTAL_CHARS = 20000;

    /**
     * @param list<array<string, mixed>> $blocks
     */
    public function __construct(
        public readonly int $schemaVersion,
        public readonly array $blocks,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $version = $data['schemaVersion'] ?? null;
        $blocks = $data['blocks'] ?? null;
        if (!\is_int($version) || !\is_array($blocks)) {
            throw new \InvalidArgumentException('Invalid question content document shape.');
        }

        /** @var list<array<string, mixed>> $typedBlocks */
        $typedBlocks = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                throw new \InvalidArgumentException('Invalid question content block.');
            }
            $typedBlocks[] = $block;
        }

        return new self($version, $typedBlocks);
    }

    /**
     * @return array{schemaVersion: int, blocks: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'blocks' => $this->blocks,
        ];
    }

    public static function paragraph(string $text): self
    {
        return new self(self::SCHEMA_VERSION, [
            ['type' => 'paragraph', 'text' => $text],
        ]);
    }
}
