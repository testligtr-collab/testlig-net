<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentResultReviewPolicy;
use App\Enum\ResultReviewPolicyStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentResultReviewPolicy>
 */
class AssessmentResultReviewPolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentResultReviewPolicy::class);
    }

    public function findOneById(Uuid $id): ?AssessmentResultReviewPolicy
    {
        return $this->find($id);
    }

    public function findFresh(Uuid $id): ?AssessmentResultReviewPolicy
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentResultReviewPolicy ? $result : null;
    }

    public function findMaxVersion(Uuid $deliveryId): int
    {
        $max = $this->createQueryBuilder('p')
            ->select('MAX(p.version)')
            ->andWhere('p.delivery = :deliveryId')
            ->setParameter('deliveryId', $deliveryId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max;
    }

    public function findActiveForDelivery(Uuid $deliveryId): ?AssessmentResultReviewPolicy
    {
        /** @var AssessmentResultReviewPolicy|null $policy */
        $policy = $this->createQueryBuilder('p')
            ->andWhere('p.delivery = :deliveryId')
            ->andWhere('p.status = :status')
            ->setParameter('deliveryId', $deliveryId, 'uuid')
            ->setParameter('status', ResultReviewPolicyStatus::Active)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $policy;
    }

    public function save(AssessmentResultReviewPolicy $policy, bool $flush = true): void
    {
        $this->getEntityManager()->persist($policy);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
