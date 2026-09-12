<?php

declare(strict_types=1);

namespace App\LearningContent\Content;

/**
 * Typed structured content document for learning content revisions.
 *
 * @phpstan-type BlockArray array{
 *     type: string,
 *     text?: string,
 *     level?: int,
 *     items?: list<string>,
 *     latex?: string,
 *     mediaId?: string,
 *     variant?: string,
 *     blocks?: list<array<string, mixed>>
 * }
 */
final class LearningContentDocument
{
    public const SCHEMA_VERSION = 1;
    public const MAX_BLOCKS = 80;
    public const MAX_NESTING = 3;
    public const MAX_TOTAL_CHARS = 50000;
    public const MAX_PAYLOAD_BYTES = 262144;

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
            throw new \InvalidArgumentException('Invalid learning content document shape.');
        }

        /** @var list<array<string, mixed>> $typedBlocks */
        $typedBlocks = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                throw new \InvalidArgumentException('Invalid learning content block.');
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
