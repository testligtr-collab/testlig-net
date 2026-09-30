<?php

declare(strict_types=1);

namespace App\Question\Import;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\Subject;

/**
 * One snapshot of the codes needed by a file. Subject, outcome, and question
 * lookups are each a single query in the Doctrine adapter.
 */
final class QuestionCsvImportLookup
{
    /**
     * @param array<string, bool>                                                      $subjectActive  true when the subject exists and is active
     * @param list<array{code: string, subjectCode: string, grade: int, usable: bool}> $outcomes
     * @param array<string, true>                                                      $existingCodes
     * @param array<string, Subject>                                                   $subjects
     * @param array<string, CurriculumLearningOutcome>                                 $usableOutcomes keyed by subject|grade|code when exactly one usable row exists
     */
    public function __construct(
        public readonly array $subjectActive,
        public readonly array $outcomes,
        public readonly array $existingCodes,
        public readonly array $subjects = [],
        public readonly array $usableOutcomes = [],
    ) {
    }
}
