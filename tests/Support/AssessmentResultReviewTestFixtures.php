<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentResultReviewPolicy;
use App\Enum\ResultReviewAvailabilityMode;
use App\Repository\AssessmentResultActiveReviewPolicyGuardRepository;
use App\Repository\AssessmentResultReviewPolicyRepository;
use App\ResultReview\AssessmentResultReviewPolicyHasher;
use App\Service\AssessmentResultReviewAccessGate;
use App\Service\AssessmentResultReviewPolicyManager;
use App\Service\AssessmentResultReviewReader;

/**
 * Shared helpers for Stage 2.13 assessment result review policy tests.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait AssessmentResultReviewTestFixtures
{
    use AssessmentScoringTestFixtures;

    private function reviewPolicies(): AssessmentResultReviewPolicyManager
    {
        $s = static::getContainer()->get(AssessmentResultReviewPolicyManager::class);
        self::assertInstanceOf(AssessmentResultReviewPolicyManager::class, $s);

        return $s;
    }

    private function reviewAccessGate(): AssessmentResultReviewAccessGate
    {
        $s = static::getContainer()->get(AssessmentResultReviewAccessGate::class);
        self::assertInstanceOf(AssessmentResultReviewAccessGate::class, $s);

        return $s;
    }

    private function reviewReader(): AssessmentResultReviewReader
    {
        $s = static::getContainer()->get(AssessmentResultReviewReader::class);
        self::assertInstanceOf(AssessmentResultReviewReader::class, $s);

        return $s;
    }

    private function reviewPolicyRepo(): AssessmentResultReviewPolicyRepository
    {
        $s = static::getContainer()->get(AssessmentResultReviewPolicyRepository::class);
        self::assertInstanceOf(AssessmentResultReviewPolicyRepository::class, $s);

        return $s;
    }

    private function reviewPolicyGuardRepo(): AssessmentResultActiveReviewPolicyGuardRepository
    {
        $s = static::getContainer()->get(AssessmentResultActiveReviewPolicyGuardRepository::class);
        self::assertInstanceOf(AssessmentResultActiveReviewPolicyGuardRepository::class, $s);

        return $s;
    }

    private function reviewPolicyHasher(): AssessmentResultReviewPolicyHasher
    {
        $s = static::getContainer()->get(AssessmentResultReviewPolicyHasher::class);
        self::assertInstanceOf(AssessmentResultReviewPolicyHasher::class, $s);

        return $s;
    }

    private function reloadReviewPolicy(\Symfony\Component\Uid\Uuid $id): AssessmentResultReviewPolicy
    {
        $this->em->clear();
        $policy = $this->reviewPolicyRepo()->findOneById($id);
        self::assertInstanceOf(AssessmentResultReviewPolicy::class, $policy);

        return $policy;
    }

    /**
     * @return array{0: \App\Entity\AssessmentAttempt, 1: \App\Entity\AssessmentScoringRun, 2: array<string, mixed>, 3: AssessmentResultReviewPolicy}
     */
    private function submitScoreReleaseWithPolicy(
        string $prefix,
        ResultReviewAvailabilityMode $mode = ResultReviewAvailabilityMode::AfterDeliveryClosed,
        bool $showScoreSummary = true,
        bool $showItemOutcomes = true,
        bool $showStudentAnswer = true,
        bool $showCorrectAnswer = true,
        bool $showExplanation = true,
        ?\DateTimeImmutable $scheduledAt = null,
    ): array {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt($prefix);
        $owner = $this->reloadUser($fx['owner']->getId());
        $this->releases()->release(
            $this->reloadScoringRun($run->getId()),
            $owner,
            'release_'.$prefix,
        );

        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $draft = $this->reviewPolicies()->createDraft(
            $delivery,
            $owner,
            $mode,
            $scheduledAt,
            $showScoreSummary,
            $showItemOutcomes,
            $showStudentAnswer,
            $showCorrectAnswer,
            $showExplanation,
            'policy_'.$prefix,
        );
        $policy = $this->reviewPolicies()->activate(
            $this->reloadReviewPolicy($draft->getId()),
            $owner,
            'activate_'.$prefix,
        );

        return [
            $this->reloadAttempt($attempt->getId()),
            $this->reloadScoringRun($run->getId()),
            $fx,
            $this->reloadReviewPolicy($policy->getId()),
        ];
    }

    private function activateFullReviewPolicy(AssessmentDelivery $delivery, \App\Entity\User $actor, string $reason): AssessmentResultReviewPolicy
    {
        $draft = $this->reviewPolicies()->createDraft(
            $delivery,
            $actor,
            ResultReviewAvailabilityMode::AfterDeliveryClosed,
            null,
            true,
            true,
            true,
            true,
            true,
            $reason.'_draft',
        );

        return $this->reviewPolicies()->activate(
            $this->reloadReviewPolicy($draft->getId()),
            $actor,
            $reason.'_activate',
        );
    }
}
