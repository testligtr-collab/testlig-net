<?php

declare(strict_types=1);

namespace App\Service\LearningContent;

use App\Entity\LearningContent;
use App\Entity\LearningContentRevision;

final class LearningContentPackageReport
{
    /**
     * @param list<string> $conflictReasons
     */
    public function __construct(
        public readonly int $packageFound,
        public readonly string $fixtureChecksum,
        public readonly int $subjectFound,
        public readonly int $outcomeFound,
        public readonly int $contentFound,
        public readonly int $revisionFound,
        public readonly int $placeholderRevision,
        public readonly int $blocksExpected,
        public readonly int $blocksCurrent,
        public readonly int $blocksToReplace,
        public readonly int $conflicts,
        public readonly int $applyReady,
        public readonly int $applied,
        public readonly int $noop,
        public readonly int $questionsTouched,
        public readonly int $assessmentsTouched,
        public readonly int $placementsTouched,
        public readonly int $usersTouched,
        public readonly string $planFingerprint,
        public readonly string $operation,
        public readonly array $conflictReasons = [],
        public readonly int $ownerMatch = 0,
        public readonly int $contentStatusMatch = 0,
        public readonly int $revisionStatusMatch = 0,
        public readonly int $subjectMatch = 0,
        public readonly int $gradeMatch = 0,
        public readonly int $outcomeMatch = 0,
        public readonly int $stableCodeMatch = 0,
        public readonly int $contentTypeMatch = 0,
        public readonly int $titleMatch = 0,
        public readonly int $summaryMatch = 0,
        public readonly int $placementAbsent = 0,
        public readonly ?LearningContent $content = null,
        public readonly ?LearningContentRevision $revision = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return [
            'package_found='.$this->packageFound,
            'fixture_checksum='.$this->fixtureChecksum,
            'subject_found='.$this->subjectFound,
            'outcome_found='.$this->outcomeFound,
            'content_found='.$this->contentFound,
            'revision_found='.$this->revisionFound,
            'placeholder_revision='.$this->placeholderRevision,
            'blocks_expected='.$this->blocksExpected,
            'blocks_current='.$this->blocksCurrent,
            'blocks_to_replace='.$this->blocksToReplace,
            'conflicts='.$this->conflicts,
            'apply_ready='.$this->applyReady,
            'applied='.$this->applied,
            'noop='.$this->noop,
            'questions_touched='.$this->questionsTouched,
            'assessments_touched='.$this->assessmentsTouched,
            'placements_touched='.$this->placementsTouched,
            'users_touched='.$this->usersTouched,
            'plan_fingerprint='.$this->planFingerprint,
            'operation='.$this->operation,
            'conflict_reason_count='.\count($this->conflictReasons),
            'conflict_reasons='.implode(',', $this->conflictReasons),
            'owner_match='.$this->ownerMatch,
            'content_status_match='.$this->contentStatusMatch,
            'revision_status_match='.$this->revisionStatusMatch,
            'subject_match='.$this->subjectMatch,
            'grade_match='.$this->gradeMatch,
            'outcome_match='.$this->outcomeMatch,
            'stable_code_match='.$this->stableCodeMatch,
            'content_type_match='.$this->contentTypeMatch,
            'title_match='.$this->titleMatch,
            'summary_match='.$this->summaryMatch,
            'placement_absent='.$this->placementAbsent,
        ];
    }
}
