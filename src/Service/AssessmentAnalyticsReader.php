<?php

declare(strict_types=1);

namespace App\Service;

use App\Analytics\AnalyticsMetricsPolicy;
use App\Analytics\AnalyticsPrivacyPolicy;
use App\Dto\AnalyticsSuppression;
use App\Dto\AssessmentAnalyticsSummaryView;
use App\Dto\ClassroomAnalyticsView;
use App\Dto\LearningOutcomeAnalyticsView;
use App\Dto\OutcomeCountBreakdown;
use App\Dto\PercentageDistributionBucket;
use App\Dto\QuestionAnalyticsView;
use App\Dto\SecurityAuditContext;
use App\Dto\StudentAnalyticsView;
use App\Entity\User;
use App\Enum\AnalyticsSuppressionReason;
use App\Enum\AnalyticsType;
use App\Enum\AssessmentAttemptStatus;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\AssessmentDeliveryRecipientStatus;
use App\Enum\ItemScoreOutcome;
use App\Enum\ResultReleaseStatus;
use App\Enum\ScoringRunStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\AssessmentAnalyticsException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Optimized Doctrine/DBAL analytics projections over immutable scoring/release facts.
 *
 * No materialized snapshot tables. Active release guard → completed scoring run only.
 * Option distribution is omitted (selectedStableKey lives in encrypted answers only).
 */
