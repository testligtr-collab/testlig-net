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
        $attempt = $this->reloadAttempt($attempt->getId());
        foreach ($this->attemptItems()->findItemsForAttemptOrdered($attempt->getId()) as $item) {
            $this->attempts()->saveAnswer(
                $attempt,
                $item,
                $student,
                $this->singleChoicePayload($selectedStableKey),
                0,
                'save_'.$reasonPrefix.'_'.$item->getPresentationPosition(),
            );
            $attempt = $this->reloadAttempt($attempt->getId());
        }
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
     * Submit without answers so item scores are unanswered.
     *
     * @param array<string, mixed> $fx
     */
    private function submitScoreReleaseUnansweredForStudent(
        array $fx,
        User $student,
        string $reasonPrefix,
    ): AssessmentAttempt {
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $student = $this->reloadUser($student->getId());
        $attempt = $this->attempts()->startAttempt($delivery, $student, 'start_'.$reasonPrefix);
        $this->attempts()->submit(
            $this->reloadAttempt($attempt->getId()),
            $student,
            'submit_'.$reasonPrefix,
        );
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
     * Classroom delivery backed by a multi-item shuffled publication (for order determinism tests).
     *
     * @return array{
     *     fx: array<string, mixed>,
     *     attempts: list<AssessmentAttempt>,
     *     students: list<User>,
     *     questions: list<\App\Entity\Question>
     * }
     */
    private function releaseShuffledMultiItemClassroomCohort(string $prefix, int $studentCount): array
    {
        self::assertGreaterThanOrEqual(1, $studentCount);
        [$owner, $sa, $institution, $year, $classroom] = $this->readyClassroom($prefix);
        unset($year);

        $reviewer = $this->activeUser($prefix.'-rev@example.com', \App\Enum\UserRole::HeadTeacher);
        $teacher = $this->activeUser($prefix.'-teacher@example.com');
        $student = $this->activeUser($prefix.'-student@example.com');
        $teacherMembership = $this->membershipManager()->addMember(
            $institution,
            $owner,
            $teacher,
            InstitutionMembershipRole::Teacher,
            'add_teacher',
        );
        $studentMembership = $this->membershipManager()->addMember(
            $institution,
            $owner,
            $student,
            InstitutionMembershipRole::Student,
            'add_student',
        );
        $this->teacherManager()->assign(
            $classroom,
            $owner,
            $teacherMembership,
            \App\Enum\TeacherAssignmentRole::HomeroomTeacher,
            'assign_t',
        );
        $this->enrollmentManager()->enroll($classroom, $owner, $studentMembership, 'enroll_s');

        $published = $this->publishShuffledMultiItemPlatformAssessment($sa, $reviewer, $prefix);
        $students = [$this->reloadUser($student->getId())];
        for ($i = 2; $i <= $studentCount; ++$i) {
            $extra = $this->activeUser($prefix.'-s'.$i.'@example.com');
            $membership = $this->membershipManager()->addMember(
                $institution,
                $owner,
                $extra,
                InstitutionMembershipRole::Student,
                'add_s'.$i,
            );
            $this->enrollmentManager()->enroll($classroom, $owner, $membership, 'enroll_s'.$i);
            $students[] = $this->reloadUser($extra->getId());
        }

        [$opens, $closes] = $this->defaultWindow();
        $delivery = $this->deliveries()->createDraft(
            $institution,
            $published['publication'],
            AssessmentDeliveryAudienceType::Classroom,
            $classroom,
            null,
            $owner,
            $opens,
            $closes,
            2,
            null,
            null,
            'create_an_'.$prefix,
        );
        $this->deliveries()->activate($delivery, $owner, 'activate_an_'.$prefix);
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);

        $fx = [
            'owner' => $owner,
            'sa' => $sa,
            'reviewer' => $reviewer,
            'institution' => $institution,
            'classroom' => $classroom,
            'teacher' => $teacher,
            'teacherMembership' => $teacherMembership,
            'student' => $student,
            'studentMembership' => $studentMembership,
            'assessment' => $published['assessment'],
            'publication' => $published['publication'],
            'delivery' => $delivery,
        ];

        $attempts = [];
        foreach ($students as $index => $s) {
            $attempts[] = $this->submitScoreReleaseForStudent($fx, $s, $prefix.'_'.$index, 'opt_b');
        }

        return [
            'fx' => $fx,
            'attempts' => $attempts,
            'students' => $students,
            'questions' => $published['questions'],
        ];
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
