<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Exception\LearningContentException;
use App\LearningContent\Content\LearningContentDocument;

/**
 * Maps admin revision-editor form fields ↔ LearningContentDocument.
 * UI allowlist is stricter than the domain validator (no media/interactive).
 */
final class LearningContentRevisionFormMapper
{
    public const UI_ALLOWED_TYPES = [
        'heading',
        'paragraph',
        'list',
        'callout',
        'quote',
        'math',
    ];

    private const TYPE_LABELS = [
        'heading' => 'Başlık',
        'paragraph' => 'Paragraf',
        'list' => 'Liste',
        'callout' => 'Uyarı kutusu',
        'quote' => 'Alıntı',
        'math' => 'Matematik',
    ];

    /**
     * @param array<mixed> $postedBlocks
     */
    public function documentFromPostedBlocks(array $postedBlocks): LearningContentDocument
    {
        ksort($postedBlocks, \SORT_NUMERIC);
        $postedBlocks = array_values($postedBlocks);

        $blocks = [];
        foreach ($postedBlocks as $raw) {
            if (!\is_array($raw)) {
                throw LearningContentException::contentInvalid('Geçersiz blok verisi.');
            }
            $blocks[] = $this->mapPostedBlock($raw);
        }

        if ([] === $blocks) {
            throw LearningContentException::contentInvalid('En az bir blok gerekli.');
        }

        return LearningContentDocument::fromArray([
            'schemaVersion' => LearningContentDocument::SCHEMA_VERSION,
            'blocks' => $blocks,
        ]);
    }

