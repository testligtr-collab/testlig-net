<?php

declare(strict_types=1);

namespace App\Service;

use App\Attempt\Answer\AttemptAnswerEncryptor;
use App\Dto\CorrectAnswerPresentation;
use App\Dto\StudentResultReviewItemView;
use App\Dto\StudentResultReviewView;
use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentAttemptAnswer;
use App\Entity\AssessmentItemScore;
use App\Entity\AssessmentResultRelease;
use App\Entity\QuestionAnswerKey;
use App\Entity\User;
use App\Enum\AssessmentResultReviewFailureReason;
use App\Enum\QuestionType;
use App\Enum\ResultReleaseStatus;
use App\Exception\AssessmentAttemptException;
use App\Exception\AssessmentResultReviewException;
use App\Exception\QuestionException;
use App\Question\Answer\QuestionAnswerIntegrityHasher;
use App\Repository\AssessmentAttemptAnswerRepository;
use App\Repository\AssessmentAttemptItemRepository;
use App\Repository\AssessmentItemScoreRepository;
use App\Repository\AssessmentResultReleaseRepository;
use App\Repository\QuestionAnswerKeyRepository;
use App\Time\UtcInstant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Reads student result review governed by the active delivery review policy.
 *
 * Fail-closed: no active policy → review_policy_not_active (score summary remains via AssessmentResultReader).
 */
