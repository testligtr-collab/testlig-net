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
use App\Entity\AssessmentScoringRun;
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
        /** @var list<AssessmentAttempt> $attempts */
        $attempts = $this->ordered($assessment, $search)
            ->setFirstResult(AdminPagination::offset($page, self::PAGE_SIZE))
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();
        $loaded = [];
        foreach ($attempts as $row) {
            $attempt = $this->attemptFrom($row);
            if ($attempt instanceof AssessmentAttempt) {
                $loaded[] = $attempt;
            }
        }
        $runs = $this->latestRuns($loaded);

        $items = [];
        $rank = AdminPagination::offset($page, self::PAGE_SIZE) + 1;
        foreach ($loaded as $attempt) {
            $items[] = $this->row($attempt, $runs[$attempt->getId()->toRfc4122()] ?? null, $rank);
            ++$rank;
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
        /** @var list<AssessmentAttempt> $attempts */
        $attempts = $this->ordered($assessment, $search)
            ->setFirstResult($rank - 1)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();
        $attempt = $this->attemptFrom($attempts[0] ?? null);
        if (!$attempt instanceof AssessmentAttempt
            || !$attempt->getAssessment()->getId()->equals($assessment->getId())
        ) {
            return null;
        }
        $student = $attempt->getUser();
        $revision = $attempt->getAssessmentRevision();

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

    private function attemptFrom(mixed $row): ?AssessmentAttempt
    {
        if ($row instanceof AssessmentAttempt) {
            return $row;
        }
        if (!\is_array($row)) {
            return null;
        }
        foreach ($row as $part) {
            if ($part instanceof AssessmentAttempt) {
                return $part;
            }
        }

        return null;
    }

    private function ordered(Assessment $assessment, ?string $search): QueryBuilder
    {
        return $this->base($assessment, $search)
            ->select('a', 'u', 'rev')
            ->addSelect('COALESCE(a.submittedAt, a.expiredAt, a.startedAt) AS HIDDEN sortAt')
            ->orderBy('sortAt', 'DESC')
            ->addOrderBy('a.id', 'DESC');
    }

    /**
     * @param list<AssessmentAttempt> $attempts
     *
     * @return array<string, AssessmentScoringRun>
     */
    private function latestRuns(array $attempts): array
    {
        if ([] === $attempts) {
            return [];
        }
        $runs = $this->entityManager->createQueryBuilder()
            ->select('run', 'attempt')
            ->from(AssessmentScoringRun::class, 'run')
            ->innerJoin('run.attempt', 'attempt')
            ->andWhere('run.attempt IN (:attempts)')
            ->andWhere('run.status = :completed')
            ->setParameter('attempts', $attempts)
            ->setParameter('completed', ScoringRunStatus::Completed)
            ->orderBy('run.runNumber', 'DESC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($runs as $run) {
            if (!$run instanceof AssessmentScoringRun) {
                continue;
            }
            $key = $run->getAttempt()->getId()->toRfc4122();
            if (!isset($latest[$key])) {
                $latest[$key] = $run;
            }
        }

        return $latest;
    }

    private function row(AssessmentAttempt $attempt, ?AssessmentScoringRun $run, int $rank): AssessmentResultRowView
    {
        $scored = $run instanceof AssessmentScoringRun;

        return new AssessmentResultRowView(
            $rank,
            trim($attempt->getUser()->getFirstName().' '.$attempt->getUser()->getLastName()),
            $attempt->getAssessmentRevision()->getTitle(),
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
