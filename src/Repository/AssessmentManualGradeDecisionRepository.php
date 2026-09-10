<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentManualGradeDecision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentManualGradeDecision>
 */
class AssessmentManualGradeDecisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentManualGradeDecision::class);
    }

    public function findOneById(Uuid $id): ?AssessmentManualGradeDecision
    {
        return $this->find($id);
    }

    public function findMaxDecisionNumber(Uuid $attemptItemId): int
    {
        $max = $this->createQueryBuilder('d')
            ->select('MAX(d.decisionNumber)')
            ->andWhere('d.attemptItem = :itemId')
            ->setParameter('itemId', $attemptItemId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max;
    }

    /**
     * @return list<AssessmentManualGradeDecision>
     */
    public function findAllForAttemptItem(Uuid $attemptItemId): array
    {
        /** @var list<AssessmentManualGradeDecision> $decisions */
        $decisions = $this->createQueryBuilder('d')
            ->andWhere('d.attemptItem = :itemId')
            ->setParameter('itemId', $attemptItemId, 'uuid')
            ->orderBy('d.decisionNumber', 'ASC')
            ->getQuery()
            ->getResult();

        return $decisions;
    }

    public function save(AssessmentManualGradeDecision $decision, bool $flush = true): void
    {
        $this->getEntityManager()->persist($decision);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
