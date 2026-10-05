<?php

declare(strict_types=1);

namespace App\Question\Package;

final class QuestionPackageReport
{
    /**
     * @param list<string> $conflictReasons
     * @param list<string> $createCodes
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $packageKey,
        public readonly string $fixtureChecksum,
        public readonly int $packageFound,
        public readonly int $actorFound,
        public readonly int $actorActive,
        public readonly int $actorVerified,
        public readonly int $actorAuthorized,
        public readonly int $subjectFound,
        public readonly int $outcomeFound,
        public readonly int $rowsExpected,
        public readonly int $rowsParsed,
        public readonly int $codesExpected,
        public readonly int $codesMissing,
        public readonly int $codesMatching,
        public readonly int $codesConflicting,
        public readonly int $questionsToCreate,
        public readonly int $revisionsToCreate,
        public readonly int $answerKeysToCreate,
        public readonly int $questionsNoop,
        public readonly int $conflicts,
        public readonly int $applyReady,
        public readonly int $applied,
        public readonly int $noop,
        public readonly int $questionsCreated,
        public readonly int $revisionsCreated,
        public readonly int $answerKeysCreated,
        public readonly int $questionsSubmitted,
        public readonly int $questionsPublished,
        public readonly int $assessmentsTouched,
        public readonly int $assessmentItemsTouched,
        public readonly int $attemptsTouched,
        public readonly int $usersTouched,
        public readonly string $planFingerprint,
        public readonly string $operation,
        public readonly array $conflictReasons = [],
        public readonly array $createCodes = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return [
            'mode='.$this->mode,
            'package_key='.$this->packageKey,
            'fixture_checksum='.$this->fixtureChecksum,
            'package_found='.$this->packageFound,
            'actor_found='.$this->actorFound,
            'actor_active='.$this->actorActive,
            'actor_verified='.$this->actorVerified,
            'actor_authorized='.$this->actorAuthorized,
            'subject_found='.$this->subjectFound,
            'outcome_found='.$this->outcomeFound,
            'rows_expected='.$this->rowsExpected,
            'rows_parsed='.$this->rowsParsed,
            'codes_expected='.$this->codesExpected,
            'codes_missing='.$this->codesMissing,
            'codes_matching='.$this->codesMatching,
            'codes_conflicting='.$this->codesConflicting,
            'questions_to_create='.$this->questionsToCreate,
            'revisions_to_create='.$this->revisionsToCreate,
            'answer_keys_to_create='.$this->answerKeysToCreate,
            'questions_noop='.$this->questionsNoop,
            'conflicts='.$this->conflicts,
            'conflict_reason_count='.\count($this->conflictReasons),
            'conflict_reasons='.implode(',', $this->conflictReasons),
            'apply_ready='.$this->applyReady,
            'applied='.$this->applied,
            'noop='.$this->noop,
            'questions_created='.$this->questionsCreated,
            'revisions_created='.$this->revisionsCreated,
            'answer_keys_created='.$this->answerKeysCreated,
            'questions_submitted='.$this->questionsSubmitted,
            'questions_published='.$this->questionsPublished,
            'assessments_touched='.$this->assessmentsTouched,
            'assessment_items_touched='.$this->assessmentItemsTouched,
            'attempts_touched='.$this->attemptsTouched,
            'users_touched='.$this->usersTouched,
            'plan_fingerprint='.$this->planFingerprint,
            'operation='.$this->operation,
        ];
    }
}
