<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentItemScore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentItemScore>
 */
class AssessmentItemScoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentItemScore::class);
    }

    public function findOneById(Uuid $id): ?AssessmentItemScore
    {
        return $this->find($id);
    }

    /**
     * @return list<AssessmentItemScore>
     */
    public function findAllForRun(Uuid $scoringRunId): array
    {
        /** @var list<AssessmentItemScore> $scores */
        $scores = $this->createQueryBuilder('s')
            ->andWhere('s.scoringRun = :runId')
            ->setParameter('runId', $scoringRunId, 'uuid')
            ->getQuery()
            ->getResult();

        return $scores;
    }

    public function findForRunAndItem(Uuid $scoringRunId, Uuid $attemptItemId): ?AssessmentItemScore
    {
        /** @var AssessmentItemScore|null $score */
        $score = $this->createQueryBuilder('s')
            ->andWhere('s.scoringRun = :runId')
            ->andWhere('s.attemptItem = :itemId')
            ->setParameter('runId', $scoringRunId, 'uuid')
            ->setParameter('itemId', $attemptItemId, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $score;
    }

    public function save(AssessmentItemScore $score, bool $flush = true): void
    {
        $this->getEntityManager()->persist($score);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
