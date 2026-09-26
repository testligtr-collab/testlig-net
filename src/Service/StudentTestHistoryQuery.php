<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\StudentTestHistoryCard;
use App\Entity\Assessment;
use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentPlatformPractice;
use App\Entity\AssessmentRevision;
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
        /** @var list<array<int, mixed>> $rows */
        $rows = $this->base($student)
            ->select('a', 'rev', 'ass', 'subj', 'run')
            ->addSelect('COALESCE(a.submittedAt, a.expiredAt, a.startedAt) AS HIDDEN sortAt')
            ->orderBy('sortAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(self::LIMIT)
            ->getQuery()
            ->getResult();

        $cards = [];
        foreach ($rows as $row) {
            $card = $this->card($row);
            if ($card instanceof StudentTestHistoryCard) {
                $cards[] = $card;
            }
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
            ->leftJoin(
                AssessmentScoringRun::class,
                'run',
                'WITH',
                'run.attempt = a AND run.status = :completed AND run.runNumber = (
                    SELECT MAX(runMax.runNumber) FROM '.AssessmentScoringRun::class.' runMax
                    WHERE runMax.attempt = a AND runMax.status = :completed
                )',
            )
            ->andWhere('a.user = :student')
            ->setParameter('student', $student->getId(), 'uuid')
            ->setParameter('completed', ScoringRunStatus::Completed);
    }

    /**
     * @param array<int, mixed> $row
     */
    private function card(array $row): ?StudentTestHistoryCard
    {
        $attempt = null;
        $revision = null;
        $assessment = null;
        $run = null;
        foreach ($row as $part) {
            if ($part instanceof AssessmentAttempt) {
                $attempt = $part;
            } elseif ($part instanceof AssessmentRevision) {
                $revision = $part;
            } elseif ($part instanceof Assessment) {
                $assessment = $part;
            } elseif ($part instanceof AssessmentScoringRun) {
                $run = $part;
            }
        }
        if (!$attempt instanceof AssessmentAttempt
            || !$revision instanceof AssessmentRevision
            || !$assessment instanceof Assessment
        ) {
            return null;
        }
        $scored = $run instanceof AssessmentScoringRun;
        $inProgress = AssessmentAttemptStatus::InProgress === $attempt->getStatus();

        return new StudentTestHistoryCard(
            $assessment->getCode(),
            $revision->getTitle(),
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

    private function stamp(?\DateTimeImmutable $at): ?string
    {
        if (null === $at) {
            return null;
        }

        return UtcInstant::ensure($at)->format('d.m.Y H:i').' UTC';
    }
}
