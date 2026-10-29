<?php

declare(strict_types=1);

namespace App\Question\Content;

/**
 * Editor payload. Image references stay on the server and are not copied into the form.
 */
final class QuestionAuthorDocument
{
    /**
     * @param array<string, mixed>|null $document
     */
    public static function json(?array $document): string
    {
        if (null === $document || !\is_array($document['blocks'] ?? null)) {
            return '';
        }
        $blocks = [];
        foreach ($document['blocks'] as $block) {
            if (!\is_array($block) || 'image_reference' === ($block['type'] ?? null)) {
                continue;
            }
            $blocks[] = $block;
        }
        if ([] === $blocks) {
            return '';
        }
        $encoded = json_encode([
            'schemaVersion' => \is_int($document['schemaVersion'] ?? null) ? $document['schemaVersion'] : QuestionContentDocument::SCHEMA_VERSION,
            'blocks' => $blocks,
        ], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return $encoded;
    }
}
