<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\StudentTestHistoryCard;
use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentPlatformPractice;
use App\Entity\AssessmentScoringRun;
use App\Entity\User;
use App\Enum\AssessmentAttemptStatus;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\AssessmentScope;
use App\Enum\ScoringRunStatus;
use App\Presentation\ResultPresentation;
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
        private readonly InvitationCodeDigestHasher $hasher,
        private readonly ResultPresentation $presentation,
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
            $delivery = $attempt->getDelivery();
            $institutionTest = AssessmentDeliveryAudienceType::Classroom === $delivery->getAudienceType()
                && AssessmentScope::Institution === $assessment->getScope();
            $cards[] = new StudentTestHistoryCard(
                $institutionTest ? $this->hasher->studentAssignmentCode($delivery->getId()) : $assessment->getCode(),
                $attempt->getAssessmentRevision()->getTitle(),
                $this->presentation->subjectName($assessment->getSubject()),
                $inProgress ? 'resume' : 'done',
                $inProgress ? 'Devam ediyor' : 'Tamamlandı',
                $this->stamp($attempt->getSubmittedAt() ?? $attempt->getExpiredAt()),
                $scored ? $run->getCorrectCount() : null,
                $scored ? $run->getIncorrectCount() : null,
                $scored ? $run->getUnansweredCount() : null,
                $scored ? $this->presentation->points($run->getFinalPoints()) : null,
                $scored ? $this->presentation->points($run->getMaximumPoints()) : null,
                $scored ? $this->presentation->percent($run->getPercentage()) : null,
                $institutionTest ? $delivery->getInstitution()->getName() : null,
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
            ->innerJoin('a.delivery', 'delivery')
            ->leftJoin(
                AssessmentPlatformPractice::class,
                'practice',
                'WITH',
                'practice.delivery = delivery AND practice.user = a.user',
            )
            ->andWhere('a.user = :student')
            ->andWhere('practice.id IS NOT NULL OR (delivery.audienceType = :classroomAudience AND ass.scope = :institutionScope)')
            ->setParameter('student', $student->getId(), 'uuid')
            ->setParameter('classroomAudience', AssessmentDeliveryAudienceType::Classroom)
            ->setParameter('institutionScope', AssessmentScope::Institution);
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

        return $this->presentation->instant($at);
    }
}
