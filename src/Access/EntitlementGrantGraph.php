<?php

declare(strict_types=1);

namespace App\Access;

/**
 * Fresh grant graph projection for policy hash (no managed Doctrine associations).
 *
 * @phpstan-type CatalogGrant array{kind: string, subjectId?: string, grade: int}
 */
final readonly class EntitlementGrantGraph
{
    /**
     * @param list<string>       $learningContentIds RFC4122, unsorted OK (hasher sorts)
     * @param list<string>       $assessmentIds      RFC4122
     * @param list<CatalogGrant> $catalogGrants
     */
    public function __construct(
        public array $learningContentIds,
        public array $assessmentIds,
        public array $catalogGrants,
    ) {
    }

    public function coversLearningContent(string $contentId, string $subjectId, int $gradeLevel): bool
    {
        if (\in_array($contentId, $this->learningContentIds, true)) {
            return true;
        }
        foreach ($this->catalogGrants as $grant) {
            if ('learning_content' !== $grant['kind']) {
                continue;
            }
            if ($grant['grade'] !== $gradeLevel) {
                continue;
            }
            if (($grant['subjectId'] ?? null) === $subjectId) {
                return true;
            }
        }

        return false;
    }

    public function coversAssessment(string $assessmentId, int $gradeLevel): bool
    {
        if (\in_array($assessmentId, $this->assessmentIds, true)) {
            return true;
        }
        foreach ($this->catalogGrants as $grant) {
            if ('assessment' !== $grant['kind']) {
                continue;
            }
            if ($grant['grade'] === $gradeLevel) {
                return true;
            }
        }

        return false;
    }
}
