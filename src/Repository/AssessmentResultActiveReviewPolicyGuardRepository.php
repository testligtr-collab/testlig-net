<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentResultActiveReviewPolicyGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentResultActiveReviewPolicyGuard>
 */
class AssessmentResultActiveReviewPolicyGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentResultActiveReviewPolicyGuard::class);
    }

    public function findOneByDeliveryId(Uuid $deliveryId): ?AssessmentResultActiveReviewPolicyGuard
    {
        return $this->find($deliveryId);
    }

    public function save(AssessmentResultActiveReviewPolicyGuard $guard, bool $flush = true): void
    {
        $this->getEntityManager()->persist($guard);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
