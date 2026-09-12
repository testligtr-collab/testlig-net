<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningContentRevisionPrimaryAlignmentGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LearningContentRevisionPrimaryAlignmentGuard>
 */
class LearningContentRevisionPrimaryAlignmentGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningContentRevisionPrimaryAlignmentGuard::class);
    }

    public function save(LearningContentRevisionPrimaryAlignmentGuard $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(LearningContentRevisionPrimaryAlignmentGuard $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
