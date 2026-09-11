<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AnalyticsSuppressionReason;
use App\Enum\ResultReleaseStatus;
use App\Tests\Support\AssessmentAnalyticsTestFixtures;
use App\Tests\Support\JsonKeyTree;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

final class AssessmentAnalyticsPrivacyOrderingTest extends KernelTestCase
{
    use AssessmentAnalyticsTestFixtures;

    private const QUESTION_PERFORMANCE_KEYS = [
        'scoredResponseCount',
        'outcomes',
        'correctCount',
        'incorrectCount',
        'unansweredCount',
        'manualPendingCount',
        'manuallyGradedCount',
        'invalidCount',
        'correctRate',
        'optionDistribution',
        'presentationPosition',
        'awardedPoints',
        'maximumPoints',
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupDeliveryFixtures();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        if (isset($this->em)) {
            $this->cleanupDeliveryFixtures();
        }
        parent::tearDown();
    }

    public function testSummaryAndClassroomSuppressRatesBelowThreshold(): void
    {
        $cohort = $this->releaseClassroomCohort('aap1', 4);
        $owner = $this->reloadUser($cohort['fx']['owner']->getId());
        $deliveryId = $cohort['fx']['delivery']->getId();

        $summary = $this->analyticsReader()->readAssessmentSummary($owner, $deliveryId);
        $classroom = $this->analyticsReader()->readClassroomAnalytics($owner, $deliveryId);

        self::assertTrue($summary->isSuppressed());
        self::assertTrue($classroom->isSuppressed());
        self::assertNull($summary->getParticipationRate());
        self::assertNull($summary->getCompletionRate());
        self::assertNull($classroom->getParticipationRate());
        self::assertNull($classroom->getCompletionRate());

        foreach ([$summary->toArray(), $classroom->toArray()] as $payload) {
            self::assertArrayNotHasKey('participationRate', $payload);
            self::assertArrayNotHasKey('completionRate', $payload);
            self::assertArrayHasKey('eligibleRecipientCount', $payload);
            self::assertArrayHasKey('releasedResultCount', $payload);
        }
    }

    public function testSummaryAndClassroomAllowRatesAtThreshold(): void
    {
        $cohort = $this->releaseClassroomCohort('aap2', 5);
        $owner = $this->reloadUser($cohort['fx']['owner']->getId());
        $deliveryId = $cohort['fx']['delivery']->getId();

        $summary = $this->analyticsReader()->readAssessmentSummary($owner, $deliveryId);
        $classroom = $this->analyticsReader()->readClassroomAnalytics($owner, $deliveryId);

        self::assertFalse($summary->isSuppressed());
        self::assertFalse($classroom->isSuppressed());
        self::assertSame('100.0000', $summary->getParticipationRate());
        self::assertSame('100.0000', $summary->getCompletionRate());
        self::assertSame('100.0000', $classroom->getParticipationRate());
        self::assertSame('100.0000', $classroom->getCompletionRate());

        foreach ([$summary->toArray(), $classroom->toArray()] as $payload) {
            self::assertArrayHasKey('participationRate', $payload);
            self::assertArrayHasKey('completionRate', $payload);
            self::assertSame('100.0000', $payload['participationRate']);
            self::assertSame('100.0000', $payload['completionRate']);
        }
    }

