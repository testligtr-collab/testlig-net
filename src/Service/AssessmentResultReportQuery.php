<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\AdminPagedResult;
use App\Dto\AssessmentResultDetailView;
use App\Dto\AssessmentResultQuestionView;
use App\Dto\AssessmentResultRowView;
use App\Dto\AssessmentResultSummary;
use App\Entity\Assessment;
use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentRevision;
use App\Entity\AssessmentScoringRun;
use App\Entity\User;
use App\Enum\AssessmentAttemptStatus;
use App\Enum\ScoringRunStatus;
use App\Service\Admin\AdminLikeEscape;
use App\Service\Admin\AdminPagination;
use App\Time\UtcInstant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Read-only result list for one assessment the caller is already allowed to report on.
 */
final class AssessmentResultReportQuery
{
    private const PAGE_SIZE = 25;

    private const SUMMARY_BATCH = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StudentPracticeResultReader $results,
    ) {
    }

    /**
     * @return AdminPagedResult<AssessmentResultRowView>
     */
    public function page(Assessment $assessment, int $page, ?string $search): AdminPagedResult
    {
        $page = AdminPagination::normalizePage($page);
        $total = $this->countAttempts($assessment, $search);
        /** @var list<array<int, mixed>> $rows */
        $rows = $this->base($assessment, $search)
            ->select('a', 'u', 'rev', 'run')
            ->addSelect('COALESCE(a.submittedAt, a.expiredAt, a.startedAt) AS HIDDEN sortAt')
            ->orderBy('sortAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult(AdminPagination::offset($page, self::PAGE_SIZE))
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        $items = [];
        $rank = AdminPagination::offset($page, self::PAGE_SIZE) + 1;
        foreach ($rows as $row) {
            $view = $this->row($row, $rank);
            if ($view instanceof AssessmentResultRowView) {
                $items[] = $view;
                ++$rank;
            }
        }

        return new AdminPagedResult($items, $page, self::PAGE_SIZE, $total);
    }

    public function summarize(Assessment $assessment, ?string $search): AssessmentResultSummary
    {
        $completed = $this->countAttempts($assessment, $search, [
            AssessmentAttemptStatus::Submitted,
            AssessmentAttemptStatus::Expired,
        ]);
        $inProgress = $this->countAttempts($assessment, $search, [AssessmentAttemptStatus::InProgress]);
        $participants = $this->countParticipants($assessment, $search);

        $sum = '0.0000';
        $count = 0;
        $highest = null;
        $lowest = null;
        $offset = 0;
        do {
            /** @var list<mixed> $batch */
            $batch = $this->base($assessment, $search)
                ->select('run.percentage')
                ->andWhere('a.status IN (:done)')
                ->andWhere('run.id IS NOT NULL')
                ->setParameter('done', [
                    AssessmentAttemptStatus::Submitted,
                    AssessmentAttemptStatus::Expired,
                ])
                ->orderBy('a.id', 'ASC')
                ->setFirstResult($offset)
                ->setMaxResults(self::SUMMARY_BATCH)
                ->getQuery()
                ->getSingleColumnResult();
            foreach ($batch as $percentage) {
                if (!\is_string($percentage)) {
                    continue;
                }
                $value = $this->percentage($percentage);
                if (null === $value) {
                    continue;
                }
                $sum = bcadd($sum, $value, 4);
                ++$count;
                if (null === $highest || 1 === bccomp($value, $highest, 4)) {
                    $highest = $value;
                }
                if (null === $lowest || -1 === bccomp($value, $lowest, 4)) {
                    $lowest = $value;
                }
            }
            $offset += self::SUMMARY_BATCH;
        } while (self::SUMMARY_BATCH === \count($batch));

        return new AssessmentResultSummary(
            $participants,
            $count > 0 ? bcdiv($sum, (string) $count, 4) : null,
            $highest,
            $lowest,
            $completed,
            $inProgress,
        );
    }

    public function detail(Assessment $assessment, int $rank, ?string $search): ?AssessmentResultDetailView
    {
        if ($rank < 1) {
            return null;
        }
        /** @var list<array<int, mixed>> $rows */
        $rows = $this->base($assessment, $search)
            ->select('a', 'u', 'rev', 'run')
            ->addSelect('COALESCE(a.submittedAt, a.expiredAt, a.startedAt) AS HIDDEN sortAt')
            ->orderBy('sortAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult($rank - 1)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();
        $row = $rows[0] ?? null;
        if (!\is_array($row)) {
            return null;
        }
        $attempt = null;
        $student = null;
        $revision = null;
        foreach ($row as $part) {
            if ($part instanceof AssessmentAttempt) {
                $attempt = $part;
            } elseif ($part instanceof User) {
                $student = $part;
            } elseif ($part instanceof AssessmentRevision) {
                $revision = $part;
            }
        }
        if (!$attempt instanceof AssessmentAttempt
            || !$student instanceof User
            || !$revision instanceof AssessmentRevision
            || !$attempt->getAssessment()->getId()->equals($assessment->getId())
            || !$attempt->getUser()->getId()->equals($student->getId())
        ) {
            return null;
        }

        $scored = $this->results->readScored($attempt);
        $questions = [];
        if (null !== $scored) {
            foreach ($scored['questions'] as $question) {
                $questions[] = new AssessmentResultQuestionView(
                    $question['position'],
                    $question['stem'],
                    $question['student_answer'],
                    $question['correct_answer'],
                    $question['label'],
                    $question['explanation'],
                );
            }
        }

        return new AssessmentResultDetailView(
            trim($student->getFirstName().' '.$student->getLastName()),
            $revision->getTitle(),
            $this->statusLabel($attempt->getStatus()),
            $this->stamp($attempt->getStartedAt()) ?? '',
            $this->stamp($attempt->getSubmittedAt() ?? $attempt->getExpiredAt()),
            null !== $scored,
            null !== $scored ? $scored['correct'] : null,
            null !== $scored ? $scored['incorrect'] : null,
            null !== $scored ? $scored['unanswered'] : null,
            null !== $scored ? $scored['earned'] : null,
            null !== $scored ? $scored['total'] : null,
            null !== $scored ? $scored['percentage'] : null,
            $questions,
        );
    }

    /**
     * @param list<AssessmentAttemptStatus>|null $statuses
     */
    private function countAttempts(Assessment $assessment, ?string $search, ?array $statuses = null): int
    {
        $qb = $this->base($assessment, $search)->select('COUNT(a.id)');
        if (null !== $statuses) {
            $qb->andWhere('a.status IN (:statuses)')->setParameter('statuses', $statuses);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function countParticipants(Assessment $assessment, ?string $search): int
    {
        return (int) $this->base($assessment, $search)
            ->select('COUNT(DISTINCT u.id)')
            ->andWhere('a.status IN (:done)')
            ->setParameter('done', [
                AssessmentAttemptStatus::Submitted,
                AssessmentAttemptStatus::Expired,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function base(Assessment $assessment, ?string $search): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(AssessmentAttempt::class, 'a')
            ->innerJoin('a.user', 'u')
            ->innerJoin('a.assessmentRevision', 'rev')
            ->leftJoin(
                AssessmentScoringRun::class,
                'run',
                'WITH',
                'run.attempt = a AND run.status = :completed AND run.runNumber = (
                    SELECT MAX(runMax.runNumber) FROM '.AssessmentScoringRun::class.' runMax
                    WHERE runMax.attempt = a AND runMax.status = :completed
                )',
            )
            ->andWhere('a.assessment = :assessment')
            ->setParameter('assessment', $assessment->getId(), 'uuid')
            ->setParameter('completed', ScoringRunStatus::Completed);
        $term = AdminLikeEscape::normalizeSearch($search);
        if (null !== $term) {
            $qb->andWhere('(u.firstName LIKE :q ESCAPE \'!\' OR u.lastName LIKE :q ESCAPE \'!\' OR rev.title LIKE :q ESCAPE \'!\')')
                ->setParameter('q', '%'.AdminLikeEscape::escape($term).'%');
        }

        return $qb;
    }

    /**
     * @param array<int, mixed> $row
     */
    private function row(array $row, int $rank): ?AssessmentResultRowView
    {
        $attempt = null;
        $student = null;
        $revision = null;
        $run = null;
        foreach ($row as $part) {
            if ($part instanceof AssessmentAttempt) {
                $attempt = $part;
            } elseif ($part instanceof User) {
                $student = $part;
            } elseif ($part instanceof AssessmentRevision) {
                $revision = $part;
            } elseif ($part instanceof AssessmentScoringRun) {
                $run = $part;
            }
        }
        if (!$attempt instanceof AssessmentAttempt
            || !$student instanceof User
            || !$revision instanceof AssessmentRevision
        ) {
            return null;
        }
        $scored = $run instanceof AssessmentScoringRun;

        return new AssessmentResultRowView(
            $rank,
            trim($student->getFirstName().' '.$student->getLastName()),
            $revision->getTitle(),
            $this->statusLabel($attempt->getStatus()),
            $this->stamp($attempt->getStartedAt()) ?? '',
            $this->stamp($attempt->getSubmittedAt() ?? $attempt->getExpiredAt()),
            $scored,
            $scored ? $run->getCorrectCount() : null,
            $scored ? $run->getIncorrectCount() : null,
            $scored ? $run->getUnansweredCount() : null,
            $scored ? $run->getFinalPoints() : null,
            $scored ? $run->getMaximumPoints() : null,
            $scored ? $run->getPercentage() : null,
        );
    }

    private function statusLabel(AssessmentAttemptStatus $status): string
    {
        return match ($status) {
            AssessmentAttemptStatus::InProgress => 'Devam ediyor',
            AssessmentAttemptStatus::Submitted => 'Tamamlandı',
            AssessmentAttemptStatus::Expired => 'Süre doldu',
            AssessmentAttemptStatus::Cancelled => 'İptal',
        };
    }

    /**
     * @return numeric-string|null
     */
    private function percentage(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ('' === $trimmed || !is_numeric($trimmed) || 1 !== preg_match('/^\d+(\.\d+)?$/', $trimmed)) {
            return null;
        }
        if (!\extension_loaded('bcmath')) {
            return null;
        }

        return bcadd($trimmed, '0', 4);
    }

    private function stamp(?\DateTimeImmutable $at): ?string
    {
        if (null === $at) {
            return null;
        }

        return UtcInstant::ensure($at)->format('d.m.Y H:i').' UTC';
    }
}
