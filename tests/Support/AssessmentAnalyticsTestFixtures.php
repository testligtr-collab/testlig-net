<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Analytics\AnalyticsMetricsPolicy;
use App\Analytics\AnalyticsPrivacyPolicy;
use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentDelivery;
use App\Entity\User;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\InstitutionMembershipRole;
use App\Service\AssessmentAnalyticsAccessGate;
use App\Service\AssessmentAnalyticsReader;

/**
 * Shared helpers for Stage 2.14 assessment analytics tests.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait AssessmentAnalyticsTestFixtures
{
    use AssessmentScoringTestFixtures;

    private function analyticsReader(): AssessmentAnalyticsReader
    {
        $s = static::getContainer()->get(AssessmentAnalyticsReader::class);
        self::assertInstanceOf(AssessmentAnalyticsReader::class, $s);

        return $s;
    }

    private function analyticsAccessGate(): AssessmentAnalyticsAccessGate
    {
        $s = static::getContainer()->get(AssessmentAnalyticsAccessGate::class);
        self::assertInstanceOf(AssessmentAnalyticsAccessGate::class, $s);

        return $s;
    }

    private function analyticsMetrics(): AnalyticsMetricsPolicy
    {
        $s = static::getContainer()->get(AnalyticsMetricsPolicy::class);
        self::assertInstanceOf(AnalyticsMetricsPolicy::class, $s);

        return $s;
    }

    private function analyticsPrivacy(): AnalyticsPrivacyPolicy
    {
        $s = static::getContainer()->get(AnalyticsPrivacyPolicy::class);
        self::assertInstanceOf(AnalyticsPrivacyPolicy::class, $s);

        return $s;
    }

    /**
     * @return array{0: AssessmentAttempt, 1: array<string, mixed>}
     */
    private function submitScoreAndReleaseClassroomAttempt(string $prefix, string $selectedStableKey = 'opt_b'): array
    {
        $fx = $this->activatedClassroomDelivery($prefix);
        $attempt = $this->submitScoreReleaseForStudent(
            $fx,
            $this->reloadUser($fx['student']->getId()),
            $prefix,
            $selectedStableKey,
        );

        return [$attempt, $fx];
    }

    /**
     * @param array<string, mixed> $fx
     */
    private function submitScoreReleaseForStudent(
        array $fx,
        User $student,
        string $reasonPrefix,
        string $selectedStableKey = 'opt_b',
    ): AssessmentAttempt {
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $student = $this->reloadUser($student->getId());
        $attempt = $this->attempts()->startAttempt($delivery, $student, 'start_'.$reasonPrefix);
        $item = $this->firstAttemptItem($attempt);
        $this->attempts()->saveAnswer(
            $attempt,
            $item,
            $student,
            $this->singleChoicePayload($selectedStableKey),
            0,
            'save_'.$reasonPrefix,
        );
        $this->attempts()->submit($attempt, $student, 'submit_'.$reasonPrefix);
        $attempt = $this->reloadAttempt($attempt->getId());
        $run = $this->scoring()->scoreAttempt($attempt, null, 'score_'.$reasonPrefix);
        $this->releases()->release(
            $this->reloadScoringRun($run->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'release_'.$reasonPrefix,
        );

        return $this->reloadAttempt($attempt->getId());
    }

    /**
     * Build a classroom delivery with N enrolled students (materialized at activate), then score+release each.
     *
     * @param list<string> $selectedKeysPerStudent stable keys aligned to student index (0-based)
     *
     * @return array{
     *     fx: array<string, mixed>,
     *     attempts: list<AssessmentAttempt>,
     *     students: list<User>
     * }
     */
    private function releaseClassroomCohort(string $prefix, int $studentCount, ?array $selectedKeysPerStudent = null): array
    {
        self::assertGreaterThanOrEqual(1, $studentCount);
        $ctx = $this->publishedDeliveryContext($prefix);
        $students = [$this->reloadUser($ctx['student']->getId())];
        for ($i = 2; $i <= $studentCount; ++$i) {
            $student = $this->activeUser($prefix.'-s'.$i.'@example.com');
            $membership = $this->membershipManager()->addMember(
                $ctx['institution'],
                $ctx['owner'],
                $student,
                InstitutionMembershipRole::Student,
                'add_s'.$i,
            );
            $this->enrollmentManager()->enroll($ctx['classroom'], $ctx['owner'], $membership, 'enroll_s'.$i);
            $students[] = $this->reloadUser($student->getId());
        }

        [$opens, $closes] = $this->defaultWindow();
        $delivery = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Classroom,
            $ctx['classroom'],
            null,
            $ctx['owner'],
            $opens,
            $closes,
            2,
            null,
            null,
            'create_an_'.$prefix,
        );
        $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_an_'.$prefix);
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);

        $fx = $ctx + ['delivery' => $delivery];
        $attempts = [];
        foreach ($students as $index => $student) {
            $key = $selectedKeysPerStudent[$index] ?? 'opt_b';
            $attempts[] = $this->submitScoreReleaseForStudent(
                $fx,
                $student,
                $prefix.'_'.$index,
                $key,
            );
        }

        return [
            'fx' => $fx,
            'attempts' => $attempts,
            'students' => $students,
        ];
    }
}
