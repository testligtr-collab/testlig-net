<?php

declare(strict_types=1);

namespace App\Assessment;

/**
 * Builds the public assessment revision structure used for publicContentHash.
 *
 * Must never include answer payloads, HMAC, email, or secrets.
 *
 * @phpstan-type PublicItem array{
 *     position: int,
 *     questionId: string,
 *     questionRevisionId: string,
 *     questionRevisionNumber: int,
 *     questionPublicContentHash: string,
 *     subjectId: string,
 *     points: string,
 *     penaltyPoints: string,
 *     required: bool,
 *     optionOrderMode: string|null
 * }
 * @phpstan-type PublicSection array{
 *     position: int,
 *     title: string,
 *     instructions: string|null,
 *     durationSeconds: int|null,
 *     questionOrderMode: string,
 *     items: list<PublicItem>
 * }
 */
final class AssessmentRevisionPublicHashBuilder
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param list<PublicSection> $sections
     *
     * @return array<string, mixed>
     */
    public function build(
        string $title,
        ?string $description,
        ?string $instructions,
        ?int $durationSeconds,
        string $navigationMode,
        string $questionOrderMode,
        string $optionOrderMode,
        string $resultReleasePolicy,
        ?string $passScorePercentage,
        array $sections,
        int $schemaVersion = self::SCHEMA_VERSION,
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'instructions' => $instructions,
            'durationSeconds' => $durationSeconds,
            'navigationMode' => $navigationMode,
            'questionOrderMode' => $questionOrderMode,
            'optionOrderMode' => $optionOrderMode,
            'resultReleasePolicy' => $resultReleasePolicy,
            'passScorePercentage' => $passScorePercentage,
            'sections' => $sections,
            'schemaVersion' => $schemaVersion,
        ];
    }
}
