<?php

declare(strict_types=1);

namespace App\Assessment;

/**
 * Builds SHA-256 publicContentHash for an assessment revision.
 */
final class AssessmentPublicContentHashBuilder
{
    public function __construct(
        private readonly AssessmentRevisionPublicHashBuilder $revisionPublicHashBuilder,
        private readonly AssessmentManifestHasher $hasher,
    ) {
    }

    /**
     * @param list<array{
     *     position: int,
     *     title: string,
     *     instructions: string|null,
     *     durationSeconds: int|null,
     *     questionOrderMode: string,
     *     items: list<array{
     *         position: int,
     *         questionId: string,
     *         questionRevisionId: string,
     *         questionRevisionNumber: int,
     *         questionPublicContentHash: string,
     *         subjectId: string,
     *         points: string,
     *         penaltyPoints: string,
     *         required: bool,
     *         optionOrderMode: string|null
     *     }>
     * }> $sections
     */
    public function hash(
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
        int $schemaVersion = AssessmentRevisionPublicHashBuilder::SCHEMA_VERSION,
    ): string {
        $payload = $this->revisionPublicHashBuilder->build(
            $title,
            $description,
            $instructions,
            $durationSeconds,
            $navigationMode,
            $questionOrderMode,
            $optionOrderMode,
            $resultReleasePolicy,
            $passScorePercentage,
            $sections,
            $schemaVersion,
        );

        return $this->hasher->hash($payload);
    }
}
