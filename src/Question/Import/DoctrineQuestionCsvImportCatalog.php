<?php

declare(strict_types=1);

namespace App\Question\Import;

use App\Entity\CurriculumLearningOutcome;
use App\Enum\CurriculumContentStatus;
use App\Enum\CurriculumStatus;
use App\Enum\SubjectStatus;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\QuestionRepository;
use App\Repository\SubjectRepository;

final class DoctrineQuestionCsvImportCatalog implements QuestionCsvImportCatalog
{
    public function __construct(
        private readonly SubjectRepository $subjects,
        private readonly CurriculumLearningOutcomeRepository $outcomes,
        private readonly QuestionRepository $questions,
    ) {
    }

    public function lookup(array $subjectCodes, array $outcomeCodes, array $questionCodes): QuestionCsvImportLookup
    {
        $subjectEntities = [];
        $subjectActive = [];
        foreach ($this->subjects->findByCodes($subjectCodes) as $subject) {
            $subjectEntities[$subject->getCode()] = $subject;
            $subjectActive[$subject->getCode()] = SubjectStatus::Active === $subject->getStatus();
        }

        $outcomeRows = [];
        $grouped = [];
        foreach ($this->outcomes->findByCodesWithPlacement($outcomeCodes) as $outcome) {
            $program = $outcome->getCurriculumProgram();
            $row = [
                'code' => $outcome->getCode(),
                'subjectCode' => $program->getSubject()->getCode(),
                'grade' => $program->getGradeLevel()->value,
                'usable' => $this->isUsable($outcome),
            ];
            $outcomeRows[] = $row;
            $grouped[self::outcomeKey($row['subjectCode'], $row['grade'], $row['code'])][] = $outcome;
        }

        $usableOutcomes = [];
        foreach ($grouped as $key => $matches) {
            $usable = array_values(array_filter(
                $matches,
                fn (CurriculumLearningOutcome $outcome): bool => $this->isUsable($outcome),
            ));
            if (1 === \count($usable)) {
                $usableOutcomes[$key] = $usable[0];
            }
        }

        $existing = [];
        foreach ($this->questions->findCodesPresent($questionCodes) as $code) {
            $existing[$code] = true;
        }

        return new QuestionCsvImportLookup($subjectActive, $outcomeRows, $existing, $subjectEntities, $usableOutcomes);
    }

    public static function outcomeKey(string $subjectCode, int $grade, string $outcomeCode): string
    {
        return $subjectCode.'|'.$grade.'|'.$outcomeCode;
    }

    private function isUsable(CurriculumLearningOutcome $outcome): bool
    {
        $program = $outcome->getCurriculumProgram();

        return CurriculumContentStatus::Active === $outcome->getStatus()
            && CurriculumContentStatus::Active === $outcome->getTopic()->getStatus()
            && CurriculumStatus::Published === $program->getStatus()
            && SubjectStatus::Active === $program->getSubject()->getStatus();
    }
}
