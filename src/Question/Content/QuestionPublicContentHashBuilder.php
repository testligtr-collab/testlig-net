<?php

declare(strict_types=1);

namespace App\Question\Content;

/**
 * Builds the public content-hash payload.
 *
 * Must never include answer payload / correct keys (oracle-safe).
 *
 * @phpstan-type PublicOption array{stableKey: string, content: array<string, mixed>, position: int}
 * @phpstan-type PublicAlignment array{learningOutcomeId: string, isPrimary: bool}
 */
final class QuestionPublicContentHashBuilder
{
    /**
     * @param array<string, mixed>      $stem
     * @param array<string, mixed>|null $explanation
     * @param list<PublicOption>        $options
     * @param list<PublicAlignment>     $alignments
     *
     * @return array{
     *     type: string,
     *     stem: array<string, mixed>,
     *     explanation: array<string, mixed>|null,
     *     options: list<PublicOption>,
     *     difficulty: string,
     *     estimatedSeconds: int|null,
     *     sourceType: string,
     *     sourceReference: string|null,
     *     alignments: list<PublicAlignment>,
     *     schemaVersion: int
     * }
     */
    public function build(
        string $type,
        array $stem,
        ?array $explanation,
        array $options,
        string $difficulty,
        ?int $estimatedSeconds,
        string $sourceType,
        ?string $sourceReference,
        array $alignments,
        int $schemaVersion,
    ): array {
        $publicOptions = [];
        foreach ($options as $option) {
            $publicOptions[] = [
                'stableKey' => $option['stableKey'],
                'content' => $option['content'],
                'position' => $option['position'],
            ];
        }

        $publicAlignments = [];
        foreach ($alignments as $alignment) {
            $publicAlignments[] = [
                'learningOutcomeId' => $alignment['learningOutcomeId'],
                'isPrimary' => $alignment['isPrimary'],
            ];
        }

        return [
            'type' => $type,
            'stem' => $stem,
            'explanation' => $explanation,
            'options' => $publicOptions,
            'difficulty' => $difficulty,
            'estimatedSeconds' => $estimatedSeconds,
            'sourceType' => $sourceType,
            'sourceReference' => $sourceReference,
            'alignments' => $publicAlignments,
            'schemaVersion' => $schemaVersion,
        ];
    }
}
