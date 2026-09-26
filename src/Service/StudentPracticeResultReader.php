<?php

declare(strict_types=1);

namespace App\Service;

use App\Attempt\Answer\AttemptAnswerReader;
use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentAttemptAnswer;
use App\Entity\AssessmentAttemptItem;
use App\Entity\AssessmentItemScore;
use App\Entity\AssessmentScoringRun;
use App\Entity\QuestionAnswerKey;
use App\Entity\QuestionRevision;
use App\Entity\User;
use App\Enum\ItemScoreOutcome;
use App\Enum\QuestionType;
use App\Enum\ScoringRunStatus;
use App\Exception\QuestionException;
use App\Question\Answer\QuestionAnswerIntegrityHasher;
use App\Question\Content\QuestionPlainText;
use App\Repository\AssessmentAttemptAnswerRepository;
use App\Repository\AssessmentAttemptItemRepository;
use App\Repository\AssessmentItemScoreRepository;
use App\Repository\AssessmentScoringRunRepository;
use App\Repository\QuestionAnswerKeyRepository;
use App\Repository\QuestionRevisionOptionRepository;

/**
 * Owner-only result text for a terminal practice attempt.
 *
 * Answer keys are read on the server after submit and are not copied into the view.
 */
final class StudentPracticeResultReader
{
    public function __construct(
        private readonly AssessmentScoringRunRepository $runs,
        private readonly AssessmentItemScoreRepository $scores,
        private readonly AssessmentAttemptItemRepository $items,
        private readonly AssessmentAttemptAnswerRepository $answers,
        private readonly QuestionAnswerKeyRepository $answerKeys,
        private readonly QuestionRevisionOptionRepository $options,
        private readonly QuestionAnswerIntegrityHasher $hasher,
        private readonly AttemptAnswerReader $answerReader,
    ) {
    }

    /**
     * @return array{
     *     title: string,
     *     correct: int,
     *     incorrect: int,
     *     unanswered: int,
     *     earned: string,
     *     total: string,
     *     percentage: string,
     *     questions: list<array{
     *         position: int,
     *         stem: list<string>,
     *         options: list<string>,
     *         student_answer: string,
     *         correct_answer: string,
     *         label: string,
     *         explanation: list<string>
     *     }>
     * }|null
     */
    public function read(User $student, AssessmentAttempt $attempt): ?array
    {
        if (!$attempt->getUser()->getId()->equals($student->getId())) {
            return null;
        }
        $run = $this->runs->findLatestForAttempt($attempt->getId());
        if (!$run instanceof AssessmentScoringRun || ScoringRunStatus::Completed !== $run->getStatus()) {
            return null;
        }
        if (!$run->getAttempt()->getId()->equals($attempt->getId())) {
            return null;
        }

        $scores = [];
        foreach ($this->scores->findAllForRun($run->getId()) as $score) {
            if (!$score->getAttempt()->getId()->equals($attempt->getId())) {
                return null;
            }
            $scores[$score->getAttemptItem()->getId()->toRfc4122()] = $score;
        }

        $questions = [];
        $revision = $attempt->getAssessmentRevision();
        foreach ($this->items->findItemsForAttemptOrdered($attempt->getId()) as $item) {
            $score = $scores[$item->getId()->toRfc4122()] ?? null;
            if (!$score instanceof AssessmentItemScore) {
                return null;
            }
            $questionRevision = $item->getQuestionRevision();
            if (QuestionType::SingleChoice !== $questionRevision->getType()) {
                return null;
            }
            $options = $this->optionTexts($item, $questionRevision);
            $selected = $this->selectedText($student, $attempt, $item, $options);
            $correct = $this->correctText($questionRevision, $options);
            if (null === $correct) {
                return null;
            }
            $questions[] = [
                'position' => $item->getPresentationPosition(),
                'stem' => QuestionPlainText::lines($questionRevision->getStemContent()),
                'options' => array_values($options),
                'student_answer' => $selected ?? '',
                'correct_answer' => $correct,
                'label' => $this->label($score->getOutcome()),
                'explanation' => QuestionPlainText::lines($questionRevision->getExplanationContent()),
            ];
        }

        return [
            'title' => $revision->getTitle(),
            'correct' => $run->getCorrectCount(),
            'incorrect' => $run->getIncorrectCount(),
            'unanswered' => $run->getUnansweredCount(),
            'earned' => $run->getFinalPoints(),
            'total' => $run->getMaximumPoints(),
            'percentage' => $run->getPercentage(),
            'questions' => $questions,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function optionTexts(AssessmentAttemptItem $item, QuestionRevision $revision): array
    {
        $byKey = [];
        foreach ($this->options->findByRevision($revision) as $option) {
            $byKey[$option->getStableKey()] = implode(' ', QuestionPlainText::lines($option->getContent()));
        }
        $order = $item->getOptionOrderJson();
        $texts = [];
        if (\is_array($order) && [] !== $order) {
            foreach ($order as $key) {
                if (!isset($byKey[$key])) {
                    continue;
                }
                $texts[$key] = $byKey[$key];
            }

            return $texts;
        }

        return $byKey;
    }

    /**
     * @param array<string, string> $options
     */
    private function selectedText(User $student, AssessmentAttempt $attempt, AssessmentAttemptItem $item, array $options): ?string
    {
        $answer = $this->answers->findAnswer($attempt->getId(), $item->getId());
        if (!$answer instanceof AssessmentAttemptAnswer) {
            return null;
        }
        $payload = $this->answerReader->readForOwner($answer, $student);
        $key = $payload['selectedStableKey'] ?? null;
        if (!\is_string($key) || !isset($options[$key])) {
            return null;
        }

        return $options[$key];
    }

    /**
     * @param array<string, string> $options
     */
    private function correctText(QuestionRevision $revision, array $options): ?string
    {
        $key = $this->answerKeys->findOneByRevision($revision);
        if (!$key instanceof QuestionAnswerKey) {
            return null;
        }
        try {
            $this->hasher->verify(
                $key->getAnswerIntegrityHmac(),
                $key->getAnswerPayload(),
                $key->getAnswerType(),
                $revision->getId(),
            );
        } catch (QuestionException) {
            return null;
        }
        $stable = $key->getAnswerPayload()['correctStableKey'] ?? null;
        if (!\is_string($stable) || !isset($options[$stable])) {
            return null;
        }

        return $options[$stable];
    }

    private function label(ItemScoreOutcome $outcome): string
    {
        return match ($outcome) {
            ItemScoreOutcome::Correct => 'Doğru',
            ItemScoreOutcome::Unanswered => 'Boş',
            ItemScoreOutcome::Incorrect, ItemScoreOutcome::Invalid => 'Yanlış',
            ItemScoreOutcome::ManualPending, ItemScoreOutcome::ManuallyGraded => 'Yanlış',
        };
    }
}
