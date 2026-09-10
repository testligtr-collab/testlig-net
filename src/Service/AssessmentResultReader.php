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
        return $this->entityManager->wrapInTransaction(function () use ($actor, $attempt): StudentResultView {
            $this->accessGate->assertCanViewResult($actor, $attempt);

            $release = $this->releases->findActiveReleasedForAttempt($attempt->getId());
            if (!$release instanceof AssessmentResultRelease
                || ResultReleaseStatus::Released !== $release->getStatus()
            ) {
                throw AssessmentScoringException::resultNotReleased();
            }

            $releasedAt = $release->getReleasedAt();
            if (null === $releasedAt) {
                throw AssessmentScoringException::resultNotReleased();
            }

            $run = $release->getScoringRun();
            $scoresByItem = [];
            foreach ($this->itemScores->findAllForRun($run->getId()) as $score) {
                $scoresByItem[$score->getAttemptItem()->getId()->toRfc4122()] = $score;
            }

            $items = [];
            foreach ($this->attemptItems->findItemsForAttemptOrdered($attempt->getId()) as $attemptItem) {
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
                $attempt->getId(),
                $attempt->getAssessment()->getId(),
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
}
