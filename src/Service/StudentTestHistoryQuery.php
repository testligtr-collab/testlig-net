<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\StudentTestHistoryCard;
use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentPlatformPractice;
use App\Entity\AssessmentScoringRun;
use App\Entity\User;
use App\Enum\AssessmentAttemptStatus;
use App\Enum\ScoringRunStatus;
use App\Time\UtcInstant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Owned platform-practice history. Does not load other students.
 */
final class StudentTestHistoryQuery
{
    private const LIMIT = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<StudentTestHistoryCard>
     */
    public function listFor(User $student): array
    {
        /** @var list<AssessmentAttempt> $attempts */
        $attempts = $this->base($student)
            ->select('a', 'rev', 'ass', 'subj')
            ->addSelect('COALESCE(a.submittedAt, a.expiredAt, a.startedAt) AS HIDDEN sortAt')
            ->orderBy('sortAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(self::LIMIT)
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

        $cards = [];
        foreach ($loaded as $attempt) {
            $run = $runs[$attempt->getId()->toRfc4122()] ?? null;
            $scored = $run instanceof AssessmentScoringRun;
            $inProgress = AssessmentAttemptStatus::InProgress === $attempt->getStatus();
            $assessment = $attempt->getAssessment();
            $cards[] = new StudentTestHistoryCard(
                $assessment->getCode(),
                $attempt->getAssessmentRevision()->getTitle(),
                $assessment->getSubject()?->getName() ?? '',
                $inProgress ? 'resume' : 'done',
                $inProgress ? 'Devam ediyor' : 'Tamamlandı',
                $this->stamp($attempt->getSubmittedAt() ?? $attempt->getExpiredAt()),
                $scored ? $run->getCorrectCount() : null,
                $scored ? $run->getIncorrectCount() : null,
                $scored ? $run->getUnansweredCount() : null,
                $scored ? $run->getFinalPoints() : null,
                $scored ? $run->getMaximumPoints() : null,
                $scored ? $run->getPercentage() : null,
            );
        }

        return $cards;
    }

    private function base(User $student): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(AssessmentAttempt::class, 'a')
            ->innerJoin('a.assessmentRevision', 'rev')
            ->innerJoin('a.assessment', 'ass')
            ->leftJoin('ass.subject', 'subj')
            ->innerJoin(
                AssessmentPlatformPractice::class,
                'practice',
                'WITH',
                'practice.delivery = a.delivery AND practice.user = a.user',
            )
            ->andWhere('a.user = :student')
            ->setParameter('student', $student->getId(), 'uuid');
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
        $qb = $this->entityManager->createQueryBuilder()
            ->select('run', 'attempt')
            ->from(AssessmentScoringRun::class, 'run')
            ->innerJoin('run.attempt', 'attempt')
            ->andWhere('run.status = :completed')
            ->setParameter('completed', ScoringRunStatus::Completed)
            ->orderBy('run.runNumber', 'DESC');
        $clauses = [];
        foreach ($attempts as $index => $attempt) {
            $name = 'attemptId'.$index;
            $clauses[] = 'attempt.id = :'.$name;
            $qb->setParameter($name, $attempt->getId(), 'uuid');
        }
        $runs = $qb
            ->andWhere(implode(' OR ', $clauses))
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($runs as $row) {
            $run = $this->runFrom($row);
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

    private function runFrom(mixed $row): ?AssessmentScoringRun
    {
        if ($row instanceof AssessmentScoringRun) {
            return $row;
        }
        if (!\is_array($row)) {
            return null;
        }
        foreach ($row as $part) {
            if ($part instanceof AssessmentScoringRun) {
                return $part;
            }
        }

        return null;
    }

    private function stamp(?\DateTimeImmutable $at): ?string
    {
        if (null === $at) {
            return null;
        }

        return UtcInstant::ensure($at)->format('d.m.Y H:i').' UTC';
    }
}
