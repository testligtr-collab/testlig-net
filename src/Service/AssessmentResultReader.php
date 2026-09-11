<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\StudentResultView;
use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentItemScore;
use App\Entity\AssessmentResultRelease;
use App\Entity\User;
use App\Enum\ResultReleaseStatus;
use App\Exception\AssessmentScoringException;
use App\Repository\AssessmentAttemptItemRepository;
use App\Repository\AssessmentItemScoreRepository;
use App\Repository\AssessmentResultReleaseRepository;
use App\Time\UtcInstant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the active released result for an attempt (never unpublished regrades).
 */
final class AssessmentResultReader
{
    public function __construct(
        private readonly AssessmentResultReleaseRepository $releases,
        private readonly AssessmentItemScoreRepository $itemScores,
        private readonly AssessmentAttemptItemRepository $attemptItems,
        private readonly AssessmentResultAccessGate $accessGate,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function readReleasedResult(User $actor, AssessmentAttempt $attempt): StudentResultView
    {
        $attemptId = $attempt->getId();

        return $this->entityManager->wrapInTransaction(function () use ($actor, $attemptId): StudentResultView {
            $freshAttempt = $this->requireFreshAttempt($attemptId);
            // AccessGate reloads actor by UUID with HINT_REFRESH; ignore identity-map User state.
            $this->accessGate->assertCanViewResult($actor, $freshAttempt);

            $release = $this->releases->findActiveReleasedForAttempt($attemptId);
            if (!$release instanceof AssessmentResultRelease
                || ResultReleaseStatus::Released !== $release->getStatus()
            ) {
                throw AssessmentScoringException::resultNotReleased();
            }
            if (!$release->getAttempt()->getId()->equals($attemptId)
                || !$release->getScoringRun()->getAttempt()->getId()->equals($attemptId)
            ) {
                throw AssessmentScoringException::scopeMismatch();
            }

            $releasedAt = $release->getReleasedAt();
            if (null === $releasedAt) {
                throw AssessmentScoringException::resultNotReleased();
            }

            $run = $release->getScoringRun();
            $scoresByItem = [];
            foreach ($this->itemScores->findAllForRun($run->getId()) as $score) {
                if (!$score->getAttempt()->getId()->equals($attemptId)) {
                    throw AssessmentScoringException::scopeMismatch();
                }
                $scoresByItem[$score->getAttemptItem()->getId()->toRfc4122()] = $score;
            }

            $items = [];
            foreach ($this->attemptItems->findItemsForAttemptOrdered($attemptId) as $attemptItem) {
                $score = $scoresByItem[$attemptItem->getId()->toRfc4122()] ?? null;
                if (!$score instanceof AssessmentItemScore) {
                    continue;
                }
                $items[] = [
                    'attemptItemId' => $attemptItem->getId()->toRfc4122(),
                    'presentationPosition' => $attemptItem->getPresentationPosition(),
                    'outcome' => $score->getOutcome()->value,
                    'awardedPoints' => $score->getAwardedPoints(),
                    'maximumPoints' => $score->getMaximumPoints(),
                    'scoringMethod' => $score->getScoringMethod()->value,
                ];
            }

            return new StudentResultView(
                $attemptId,
                $freshAttempt->getAssessment()->getId(),
                $release->getReleaseNumber(),
                UtcInstant::ensure($releasedAt),
                $run->getFinalPoints(),
                $run->getMaximumPoints(),
                $run->getPercentage(),
                $run->getCorrectCount(),
                $run->getIncorrectCount(),
                $run->getUnansweredCount(),
                $items,
            );
        });
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
            throw AssessmentScoringException::notFound();
        }

        return $attempt;
    }
}
