<?php

declare(strict_types=1);

namespace App\Question\Usage;

use App\Entity\Question;
use App\Enum\AssessmentScope;
use App\Repository\AssessmentItemRepository;

/**
 * Visible platform-test usage. One row per assessment item, including older revisions.
 */
final class QuestionTestUsage
{
    public function __construct(
        private readonly AssessmentItemRepository $items,
    ) {
    }

    /**
     * @param list<Question> $questions
     *
     * @return array<string, list<array{title: string, assessment_id: string, assessment_revision: int, question_revision: int, position: int, current: bool}>>
     */
    public function forQuestions(array $questions): array
    {
        $grouped = [];
        foreach ($questions as $question) {
            $grouped[$question->getId()->toRfc4122()] = [];
        }
        if ([] === $questions) {
            return [];
        }
        foreach ($this->items->findPlatformUsages($questions) as $item) {
            $assessment = $item->getAssessmentRevision()->getAssessment();
            if (AssessmentScope::Platform !== $assessment->getScope() || null !== $assessment->getInstitution()) {
                continue;
            }
            $question = $item->getQuestion();
            $id = $question->getId()->toRfc4122();
            if (!isset($grouped[$id])) {
                continue;
            }
            $questionRevision = $item->getQuestionRevision();
            $assessmentRevision = $item->getAssessmentRevision();
            $grouped[$id][] = [
                'title' => $assessmentRevision->getTitle(),
                'assessment_id' => $assessment->getId()->toRfc4122(),
                'assessment_revision' => $assessmentRevision->getRevisionNumber(),
                'question_revision' => $questionRevision->getRevisionNumber(),
                'position' => $item->getPosition(),
                'current' => $questionRevision->getRevisionNumber() === $question->getCurrentRevisionNumber(),
            ];
        }

        return $grouped;
    }
}
