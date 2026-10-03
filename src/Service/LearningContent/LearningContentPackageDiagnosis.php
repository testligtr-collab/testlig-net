<?php

declare(strict_types=1);

namespace App\Service\LearningContent;

use App\Exception\LearningContentPackageException;

final class LearningContentPackageDiagnosis
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public readonly array $reasons,
        public readonly int $ownerMatch,
        public readonly int $contentStatusMatch,
        public readonly int $revisionStatusMatch,
        public readonly int $subjectMatch,
        public readonly int $gradeMatch,
        public readonly int $outcomeMatch,
        public readonly int $stableCodeMatch,
        public readonly int $contentTypeMatch,
        public readonly int $titleMatch,
        public readonly int $summaryMatch,
        public readonly int $placementAbsent,
    ) {
        $this->assertSafeReasons($reasons);
    }

    public static function none(): self
    {
        return new self([], 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
    }

    /**
     * @param list<string> $reasons
     */
    private function assertSafeReasons(array $reasons): void
    {
        $allow = array_flip(LearningContentPackageConflictReason::ORDER);
        foreach ($reasons as $reason) {
            if (!isset($allow[$reason])) {
                throw LearningContentPackageException::conflict();
            }
        }
    }
}
