<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AnalyticsSuppressionReason;
use App\Enum\AssessmentAttemptStatus;
use App\Enum\ResultReleaseStatus;
use App\Exception\AssessmentAnalyticsException;
use App\Tests\Support\AssessmentAnalyticsTestFixtures;
use App\Tests\Support\JsonKeyTree;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

final class AssessmentAnalyticsReaderTest extends KernelTestCase
{
    use AssessmentAnalyticsTestFixtures;

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

    public function testSummaryUsesActiveReleaseOnlyAfterRegrade(): void
    {
        [$attempt, $fx] = $this->submitScoreAndReleaseClassroomAttempt('aar1');
        $deliveryId = $fx['delivery']->getId();

        $before = $this->analyticsReader()->readAssessmentSummary(
            $this->reloadUser($fx['owner']->getId()),
            $deliveryId,
        );
        self::assertSame(1, $before->getReleasedResultCount());
        $pctBefore = $before->toArray();
        self::assertTrue($pctBefore['suppressed']);

        $regrade = $this->scoring()->regradeAttempt($this->reloadAttempt($attempt->getId()), null, 'regrade_aar1');
        $summaryMid = $this->analyticsReader()->readAssessmentSummary(
            $this->reloadUser($fx['owner']->getId()),
            $deliveryId,
        );
        self::assertSame(1, $summaryMid->getReleasedResultCount());

        $this->releases()->release(
            $this->reloadScoringRun($regrade->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'release2_aar1',
        );
        $after = $this->analyticsReader()->readAssessmentSummary(
            $this->reloadUser($fx['owner']->getId()),
            $deliveryId,
        );
        self::assertSame(1, $after->getReleasedResultCount());
    }

    public function testWithdrawnReleaseExcluded(): void
    {
        [$attempt, $fx] = $this->submitScoreAndReleaseClassroomAttempt('aar2');
        $releases = $this->resultReleases()->findBy(['attempt' => $attempt]);
        self::assertCount(1, $releases);
        $this->releases()->withdraw(
            $this->reloadRelease($releases[0]->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'withdraw_aar2',
        );

        $view = $this->analyticsReader()->readAssessmentSummary(
            $this->reloadUser($fx['owner']->getId()),
            $fx['delivery']->getId(),
        );
        self::assertSame(0, $view->getReleasedResultCount());
        self::assertTrue($view->isSuppressed());
        self::assertSame(AnalyticsSuppressionReason::NoReleasedResults, $view->getSuppressionReason());
    }

    public function testCancelledAttemptExcludedFromAverages(): void
    {
        $cohort = $this->releaseClassroomCohort('aar3', 1);
        $fx = $cohort['fx'];
        $owner = $this->reloadUser($fx['owner']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());

        $student2 = $this->activeUser('aar3-cancel@example.com');
        $membership2 = $this->membershipManager()->addMember(
            $fx['institution'],
            $owner,
            $student2,
            \App\Enum\InstitutionMembershipRole::Student,
            'add_cancel',
        );
        $this->enrollmentManager()->enroll($fx['classroom'], $owner, $membership2, 'enroll_cancel');
        $this->deliveries()->addEligibleRecipient(
            $this->reloadDelivery($delivery->getId()),
            $membership2,
            $owner,
            'add_recip',
        );

        $attempt2 = $this->attempts()->startAttempt(
            $this->reloadDelivery($delivery->getId()),
            $this->reloadUser($student2->getId()),
            'start_cancel',
        );
        $this->attempts()->cancel(
            $this->reloadAttempt($attempt2->getId()),
            $owner,
            'cancel_aar3',
            'admin_cancel',
        );
        $cancelled = $this->reloadAttempt($attempt2->getId());
        self::assertSame(AssessmentAttemptStatus::Cancelled, $cancelled->getStatus());

        $view = $this->analyticsReader()->readAssessmentSummary($owner, $delivery->getId());
        self::assertSame(1, $view->getReleasedResultCount());
        self::assertSame(1, $view->getStartedAttemptCount());
    }

    public function testCohortBelowFiveSuppressesPercentages(): void
    {
        $cohort = $this->releaseClassroomCohort('aar4', 4);
        $view = $this->analyticsReader()->readAssessmentSummary(
            $this->reloadUser($cohort['fx']['owner']->getId()),
            $cohort['fx']['delivery']->getId(),
        );
        self::assertSame(4, $view->getReleasedResultCount());
        self::assertTrue($view->isSuppressed());
        $keys = JsonKeyTree::collectKeys($view->toArray());
        self::assertNotContains('averagePercentage', $keys);
        self::assertNotContains('medianPercentage', $keys);
        self::assertNotContains('distribution', $keys);
        self::assertNotContains('participationRate', $keys);
        self::assertNotContains('completionRate', $keys);
        self::assertContains('eligibleRecipientCount', $keys);
    }

    public function testCohortOfFiveAllowsAggregates(): void
    {
        $cohort = $this->releaseClassroomCohort('aar5', 5);
        $view = $this->analyticsReader()->readAssessmentSummary(
            $this->reloadUser($cohort['fx']['owner']->getId()),
            $cohort['fx']['delivery']->getId(),
        );
        self::assertSame(5, $view->getReleasedResultCount());
        self::assertFalse($view->isSuppressed());
        $payload = $view->toArray();
        self::assertArrayHasKey('averagePercentage', $payload);
        self::assertArrayHasKey('medianPercentage', $payload);
        self::assertArrayHasKey('distribution', $payload);
        self::assertArrayHasKey('participationRate', $payload);
        self::assertArrayHasKey('completionRate', $payload);
        self::assertSame('100.0000', $payload['averagePercentage']);
    }

    public function testClassroomAnalyticsRequiresClassroomAudience(): void
    {
        $cohort = $this->releaseClassroomCohort('aar6', 1);
        $view = $this->analyticsReader()->readClassroomAnalytics(
            $this->reloadUser($cohort['fx']['owner']->getId()),
            $cohort['fx']['delivery']->getId(),
        );
        self::assertTrue($view->getClassroomId()->equals($cohort['fx']['classroom']->getId()));
    }

    public function testStudentAnalyticsAndLearningOutcomes(): void
    {
        [$attempt, $fx] = $this->submitScoreAndReleaseClassroomAttempt('aar7');
        $view = $this->analyticsReader()->readStudentAnalytics(
            $this->reloadUser($fx['owner']->getId()),
            $attempt->getId(),
        );
        self::assertTrue($view->getAttemptId()->equals($attempt->getId()));
        self::assertSame('100.0000', $view->getPercentage());
        self::assertNotEmpty($view->getLearningOutcomes());
        self::assertArrayHasKey('performanceBand', $view->getLearningOutcomes()[0]);
    }

    public function testQuestionAnalyticsOmitsOptionDistribution(): void
    {
        $cohort = $this->releaseClassroomCohort('aar8', 5);
        $views = $this->analyticsReader()->readQuestionAnalytics(
            $this->reloadUser($cohort['fx']['owner']->getId()),
            $cohort['fx']['delivery']->getId(),
        );
        self::assertNotEmpty($views);
        $keys = JsonKeyTree::collectKeys($views[0]->toArray());
        self::assertNotContains('optionDistribution', $keys);
        self::assertArrayHasKey('correctRate', $views[0]->toArray());
    }

    public function testLearningOutcomeAnalyticsSuppressedBelowThreshold(): void
    {
        $cohort = $this->releaseClassroomCohort('aar9', 3);
        $views = $this->analyticsReader()->readLearningOutcomeAnalytics(
            $this->reloadUser($cohort['fx']['owner']->getId()),
            $cohort['fx']['delivery']->getId(),
        );
        self::assertNotEmpty($views);
        self::assertTrue($views[0]->isSuppressed());
        $keys = JsonKeyTree::collectKeys($views[0]->toArray());
        self::assertNotContains('percentage', $keys);
        self::assertNotContains('performanceBand', $keys);
    }

    public function testActiveReleaseStatusIsReleased(): void
    {
        [$attempt] = $this->submitScoreAndReleaseClassroomAttempt('aar10');
        $release = $this->resultReleases()->findActiveReleasedForAttempt($attempt->getId());
        self::assertNotNull($release);
        self::assertSame(ResultReleaseStatus::Released, $release->getStatus());
    }

    public function testStudentOwnAnalyticsAllowed(): void
    {
        [$attempt, $fx] = $this->submitScoreAndReleaseClassroomAttempt('aar11');
        $view = $this->analyticsReader()->readStudentAnalytics(
            $this->reloadUser($fx['student']->getId()),
            $attempt->getId(),
        );
        self::assertTrue($view->getUserId()->equals($fx['student']->getId()));
    }

    public function testUnreleasedAttemptDeniesStudentAnalytics(): void
    {
        [$attempt, , $fx] = $this->submitAndScoreClassroomAttempt('aar12');
        try {
            $this->analyticsReader()->readStudentAnalytics(
                $this->reloadUser($fx['owner']->getId()),
                $attempt->getId(),
            );
            self::fail('Expected result not released');
        } catch (AssessmentAnalyticsException $e) {
            self::assertSame(\App\Enum\AnalyticsFailureReason::ResultNotReleased, $e->getReason());
        }
    }
}