    public function testNoReleasedResultsOmitsRates(): void
    {
        [$attempt, $fx] = $this->submitScoreAndReleaseClassroomAttempt('aap3');
        $releases = $this->resultReleases()->findBy(['attempt' => $attempt]);
        self::assertCount(1, $releases);
        $this->releases()->withdraw(
            $this->reloadRelease($releases[0]->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'withdraw_aap3',
        );

        $summary = $this->analyticsReader()->readAssessmentSummary(
            $this->reloadUser($fx['owner']->getId()),
            $fx['delivery']->getId(),
        );
        self::assertSame(0, $summary->getReleasedResultCount());
        self::assertTrue($summary->isSuppressed());
        self::assertSame(AnalyticsSuppressionReason::NoReleasedResults, $summary->getSuppressionReason());
        self::assertNull($summary->getParticipationRate());
        self::assertNull($summary->getCompletionRate());
        $payload = $summary->toArray();
        self::assertArrayNotHasKey('participationRate', $payload);
        self::assertArrayNotHasKey('completionRate', $payload);
        self::assertSame(ResultReleaseStatus::Withdrawn, $this->reloadRelease($releases[0]->getId())->getStatus());
    }

    public function testQuestionSuppressionHidesOutcomesForCohortOne(): void
    {
        $cohort = $this->releaseClassroomCohort('aap4', 1, ['opt_b']);
        $this->assertQuestionPayloadFullySuppressed(
            $this->reloadUser($cohort['fx']['owner']->getId()),
            $cohort['fx']['delivery']->getId(),
        );
    }

    public function testQuestionSuppressionHidesOutcomesForCohortFour(): void
    {
        $cohort = $this->releaseClassroomCohort('aap5', 4);
        $this->assertQuestionPayloadFullySuppressed(
            $this->reloadUser($cohort['fx']['owner']->getId()),
            $cohort['fx']['delivery']->getId(),
        );
    }

    public function testQuestionAllowsAggregatesAtCohortFive(): void
    {
        $cohort = $this->releaseClassroomCohort('aap6', 5, array_fill(0, 5, 'opt_b'));
        $views = $this->analyticsReader()->readQuestionAnalytics(
            $this->reloadUser($cohort['fx']['owner']->getId()),
            $cohort['fx']['delivery']->getId(),
        );
        self::assertCount(1, $views);
        $view = $views[0];
        self::assertFalse($view->isSuppressed());
        self::assertSame(5, $view->getScoredResponseCount());
        self::assertNotNull($view->getOutcomes());
        self::assertSame(5, $view->getOutcomes()->getCorrectCount());
        self::assertSame('100.0000', $view->getCorrectRate());
        $payload = $view->toArray();
        self::assertArrayHasKey('scoredResponseCount', $payload);
        self::assertArrayHasKey('outcomes', $payload);
        self::assertArrayHasKey('correctRate', $payload);
        self::assertArrayNotHasKey('optionDistribution', $payload);
        self::assertArrayNotHasKey('presentationPosition', $payload);
    }

    public function testSingleStudentCorrectCannotBeInferredFromQuestionDto(): void
    {
        $cohort = $this->releaseClassroomCohort('aap7', 1, ['opt_b']);
        $this->assertQuestionPayloadFullySuppressed(
            $this->reloadUser($cohort['fx']['owner']->getId()),
            $cohort['fx']['delivery']->getId(),
        );
    }

    public function testSingleStudentIncorrectCannotBeInferredFromQuestionDto(): void
    {
        $cohort = $this->releaseClassroomCohort('aap8', 1, ['opt_a']);
        $this->assertQuestionPayloadFullySuppressed(
            $this->reloadUser($cohort['fx']['owner']->getId()),
            $cohort['fx']['delivery']->getId(),
        );
    }

    public function testSingleStudentUnansweredCannotBeInferredFromQuestionDto(): void
    {
        $fx = $this->activatedClassroomDelivery('aap9');
        $this->submitScoreReleaseUnansweredForStudent(
            $fx,
            $this->reloadUser($fx['student']->getId()),
            'aap9_u',
        );
        $this->assertQuestionPayloadFullySuppressed(
            $this->reloadUser($fx['owner']->getId()),
            $fx['delivery']->getId(),
        );
    }

    public function testQuestionOrderUsesBlueprintNotShuffle(): void
    {
        $cohort = $this->releaseShuffledMultiItemClassroomCohort('aap10', 2);
        $owner = $this->reloadUser($cohort['fx']['owner']->getId());
        $deliveryId = $cohort['fx']['delivery']->getId();

        $expectedBlueprint = [
            [$cohort['questions'][0]->getId()->toRfc4122(), 1, 1],
            [$cohort['questions'][1]->getId()->toRfc4122(), 1, 2],
            [$cohort['questions'][2]->getId()->toRfc4122(), 2, 1],
        ];

        foreach ($cohort['attempts'] as $attempt) {
            $rows = [];
            foreach ($this->attemptItems()->findItemsForAttemptOrdered($attempt->getId()) as $item) {
                $rows[] = [
                    'qid' => $item->getQuestion()->getId()->toRfc4122(),
                    'presentation' => $item->getPresentationPosition(),
                    'section' => $item->getSectionPosition(),
                    'item' => $item->getItemPosition(),
                ];
            }
            $byBlueprint = $rows;
            usort(
                $byBlueprint,
                static fn (array $a, array $b): int => [$a['section'], $a['item'], $a['qid']]
                    <=> [$b['section'], $b['item'], $b['qid']],
            );
            self::assertSame(
                $expectedBlueprint,
                array_map(
                    static fn (array $row): array => [$row['qid'], $row['section'], $row['item']],
                    $byBlueprint,
                ),
            );
        }

        $first = $this->analyticsReader()->readQuestionAnalytics($owner, $deliveryId);
        $second = $this->analyticsReader()->readQuestionAnalytics($owner, $deliveryId);
        self::assertCount(3, $first);
        self::assertSame(
            array_map(static fn ($v) => $v->getQuestionId()->toRfc4122(), $first),
            array_map(static fn ($v) => $v->getQuestionId()->toRfc4122(), $second),
        );
        self::assertSame(1, $first[0]->getSectionPosition());
        self::assertSame(1, $first[0]->getItemPosition());
        self::assertSame(1, $first[1]->getSectionPosition());
        self::assertSame(2, $first[1]->getItemPosition());
        self::assertSame(2, $first[2]->getSectionPosition());
        self::assertSame(1, $first[2]->getItemPosition());
        self::assertTrue($first[0]->getQuestionId()->equals($cohort['questions'][0]->getId()));
        self::assertTrue($first[1]->getQuestionId()->equals($cohort['questions'][1]->getId()));
        self::assertTrue($first[2]->getQuestionId()->equals($cohort['questions'][2]->getId()));
        self::assertArrayNotHasKey('presentationPosition', $first[0]->toArray());

        $extra = $this->activeUser('aap10-s3@example.com');
        $membership = $this->membershipManager()->addMember(
            $cohort['fx']['institution'],
            $owner,
            $extra,
            \App\Enum\InstitutionMembershipRole::Student,
            'add_s3',
        );
        $this->enrollmentManager()->enroll($cohort['fx']['classroom'], $owner, $membership, 'enroll_s3');
        $this->deliveries()->addEligibleRecipient(
            $this->reloadDelivery($deliveryId),
            $membership,
            $owner,
            'add_recip_s3',
        );
        $this->submitScoreReleaseForStudent($cohort['fx'], $this->reloadUser($extra->getId()), 'aap10_2', 'opt_b');

        $afterGrowth = $this->analyticsReader()->readQuestionAnalytics($owner, $deliveryId);
        self::assertSame(
            array_map(static fn ($v) => $v->getQuestionId()->toRfc4122(), $first),
            array_map(static fn ($v) => $v->getQuestionId()->toRfc4122(), $afterGrowth),
        );
    }

    /**
     * @param \Symfony\Component\Uid\Uuid $deliveryId
     */
    private function assertQuestionPayloadFullySuppressed(\App\Entity\User $owner, $deliveryId): void
    {
        $views = $this->analyticsReader()->readQuestionAnalytics($owner, $deliveryId);
        self::assertNotEmpty($views);
        foreach ($views as $view) {
            self::assertTrue($view->isSuppressed());
            self::assertNull($view->getScoredResponseCount());
            self::assertNull($view->getOutcomes());
            self::assertNull($view->getCorrectRate());
            self::assertNull($view->getOptionDistribution());

            $payload = $view->toArray();
            self::assertArrayHasKey('questionId', $payload);
            self::assertArrayHasKey('questionRevisionId', $payload);
            self::assertArrayHasKey('sectionPosition', $payload);
            self::assertArrayHasKey('itemPosition', $payload);
            self::assertTrue($payload['suppressed']);
            self::assertArrayHasKey('suppressionReason', $payload);

            $keys = JsonKeyTree::collectKeys($payload);
            foreach (self::QUESTION_PERFORMANCE_KEYS as $forbidden) {
                self::assertNotContains($forbidden, $keys, 'Leaked performance key: '.$forbidden);
            }
        }
    }
}
