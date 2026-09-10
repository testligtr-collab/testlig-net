<?php

declare(strict_types=1);

namespace App\Assessment;

/**
 * Builds the public publication manifest (oracle-safe).
 *
 * @phpstan-type ManifestItem array{
 *     id: string,
 *     position: int,
 *     questionId: string,
 *     questionRevisionId: string,
 *     questionRevisionNumber: int,
 *     questionPublicContentHash: string,
 *     subjectId: string,
 *     points: string,
 *     penaltyPoints: string,
 *     required: bool,
 *     optionOrderMode: string|null,
 *     questionSchemaVersion: int
 * }
 * @phpstan-type ManifestSection array{
 *     id: string,
 *     position: int,
 *     title: string,
 *     instructions: string|null,
 *     durationSeconds: int|null,
 *     questionOrderMode: string,
 *     items: list<ManifestItem>
 * }
 */
final class AssessmentManifestBuilder
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param list<ManifestSection> $sections
     *
     * @return array<string, mixed>
     */
    public function build(
        string $assessmentId,
        string $assessmentRevisionId,
        int $assessmentRevisionNumber,
        string $assessmentType,
        int $gradeLevel,
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
        int $assessmentSchemaVersion,
        int $manifestSchemaVersion = self::SCHEMA_VERSION,
    ): array {
        return [
            'assessmentId' => $assessmentId,
            'assessmentRevisionId' => $assessmentRevisionId,
            'assessmentRevisionNumber' => $assessmentRevisionNumber,
            'assessmentType' => $assessmentType,
            'gradeLevel' => $gradeLevel,
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
            'assessmentSchemaVersion' => $assessmentSchemaVersion,
            'manifestSchemaVersion' => $manifestSchemaVersion,
        ];
    }
}