    /**
     * Rebuild a document from already-stored domain blocks after UI allowlist check.
     */
    public function documentFromDomainBlocks(mixed $blocks): LearningContentDocument
    {
        if (!\is_array($blocks) || [] === $blocks) {
            throw LearningContentException::contentInvalid('En az bir blok gerekli.');
        }

        $typed = [];
        foreach (array_values($blocks) as $block) {
            if (!\is_array($block)) {
                throw LearningContentException::contentInvalid('Geçersiz blok.');
            }
            $type = $block['type'] ?? null;
            if (!\is_string($type) || !\in_array($type, self::UI_ALLOWED_TYPES, true)) {
                throw LearningContentException::contentInvalid('Desteklenmeyen blok türü.');
            }
            $typed[] = $block;
        }

        return LearningContentDocument::fromArray([
            'schemaVersion' => LearningContentDocument::SCHEMA_VERSION,
            'blocks' => $typed,
        ]);
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>> $structuredContent
     *
     * @return list<array{
     *     type: string,
     *     type_label: string,
     *     text: string,
     *     level: int,
     *     items_text: string,
     *     latex: string,
     *     variant: string,
     *     callout_text: string
     * }>
     */
    public function editorRowsFromStructuredContent(array $structuredContent): array
    {
        $blocks = $structuredContent['blocks'] ?? $structuredContent;
        if (!\is_array($blocks)) {
            throw LearningContentException::contentInvalid('Geçersiz içerik gövdesi.');
        }

        $rows = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                throw LearningContentException::contentInvalid('Geçersiz blok.');
            }
            $type = $block['type'] ?? null;
            if (!\is_string($type) || !\in_array($type, self::UI_ALLOWED_TYPES, true)) {
                throw LearningContentException::contentInvalid(
                    'Bu sürüm medya veya etkileşimli blok içeriyor; bu editör desteklemiyor.',
                );
            }
            $rows[] = $this->blockToEditorRow($type, $block);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyBlock(string $type): array
    {
        if (!\in_array($type, self::UI_ALLOWED_TYPES, true)) {
            throw LearningContentException::contentInvalid('Desteklenmeyen blok türü.');
        }

        return match ($type) {
            'heading' => ['type' => 'heading', 'level' => 2, 'text' => 'Yeni başlık'],
            'paragraph' => ['type' => 'paragraph', 'text' => 'Yeni paragraf'],
            'list' => ['type' => 'list', 'items' => ['Yeni madde']],
            'quote' => ['type' => 'quote', 'text' => 'Yeni alıntı'],
            'math' => ['type' => 'math', 'latex' => 'x'],
            'callout' => [
                'type' => 'callout',
                'variant' => 'info',
                'blocks' => [['type' => 'paragraph', 'text' => 'Yeni bilgi']],
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public function typeChoices(): array
    {
        $choices = [];
        foreach (self::TYPE_LABELS as $value => $label) {
            $choices[$label] = $value;
        }

        return $choices;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function mapPostedBlock(array $raw): array
    {
        $type = $raw['type'] ?? null;
        if (!\is_string($type) || '' === $type) {
            throw LearningContentException::contentInvalid('Blok türü zorunludur.');
        }
        if (!\in_array($type, self::UI_ALLOWED_TYPES, true)) {
            throw LearningContentException::contentInvalid('Desteklenmeyen blok türü.');
        }

        return match ($type) {
            'paragraph' => [
                'type' => 'paragraph',
                'text' => $this->requireString($raw, 'text', 'Paragraf metni zorunludur.'),
            ],
            'heading' => [
                'type' => 'heading',
                'level' => $this->requireLevel($raw),
                'text' => $this->requireString($raw, 'text', 'Başlık metni zorunludur.'),
            ],
            'list' => [
                'type' => 'list',
                'items' => $this->parseItems($raw),
            ],
            'quote' => [
                'type' => 'quote',
                'text' => $this->requireString($raw, 'text', 'Alıntı metni zorunludur.'),
            ],
            'math' => [
                'type' => 'math',
                'latex' => $this->requireString($raw, 'latex', 'Matematik ifadesi zorunludur.'),
            ],
            'callout' => $this->mapCallout($raw),
        };
    }

    /**
     * @param array<mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function mapCallout(array $raw): array
    {
        $text = $this->requireString($raw, 'callout_text', 'Uyarı kutusu metni zorunludur.');
        $variantRaw = $raw['variant'] ?? 'info';
        if (!\is_string($variantRaw) || '' === $variantRaw) {
            $variantRaw = 'info';
        }
        $block = [
            'type' => 'callout',
            'variant' => $variantRaw,
            'blocks' => [
                ['type' => 'paragraph', 'text' => $text],
            ],
        ];

        return $block;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<string>
     */
    private function parseItems(array $raw): array
    {
        $itemsRaw = $raw['items'] ?? '';
        if (\is_array($itemsRaw)) {
            $lines = $itemsRaw;
        } elseif (\is_string($itemsRaw)) {
            $lines = preg_split("/\r\n|\n|\r/", $itemsRaw) ?: [];
        } else {
            throw LearningContentException::contentInvalid('Liste maddeleri geçersiz.');
        }

        $items = [];
        foreach ($lines as $line) {
            if (!\is_string($line) && !is_numeric($line)) {
                throw LearningContentException::contentInvalid('Liste maddeleri geçersiz.');
            }
            $trimmed = trim((string) $line);
            if ('' === $trimmed) {
                continue;
            }
            $items[] = $trimmed;
        }

        if ([] === $items) {
            throw LearningContentException::contentInvalid('Liste en az bir madde içermelidir.');
        }

        return $items;
    }

    /**
     * @param array<mixed> $raw
     */
    private function requireLevel(array $raw): int
    {
        $level = $raw['level'] ?? null;
        if (\is_string($level) && is_numeric($level)) {
            $level = (int) $level;
        }
        if (!\is_int($level)) {
            throw LearningContentException::contentInvalid('Başlık seviyesi 1–3 olmalıdır.');
        }

        return $level;
    }

    /**
     * @param array<mixed> $raw
     */
    private function requireString(array $raw, string $key, string $message): string
    {
        $value = $raw[$key] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            throw LearningContentException::contentInvalid($message);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array{
     *     type: string,
     *     type_label: string,
     *     text: string,
     *     level: int,
     *     items_text: string,
     *     latex: string,
     *     variant: string,
     *     callout_text: string
     * }
     */
    private function blockToEditorRow(string $type, array $block): array
    {
        $text = \is_string($block['text'] ?? null) ? $block['text'] : '';
        $level = \is_int($block['level'] ?? null) ? $block['level'] : 2;
        $latex = \is_string($block['latex'] ?? null) ? $block['latex'] : '';
        $variant = \is_string($block['variant'] ?? null) ? $block['variant'] : 'info';
        $itemsText = '';
        if (isset($block['items']) && \is_array($block['items'])) {
            $parts = [];
            foreach ($block['items'] as $item) {
                if (\is_string($item)) {
                    $parts[] = $item;
                }
            }
            $itemsText = implode("\n", $parts);
        }
        $calloutText = '';
        if ('callout' === $type) {
            $nested = $block['blocks'] ?? [];
            if (\is_array($nested) && isset($nested[0]) && \is_array($nested[0])) {
                $first = $nested[0];
                if (($first['type'] ?? null) === 'paragraph' && \is_string($first['text'] ?? null)) {
                    $calloutText = $first['text'];
                }
            }
        }

        return [
            'type' => $type,
            'type_label' => self::TYPE_LABELS[$type] ?? $type,
            'text' => $text,
            'level' => $level,
            'items_text' => $itemsText,
            'latex' => $latex,
            'variant' => $variant,
            'callout_text' => $calloutText,
        ];
    }
}