final class AssessmentAnalyticsReader
{
    public function __construct(
        private readonly AssessmentAnalyticsAccessGate $accessGate,
        private readonly AnalyticsPrivacyPolicy $privacyPolicy,
        private readonly AnalyticsMetricsPolicy $metricsPolicy,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function readAssessmentSummary(User $actor, Uuid $deliveryId): AssessmentAnalyticsSummaryView
    {
        return $this->entityManager->wrapInTransaction(function () use ($actor, $deliveryId): AssessmentAnalyticsSummaryView {
            $this->accessGate->assertCanViewDeliveryAnalytics($actor, $deliveryId);
            $scope = $this->accessGate->fetchDeliveryScope($deliveryId);
            if (null === $scope) {
                throw AssessmentAnalyticsException::notFound();
            }

            $counts = $this->fetchParticipationCounts($deliveryId);
            $percentages = $this->fetchReleasedPercentages($deliveryId);
            $releasedCount = \count($percentages);
            $suppression = $this->cohortSuppression($releasedCount);

            $participationRate = $this->metricsPolicy->rate(
                (string) $counts['started'],
                $counts['eligible'],
            );
            $completionRate = $this->metricsPolicy->rate(
                (string) $counts['completed'],
                $counts['eligible'],
            );

            $average = null;
            $median = null;
            $min = null;
            $max = null;
            $distribution = null;
            if (!$suppression->isSuppressed()) {
                $average = $this->metricsPolicy->mean($percentages);
                $median = $this->metricsPolicy->median($percentages);
                $min = $this->metricsPolicy->min($percentages);
                $max = $this->metricsPolicy->max($percentages);
                $distribution = array_map(
                    static fn (array $b): PercentageDistributionBucket => new PercentageDistributionBucket(
                        $b['label'],
                        $b['count'],
                    ),
                    $this->metricsPolicy->distributionBuckets($percentages),
                );
            } elseif (0 === $releasedCount) {
                $suppression = AnalyticsSuppression::of(AnalyticsSuppressionReason::NoReleasedResults);
            }

            $view = new AssessmentAnalyticsSummaryView(
                $deliveryId,
                $scope['assessment_id'],
                $scope['institution_id'],
                $counts['eligible'],
                $counts['started'],
                $counts['completed'],
                $releasedCount,
                $suppression,
                $participationRate,
                $completionRate,
                $average,
                $median,
                $min,
                $max,
                $distribution,
            );

            $this->auditBestEffort(
                SecurityAuditAction::AssessmentAnalyticsViewed,
                $actor,
                [
                    'analytics_type' => AnalyticsType::AssessmentSummary->value,
                    'delivery_id' => $deliveryId->toRfc4122(),
                    'institution_id' => $scope['institution_id']->toRfc4122(),
                    'cohort_size' => $releasedCount,
                    'suppressed' => $suppression->isSuppressed(),
                ],
            );

            return $view;
        });
    }

    public function readClassroomAnalytics(User $actor, Uuid $deliveryId): ClassroomAnalyticsView
    {
        return $this->entityManager->wrapInTransaction(function () use ($actor, $deliveryId): ClassroomAnalyticsView {
            $this->accessGate->assertCanViewDeliveryAnalytics($actor, $deliveryId);
            $scope = $this->accessGate->fetchDeliveryScope($deliveryId);
            if (null === $scope) {
                throw AssessmentAnalyticsException::notFound();
            }
            if (AssessmentDeliveryAudienceType::Classroom->value !== $scope['audience_type']
                || null === $scope['classroom_id']
            ) {
                throw AssessmentAnalyticsException::invalidInput(
                    'Classroom analytics require a classroom-scoped delivery.',
                );
            }

            $this->accessGate->assertCanViewClassroomAnalytics(
                $actor,
                $scope['classroom_id'],
                $scope['institution_id'],
            );

            $counts = $this->fetchParticipationCounts($deliveryId);
            $percentages = $this->fetchReleasedPercentages($deliveryId);
            $releasedCount = \count($percentages);
            $suppression = $this->cohortSuppression($releasedCount);

            $participationRate = $this->metricsPolicy->rate(
                (string) $counts['started'],
                $counts['eligible'],
            );
            $completionRate = $this->metricsPolicy->rate(
                (string) $counts['completed'],
                $counts['eligible'],
            );

            $average = null;
            $median = null;
            $min = null;
            $max = null;
            $distribution = null;
            if (!$suppression->isSuppressed()) {
                $average = $this->metricsPolicy->mean($percentages);
                $median = $this->metricsPolicy->median($percentages);
                $min = $this->metricsPolicy->min($percentages);
                $max = $this->metricsPolicy->max($percentages);
                $distribution = array_map(
                    static fn (array $b): PercentageDistributionBucket => new PercentageDistributionBucket(
                        $b['label'],
                        $b['count'],
                    ),
                    $this->metricsPolicy->distributionBuckets($percentages),
                );
            } elseif (0 === $releasedCount) {
                $suppression = AnalyticsSuppression::of(AnalyticsSuppressionReason::NoReleasedResults);
            }

            $view = new ClassroomAnalyticsView(
                $deliveryId,
                $scope['classroom_id'],
                $scope['assessment_id'],
                $scope['institution_id'],
                $counts['eligible'],
                $counts['started'],
                $counts['completed'],
                $releasedCount,
                $suppression,
                $participationRate,
                $completionRate,
                $average,
                $median,
                $min,
                $max,
                $distribution,
            );

            $this->auditBestEffort(
                SecurityAuditAction::ClassroomAnalyticsViewed,
                $actor,
                [
                    'analytics_type' => AnalyticsType::Classroom->value,
                    'delivery_id' => $deliveryId->toRfc4122(),
                    'classroom_id' => $scope['classroom_id']->toRfc4122(),
                    'institution_id' => $scope['institution_id']->toRfc4122(),
                    'cohort_size' => $releasedCount,
                    'suppressed' => $suppression->isSuppressed(),
                ],
            );

            return $view;
        });
    }

    public function readStudentAnalytics(User $actor, Uuid $attemptId): StudentAnalyticsView
    {
        return $this->entityManager->wrapInTransaction(function () use ($actor, $attemptId): StudentAnalyticsView {
            $scope = $this->accessGate->fetchAttemptScope($attemptId);
            if (null === $scope) {
                throw AssessmentAnalyticsException::notFound();
            }

            $this->accessGate->assertCanViewStudentAnalytics($actor, $scope['user_id'], $attemptId);

            $release = $this->fetchActiveReleasedRun($attemptId);
            if (null === $release) {
                throw AssessmentAnalyticsException::resultNotReleased();
            }
            if (ResultReleaseStatus::Withdrawn->value === $release['status']) {
                throw AssessmentAnalyticsException::resultWithdrawn();
            }
            if (ResultReleaseStatus::Released->value !== $release['status']) {
                throw AssessmentAnalyticsException::resultNotReleased();
            }

            $outcomeRows = $this->connection->fetchAllAssociative(
                'SELECT s.outcome, COUNT(*) AS cnt
                 FROM assessment_item_scores s
                 WHERE s.scoring_run_id = :runId
                 GROUP BY s.outcome',
                ['runId' => Uuid::fromString($release['scoring_run_id'])->toBinary()],
            );
            $outcomes = $this->mapOutcomeBreakdown($outcomeRows);

            $loRows = $this->connection->fetchAllAssociative(
                'SELECT clo.id AS learning_outcome_id, clo.code,
                        SUM(s.awarded_points) AS awarded_sum,
                        SUM(s.maximum_points) AS maximum_sum
                 FROM assessment_item_scores s
                 INNER JOIN question_revision_alignments qra
                     ON qra.revision_id = s.question_revision_id
                 INNER JOIN curriculum_learning_outcomes clo ON clo.id = qra.learning_outcome_id
                 WHERE s.scoring_run_id = :runId
                 GROUP BY clo.id, clo.code
                 ORDER BY clo.code ASC',
                ['runId' => Uuid::fromString($release['scoring_run_id'])->toBinary()],
            );

            $learningOutcomes = [];
            foreach ($loRows as $row) {
                $awarded = $this->metricsPolicy->normalizePoints((string) $row['awarded_sum']);
                $maximum = $this->metricsPolicy->normalizePoints((string) $row['maximum_sum']);
                $pct = $this->metricsPolicy->percentageFromPoints($awarded, $maximum);
                if (null === $pct) {
                    continue;
                }
                $learningOutcomes[] = [
                    'learningOutcomeId' => Uuid::fromBinary((string) $row['learning_outcome_id'])->toRfc4122(),
                    'code' => (string) $row['code'],
                    'awardedPoints' => $awarded,
                    'maximumPoints' => $maximum,
                    'percentage' => $pct,
                    'performanceBand' => $this->metricsPolicy->bandForPercentage($pct)->value,
                ];
            }

            $releasedAt = new \DateTimeImmutable((string) $release['released_at'], new \DateTimeZone('UTC'));
            $view = new StudentAnalyticsView(
                $attemptId,
                $scope['delivery_id'],
                $scope['assessment_id'],
                $scope['user_id'],
                $scope['student_membership_id'],
                (int) $release['release_number'],
                $releasedAt,
                $this->metricsPolicy->normalizePoints((string) $release['final_points']),
                $this->metricsPolicy->normalizePoints((string) $release['maximum_points']),
                $this->metricsPolicy->normalizePercentage((string) $release['percentage']),
                $outcomes,
                $learningOutcomes,
            );

            $this->auditBestEffort(
                SecurityAuditAction::StudentAnalyticsViewed,
                $actor,
                [
                    'analytics_type' => AnalyticsType::Student->value,
                    'attempt_id' => $attemptId->toRfc4122(),
                    'delivery_id' => $scope['delivery_id']->toRfc4122(),
                    'institution_id' => $scope['institution_id']->toRfc4122(),
                    'release_number' => (int) $release['release_number'],
                    'result_release_id' => $release['release_id'],
                ],
            );

            return $view;
        });
    }

    /**
     * @return list<QuestionAnalyticsView>
     */
    public function readQuestionAnalytics(User $actor, Uuid $deliveryId): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($actor, $deliveryId): array {
            $this->accessGate->assertCanViewDeliveryAnalytics($actor, $deliveryId);
            $scope = $this->accessGate->fetchDeliveryScope($deliveryId);
            if (null === $scope) {
                throw AssessmentAnalyticsException::notFound();
            }

            $releasedCount = $this->countReleasedResults($deliveryId);
            $suppression = $this->cohortSuppression($releasedCount);
            if (0 === $releasedCount) {
                $suppression = AnalyticsSuppression::of(AnalyticsSuppressionReason::NoReleasedResults);
            }

            // Order by immutable blueprint positions snapshotted on attempt items
            // (section_position / item_position). Never use presentation_position — that is
            // per-attempt shuffle order and MIN() would drift as the cohort grows.
            $rows = $this->connection->fetchAllAssociative(
                'SELECT s.question_id, s.question_revision_id,
                        MIN(ai.section_position) AS section_position,
                        MIN(ai.item_position) AS item_position,
                        COUNT(*) AS scored_response_count,
                        SUM(CASE WHEN s.outcome = :correct THEN 1 ELSE 0 END) AS correct_count,
                        SUM(CASE WHEN s.outcome = :incorrect THEN 1 ELSE 0 END) AS incorrect_count,
                        SUM(CASE WHEN s.outcome = :unanswered THEN 1 ELSE 0 END) AS unanswered_count,
                        SUM(CASE WHEN s.outcome = :manual_pending THEN 1 ELSE 0 END) AS manual_pending_count,
                        SUM(CASE WHEN s.outcome = :manually_graded THEN 1 ELSE 0 END) AS manually_graded_count,
                        SUM(CASE WHEN s.outcome = :invalid THEN 1 ELSE 0 END) AS invalid_count
                 FROM assessment_result_active_release_guards g
                 INNER JOIN assessment_result_releases r
                     ON r.id = g.release_id AND r.status = :released
                 INNER JOIN assessment_scoring_runs sr
                     ON sr.id = r.scoring_run_id AND sr.status = :completed
                 INNER JOIN assessment_attempts a
                     ON a.id = r.attempt_id AND a.delivery_id = :deliveryId
                    AND a.status <> :cancelled
                 INNER JOIN assessment_item_scores s ON s.scoring_run_id = sr.id
                 INNER JOIN assessment_attempt_items ai ON ai.id = s.attempt_item_id
                 GROUP BY s.question_id, s.question_revision_id
                 ORDER BY section_position ASC, item_position ASC,
                          s.question_id ASC, s.question_revision_id ASC',
                [
                    'deliveryId' => $deliveryId->toBinary(),
                    'released' => ResultReleaseStatus::Released->value,
                    'completed' => ScoringRunStatus::Completed->value,
                    'cancelled' => AssessmentAttemptStatus::Cancelled->value,
                    'correct' => ItemScoreOutcome::Correct->value,
                    'incorrect' => ItemScoreOutcome::Incorrect->value,
                    'unanswered' => ItemScoreOutcome::Unanswered->value,
                    'manual_pending' => ItemScoreOutcome::ManualPending->value,
                    'manually_graded' => ItemScoreOutcome::ManuallyGraded->value,
                    'invalid' => ItemScoreOutcome::Invalid->value,
                ],
            );

            $views = [];
            foreach ($rows as $row) {
                $scored = (int) $row['scored_response_count'];
                $correct = (int) $row['correct_count'];
                $outcomes = new OutcomeCountBreakdown(
                    $correct,
                    (int) $row['incorrect_count'],
                    (int) $row['unanswered_count'],
                    (int) $row['manual_pending_count'],
                    (int) $row['manually_graded_count'],
                    (int) $row['invalid_count'],
                );
                $correctRate = null;
                if (!$suppression->isSuppressed()) {
                    $correctRate = $this->metricsPolicy->rate((string) $correct, $scored);
                }

                $views[] = new QuestionAnalyticsView(
                    Uuid::fromBinary((string) $row['question_id']),
                    Uuid::fromBinary((string) $row['question_revision_id']),
                    (int) $row['section_position'],
                    (int) $row['item_position'],
                    $scored,
                    $suppression,
                    $outcomes,
                    $correctRate,
                    null, // option distribution omitted — requires decrypting attempt answers
                );
            }

            $this->auditBestEffort(
                SecurityAuditAction::QuestionAnalyticsViewed,
                $actor,
                [
                    'analytics_type' => AnalyticsType::Question->value,
                    'delivery_id' => $deliveryId->toRfc4122(),
                    'institution_id' => $scope['institution_id']->toRfc4122(),
                    'cohort_size' => $releasedCount,
                    'suppressed' => $suppression->isSuppressed(),
                    'item_count' => \count($views),
                ],
            );

            return $views;
        });
    }

    /**
     * @return list<LearningOutcomeAnalyticsView>
     */
    public function readLearningOutcomeAnalytics(User $actor, Uuid $deliveryId): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($actor, $deliveryId): array {
            $this->accessGate->assertCanViewDeliveryAnalytics($actor, $deliveryId);
            $scope = $this->accessGate->fetchDeliveryScope($deliveryId);
            if (null === $scope) {
                throw AssessmentAnalyticsException::notFound();
            }

            $releasedCount = $this->countReleasedResults($deliveryId);
            $suppression = $this->cohortSuppression($releasedCount);
            if (0 === $releasedCount) {
                $suppression = AnalyticsSuppression::of(AnalyticsSuppressionReason::NoReleasedResults);
            }

            $rows = $this->connection->fetchAllAssociative(
                'SELECT clo.id AS learning_outcome_id, clo.code,
                        COUNT(*) AS aligned_item_score_count,
                        SUM(s.awarded_points) AS awarded_sum,
                        SUM(s.maximum_points) AS maximum_sum
                 FROM assessment_result_active_release_guards g
                 INNER JOIN assessment_result_releases r
                     ON r.id = g.release_id AND r.status = :released
                 INNER JOIN assessment_scoring_runs sr
                     ON sr.id = r.scoring_run_id AND sr.status = :completed
                 INNER JOIN assessment_attempts a
                     ON a.id = r.attempt_id AND a.delivery_id = :deliveryId
                    AND a.status <> :cancelled
                 INNER JOIN assessment_item_scores s ON s.scoring_run_id = sr.id
                 INNER JOIN question_revision_alignments qra
                     ON qra.revision_id = s.question_revision_id
                 INNER JOIN curriculum_learning_outcomes clo ON clo.id = qra.learning_outcome_id
                 GROUP BY clo.id, clo.code
                 ORDER BY clo.code ASC',
                [
                    'deliveryId' => $deliveryId->toBinary(),
                    'released' => ResultReleaseStatus::Released->value,
                    'completed' => ScoringRunStatus::Completed->value,
                    'cancelled' => AssessmentAttemptStatus::Cancelled->value,
                ],
            );

            $views = [];
            foreach ($rows as $row) {
                $awarded = null;
                $maximum = null;
                $percentage = null;
                $band = null;
                if (!$suppression->isSuppressed()) {
                    $awarded = $this->metricsPolicy->normalizePoints((string) $row['awarded_sum']);
                    $maximum = $this->metricsPolicy->normalizePoints((string) $row['maximum_sum']);
                    $percentage = $this->metricsPolicy->percentageFromPoints($awarded, $maximum);
                    if (null === $percentage) {
                        $itemSuppression = AnalyticsSuppression::of(AnalyticsSuppressionReason::ZeroDenominator);
                        $views[] = new LearningOutcomeAnalyticsView(
                            Uuid::fromBinary((string) $row['learning_outcome_id']),
                            (string) $row['code'],
                            (int) $row['aligned_item_score_count'],
                            $itemSuppression,
                            null,
                            null,
                            null,
                            null,
                        );
                        continue;
                    }
                    $band = $this->metricsPolicy->bandForPercentage($percentage);
                }

                $views[] = new LearningOutcomeAnalyticsView(
                    Uuid::fromBinary((string) $row['learning_outcome_id']),
                    (string) $row['code'],
                    (int) $row['aligned_item_score_count'],
                    $suppression,
                    $awarded,
                    $maximum,
                    $percentage,
                    $band,
                );
            }

            $this->auditBestEffort(
                SecurityAuditAction::LearningOutcomeAnalyticsViewed,
                $actor,
                [
                    'analytics_type' => AnalyticsType::LearningOutcome->value,
                    'delivery_id' => $deliveryId->toRfc4122(),
                    'institution_id' => $scope['institution_id']->toRfc4122(),
                    'cohort_size' => $releasedCount,
                    'suppressed' => $suppression->isSuppressed(),
                ],
            );

            return $views;
        });
    }

    private function cohortSuppression(int $releasedCount): AnalyticsSuppression
    {
        if ($this->privacyPolicy->shouldSuppressCohort($releasedCount)) {
            return AnalyticsSuppression::of(AnalyticsSuppressionReason::CohortBelowThreshold);
        }

        return AnalyticsSuppression::none();
    }

    /**
     * @return array{eligible: int, started: int, completed: int}
     */
    private function fetchParticipationCounts(Uuid $deliveryId): array
    {
        $eligible = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM assessment_delivery_recipients
             WHERE delivery_id = :deliveryId AND status = :eligible',
            [
                'deliveryId' => $deliveryId->toBinary(),
                'eligible' => AssessmentDeliveryRecipientStatus::Eligible->value,
            ],
        );

        $started = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM assessment_attempts
             WHERE delivery_id = :deliveryId AND status <> :cancelled',
            [
                'deliveryId' => $deliveryId->toBinary(),
                'cancelled' => AssessmentAttemptStatus::Cancelled->value,
            ],
        );

        $completed = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM assessment_attempts
             WHERE delivery_id = :deliveryId
               AND (status = :submitted OR status = :expired)',
            [
                'deliveryId' => $deliveryId->toBinary(),
                'submitted' => AssessmentAttemptStatus::Submitted->value,
                'expired' => AssessmentAttemptStatus::Expired->value,
            ],
        );

        return [
            'eligible' => $eligible,
            'started' => $started,
            'completed' => $completed,
        ];
    }

    /**
     * @return list<numeric-string>
     */
    private function fetchReleasedPercentages(Uuid $deliveryId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT sr.percentage
             FROM assessment_result_active_release_guards g
             INNER JOIN assessment_result_releases r
                 ON r.id = g.release_id AND r.status = :released
             INNER JOIN assessment_scoring_runs sr
                 ON sr.id = r.scoring_run_id AND sr.status = :completed
             INNER JOIN assessment_attempts a
                 ON a.id = r.attempt_id AND a.delivery_id = :deliveryId
                AND a.status <> :cancelled
             ORDER BY sr.percentage ASC',
            [
                'deliveryId' => $deliveryId->toBinary(),
                'released' => ResultReleaseStatus::Released->value,
                'completed' => ScoringRunStatus::Completed->value,
                'cancelled' => AssessmentAttemptStatus::Cancelled->value,
            ],
        );

        $out = [];
        foreach ($rows as $pct) {
            $out[] = $this->metricsPolicy->normalizePercentage((string) $pct);
        }

        return $out;
    }

    private function countReleasedResults(Uuid $deliveryId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM assessment_result_active_release_guards g
             INNER JOIN assessment_result_releases r
                 ON r.id = g.release_id AND r.status = :released
             INNER JOIN assessment_scoring_runs sr
                 ON sr.id = r.scoring_run_id AND sr.status = :completed
             INNER JOIN assessment_attempts a
                 ON a.id = r.attempt_id AND a.delivery_id = :deliveryId
                AND a.status <> :cancelled',
            [
                'deliveryId' => $deliveryId->toBinary(),
                'released' => ResultReleaseStatus::Released->value,
                'completed' => ScoringRunStatus::Completed->value,
                'cancelled' => AssessmentAttemptStatus::Cancelled->value,
            ],
        );
    }

    /**
     * @return array{
     *     release_id: string,
     *     release_number: int|string,
     *     released_at: string,
     *     status: string,
     *     scoring_run_id: string,
     *     final_points: string,
     *     maximum_points: string,
     *     percentage: string
     * }|null
     */
    private function fetchActiveReleasedRun(Uuid $attemptId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT r.id AS release_id, r.release_number, r.released_at, r.status,
                    sr.id AS scoring_run_id, sr.final_points, sr.maximum_points, sr.percentage
             FROM assessment_result_active_release_guards g
             INNER JOIN assessment_result_releases r ON r.id = g.release_id
             INNER JOIN assessment_scoring_runs sr
                 ON sr.id = r.scoring_run_id AND sr.status = :completed
             WHERE g.attempt_id = :attemptId
             LIMIT 1',
            [
                'attemptId' => $attemptId->toBinary(),
                'completed' => ScoringRunStatus::Completed->value,
            ],
        );
        if (false === $row) {
            return null;
        }

        return [
            'release_id' => Uuid::fromBinary((string) $row['release_id'])->toRfc4122(),
            'release_number' => $row['release_number'],
            'released_at' => (string) $row['released_at'],
            'status' => (string) $row['status'],
            'scoring_run_id' => Uuid::fromBinary((string) $row['scoring_run_id'])->toRfc4122(),
            'final_points' => (string) $row['final_points'],
            'maximum_points' => (string) $row['maximum_points'],
            'percentage' => (string) $row['percentage'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function mapOutcomeBreakdown(array $rows): OutcomeCountBreakdown
    {
        $correct = 0;
        $incorrect = 0;
        $unanswered = 0;
        $manualPending = 0;
        $manuallyGraded = 0;
        $invalid = 0;
        foreach ($rows as $row) {
            $cnt = (int) $row['cnt'];
            match ((string) $row['outcome']) {
                ItemScoreOutcome::Correct->value => $correct = $cnt,
                ItemScoreOutcome::Incorrect->value => $incorrect = $cnt,
                ItemScoreOutcome::Unanswered->value => $unanswered = $cnt,
                ItemScoreOutcome::ManualPending->value => $manualPending = $cnt,
                ItemScoreOutcome::ManuallyGraded->value => $manuallyGraded = $cnt,
                ItemScoreOutcome::Invalid->value => $invalid = $cnt,
                default => null,
            };
        }

        return new OutcomeCountBreakdown(
            $correct,
            $incorrect,
            $unanswered,
            $manualPending,
            $manuallyGraded,
            $invalid,
        );
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $metadata
     */
    private function auditBestEffort(SecurityAuditAction $action, User $actor, array $metadata): void
    {
        try {
            $this->auditRecorder->record(new SecurityAuditContext(
                action: $action,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $actor,
                metadata: $metadata,
                captureRequestHashes: false,
            ), false);
        } catch (\Throwable $e) {
            $this->logger->warning('Assessment analytics audit recording failed.', [
                'action' => $action->value,
                'exception' => $e::class,
            ]);
        }
    }
}
