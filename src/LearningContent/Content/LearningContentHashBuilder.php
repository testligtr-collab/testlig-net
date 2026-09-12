<?php

declare(strict_types=1);

namespace App\LearningContent\Content;

/**
 * Builds the content-hash payload for learning content revisions.
 *
 * Must never include secrets, storage keys, or absolute paths.
 *
 * @phpstan-type AccessibilityMetadata array<string, mixed>
 */
final class LearningContentHashBuilder
{
    /**
     * @param array<string, mixed>       $structuredContent
     * @param AccessibilityMetadata|null $accessibilityMetadata
     *
     * @return array{
     *     schemaVersion: int,
     *     language: string,
     *     estimatedMinutes: int|null,
     *     sourceType: string,
     *     sourceReference: string|null,
     *     structuredContent: array<string, mixed>,
     *     accessibilityMetadata: AccessibilityMetadata|null
     * }
     */
    public function build(
        int $schemaVersion,
        string $language,
        ?int $estimatedMinutes,
        string $sourceType,
        ?string $sourceReference,
        array $structuredContent,
        ?array $accessibilityMetadata,
    ): array {
        return [
            'schemaVersion' => $schemaVersion,
            'language' => $language,
            'estimatedMinutes' => $estimatedMinutes,
            'sourceType' => $sourceType,
            'sourceReference' => $sourceReference,
            'structuredContent' => $structuredContent,
            'accessibilityMetadata' => $accessibilityMetadata,
        ];
    }
}
