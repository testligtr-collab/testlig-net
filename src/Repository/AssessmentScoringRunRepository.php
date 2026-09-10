<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentScoringRun;
use App\Enum\ScoringRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentScoringRun>
 */
class AssessmentScoringRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentScoringRun::class);
    }

    public function findOneById(Uuid $id): ?AssessmentScoringRun
    {
        return $this->find($id);
    }

    public function findMaxRunNumber(Uuid $attemptId): int
    {
        $max = $this->createQueryBuilder('r')
            ->select('MAX(r.runNumber)')
            ->andWhere('r.attempt = :attemptId')
            ->setParameter('attemptId', $attemptId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max;
    }

    public function findLatestForAttempt(Uuid $attemptId): ?AssessmentScoringRun
    {
        /** @var AssessmentScoringRun|null $run */
        $run = $this->createQueryBuilder('r')
            ->andWhere('r.attempt = :attemptId')
            ->setParameter('attemptId', $attemptId, 'uuid')
            ->orderBy('r.runNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $run;
    }

    public function findOpenForAttempt(Uuid $attemptId): ?AssessmentScoringRun
    {
        /** @var AssessmentScoringRun|null $run */
        $run = $this->createQueryBuilder('r')
            ->andWhere('r.attempt = :attemptId')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('attemptId', $attemptId, 'uuid')
            ->setParameter('statuses', [
                ScoringRunStatus::Processing,
                ScoringRunStatus::PendingManual,
            ])
            ->orderBy('r.runNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $run;
    }

    public function save(AssessmentScoringRun $run, bool $flush = true): void
    {
        $this->getEntityManager()->persist($run);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