final class AssessmentResultReviewReader
{
    public function __construct(
        private readonly AssessmentResultReviewAccessGate $accessGate,
        private readonly AssessmentResultReleaseRepository $releases,
        private readonly AssessmentItemScoreRepository $itemScores,
        private readonly AssessmentAttemptItemRepository $attemptItems,
        private readonly AssessmentAttemptAnswerRepository $answers,
        private readonly QuestionAnswerKeyRepository $answerKeys,
        private readonly QuestionAnswerIntegrityHasher $answerIntegrityHasher,
        private readonly AttemptAnswerEncryptor $answerEncryptor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function readReview(User $actor, AssessmentAttempt $attempt): StudentResultReviewView
    {
        $attemptId = $attempt->getId();

        return $this->entityManager->wrapInTransaction(function () use ($actor, $attemptId): StudentResultReviewView {
            $freshAttempt = $this->requireFreshAttempt($attemptId);
            $decision = $this->accessGate->decideStudentReview($actor, $freshAttempt);
            if (!$decision->isAllowed()) {
                throw $this->mapDenyReason($decision->getDenyReason());
            }

            $policy = $decision->getPolicy();
            if (null === $policy) {
                throw AssessmentResultReviewException::reviewPolicyNotActive();
            }

            $release = $this->releases->findActiveReleasedForAttempt($attemptId);
            if (!$release instanceof AssessmentResultRelease) {
                throw AssessmentResultReviewException::resultNotReleased();
            }
            if (ResultReleaseStatus::Withdrawn === $release->getStatus()) {
                throw AssessmentResultReviewException::resultWithdrawn();
            }
            if (ResultReleaseStatus::Released !== $release->getStatus()) {
                throw AssessmentResultReviewException::resultNotReleased();
            }
            if (!$release->getAttempt()->getId()->equals($attemptId)
                || !$release->getScoringRun()->getAttempt()->getId()->equals($attemptId)
            ) {
                throw AssessmentResultReviewException::scopeMismatch();
            }

            $releasedAt = $release->getReleasedAt();
            if (null === $releasedAt) {
                throw AssessmentResultReviewException::resultNotReleased();
            }

            $run = $release->getScoringRun();
            $scoreSummaryIncluded = $decision->allowScoreSummary();
            $items = [];

            if ($decision->allowItemOutcomes()) {
                $scoresByItem = [];
                foreach ($this->itemScores->findAllForRun($run->getId()) as $score) {
                    if (!$score->getAttempt()->getId()->equals($attemptId)) {
                        throw AssessmentResultReviewException::scopeMismatch();
                    }
                    $scoresByItem[$score->getAttemptItem()->getId()->toRfc4122()] = $score;
                }

                $answersByItem = [];
                if ($decision->allowStudentAnswer()) {
                    foreach ($this->answers->findAllForAttempt($attemptId) as $answer) {
                        $answersByItem[$answer->getAttemptItem()->getId()->toRfc4122()] = $answer;
                    }
                }

                foreach ($this->attemptItems->findItemsForAttemptOrdered($attemptId) as $attemptItem) {
                    $score = $scoresByItem[$attemptItem->getId()->toRfc4122()] ?? null;
                    if (!$score instanceof AssessmentItemScore) {
                        continue;
                    }

                    $studentAnswer = null;
                    if ($decision->allowStudentAnswer()) {
                        $answer = $answersByItem[$attemptItem->getId()->toRfc4122()] ?? null;
                        if ($answer instanceof AssessmentAttemptAnswer) {
                            $studentAnswer = $this->presentStudentAnswer(
                                $freshAttempt,
                                $attemptItem->getId(),
                                $answer,
                            );
                        }
                    }

                    $correctAnswer = null;
                    $explanation = null;
                    $questionRevision = $attemptItem->getQuestionRevision();

                    if ($decision->allowCorrectAnswer() || $decision->allowExplanation()) {
                        if ($decision->isDeliveryCancelled()) {
                            throw AssessmentResultReviewException::deliveryNotSafelyClosed();
                        }
                    }

                    if ($decision->allowCorrectAnswer()) {
                        $correctAnswer = $this->presentCorrectAnswer($questionRevision->getId(), $questionRevision->getType());
                    }

                    if ($decision->allowExplanation()) {
                        $explanation = $questionRevision->getExplanationContent();
                    }

                    $items[] = new StudentResultReviewItemView(
                        $attemptItem->getId()->toRfc4122(),
                        $attemptItem->getPresentationPosition(),
                        $score->getOutcome()->value,
                        $score->getAwardedPoints(),
                        $score->getMaximumPoints(),
                        $score->getScoringMethod()->value,
                        $studentAnswer,
                        $correctAnswer,
                        $explanation,
                    );
                }
            }

            return new StudentResultReviewView(
                $attemptId,
                $freshAttempt->getAssessment()->getId(),
                $freshAttempt->getDelivery()->getId(),
                $policy->getVersion(),
                $policy->getAvailabilityMode()->value,
                $release->getReleaseNumber(),
                UtcInstant::ensure($releasedAt),
                $scoreSummaryIncluded,
                $scoreSummaryIncluded ? $run->getFinalPoints() : null,
                $scoreSummaryIncluded ? $run->getMaximumPoints() : null,
                $scoreSummaryIncluded ? $run->getPercentage() : null,
                $scoreSummaryIncluded ? $run->getCorrectCount() : null,
                $scoreSummaryIncluded ? $run->getIncorrectCount() : null,
                $scoreSummaryIncluded ? $run->getUnansweredCount() : null,
                $decision->getSensitiveRevealAt(),
                $items,
            );
        });
    }

    private function mapDenyReason(?AssessmentResultReviewFailureReason $reason): AssessmentResultReviewException
    {
        return match ($reason) {
            AssessmentResultReviewFailureReason::Unauthorized => AssessmentResultReviewException::unauthorized(),
            AssessmentResultReviewFailureReason::NotFound => AssessmentResultReviewException::notFound(),
            AssessmentResultReviewFailureReason::ReviewPolicyNotActive => AssessmentResultReviewException::reviewPolicyNotActive(),
            AssessmentResultReviewFailureReason::ReviewNotAvailable => AssessmentResultReviewException::reviewNotAvailable(),
            AssessmentResultReviewFailureReason::PolicyIntegrityFailed => AssessmentResultReviewException::policyIntegrityFailed(),
            AssessmentResultReviewFailureReason::DeliveryNotSafelyClosed => AssessmentResultReviewException::deliveryNotSafelyClosed(),
            default => AssessmentResultReviewException::reviewNotAvailable(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStudentAnswer(
        AssessmentAttempt $attempt,
        Uuid $attemptItemId,
        AssessmentAttemptAnswer $answer,
    ): array {
        try {
            $payload = $this->answerEncryptor->decrypt(
                $answer->getAnswerCiphertext(),
                $answer->getAnswerNonce(),
                $answer->getEncryptionVersion(),
                $attempt->getId()->toRfc4122(),
                $attemptItemId->toRfc4122(),
                $attempt->getUser()->getId()->toRfc4122(),
            );
        } catch (AssessmentAttemptException) {
            throw AssessmentResultReviewException::answerDecryptionFailed();
        }

        return $this->sanitizeStudentPayload($payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function sanitizeStudentPayload(array $payload): array
    {
        $out = [];
        if (isset($payload['answerType']) && \is_string($payload['answerType'])) {
            $out['answerType'] = $payload['answerType'];
        }
        if (isset($payload['selectedStableKey']) && \is_string($payload['selectedStableKey'])) {
            $out['selectedStableKey'] = $payload['selectedStableKey'];
        }
        if (isset($payload['selectedStableKeys']) && \is_array($payload['selectedStableKeys'])) {
            $keys = [];
            foreach ($payload['selectedStableKeys'] as $key) {
                if (\is_string($key)) {
                    $keys[] = $key;
                }
            }
            $out['selectedStableKeys'] = $keys;
        }
        if (\array_key_exists('selected', $payload) && \is_bool($payload['selected'])) {
            $out['selected'] = $payload['selected'];
        }
        if (isset($payload['value']) && (\is_string($payload['value']) || \is_int($payload['value']) || \is_float($payload['value']))) {
            $out['value'] = (string) $payload['value'];
        }
        if (isset($payload['text']) && \is_string($payload['text'])) {
            $out['text'] = $payload['text'];
        }

        return $out;
    }

    private function presentCorrectAnswer(Uuid $revisionId, QuestionType $type): CorrectAnswerPresentation
    {
        $revision = $this->entityManager->find(\App\Entity\QuestionRevision::class, $revisionId);
        if (null === $revision) {
            throw AssessmentResultReviewException::answerIntegrityFailed();
        }

        $answerKey = $this->answerKeys->findOneByRevision($revision);
        if (!$answerKey instanceof QuestionAnswerKey) {
            throw AssessmentResultReviewException::answerIntegrityFailed();
        }

        try {
            $this->answerIntegrityHasher->verify(
                $answerKey->getAnswerIntegrityHmac(),
                $answerKey->getAnswerPayload(),
                $answerKey->getAnswerType(),
                $revisionId,
            );
        } catch (QuestionException) {
            throw AssessmentResultReviewException::answerIntegrityFailed();
        }

        if ($answerKey->getAnswerType() !== $type) {
            throw AssessmentResultReviewException::answerIntegrityFailed();
        }

        $payload = $answerKey->getAnswerPayload();

        return match ($type) {
            QuestionType::SingleChoice => CorrectAnswerPresentation::singleChoice(
                $this->requireString($payload, 'correctStableKey'),
            ),
            QuestionType::MultipleChoice => CorrectAnswerPresentation::multipleChoice(
                $this->requireStringList($payload, 'correctStableKeys'),
            ),
            QuestionType::TrueFalse => CorrectAnswerPresentation::trueFalse(
                $this->requireBool($payload, 'correct'),
            ),
            QuestionType::Numeric => CorrectAnswerPresentation::numeric(
                $this->requireString($payload, 'value'),
                isset($payload['tolerance']) && (\is_string($payload['tolerance']) || \is_int($payload['tolerance']) || \is_float($payload['tolerance']))
                    ? (string) $payload['tolerance']
                    : null,
            ),
            QuestionType::ShortAnswer => CorrectAnswerPresentation::shortAnswer(
                $this->requireStringList($payload, 'acceptedAnswers'),
            ),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!\is_string($value)) {
            throw AssessmentResultReviewException::answerIntegrityFailed();
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function requireStringList(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!\is_array($value)) {
            throw AssessmentResultReviewException::answerIntegrityFailed();
        }
        $out = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw AssessmentResultReviewException::answerIntegrityFailed();
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireBool(array $payload, string $key): bool
    {
        $value = $payload[$key] ?? null;
        if (!\is_bool($value)) {
            throw AssessmentResultReviewException::answerIntegrityFailed();
        }

        return $value;
    }

    private function requireFreshAttempt(Uuid $attemptId): AssessmentAttempt
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(AssessmentAttempt::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $attemptId, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true);
        $attempt = $query->getOneOrNullResult();
        if (!$attempt instanceof AssessmentAttempt) {
            throw AssessmentResultReviewException::notFound();
        }

        return $attempt;
    }
}
