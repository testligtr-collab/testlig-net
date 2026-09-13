<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningContentAccessPolicy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LearningContentAccessPolicy>
 */
class LearningContentAccessPolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningContentAccessPolicy::class);
    }

    public function findOneById(Uuid $id): ?LearningContentAccessPolicy
    {
        $entity = $this->find($id);

        return $entity instanceof LearningContentAccessPolicy ? $entity : null;
    }

    public function save(LearningContentAccessPolicy $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(LearningContentAccessPolicy $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findForContent(Uuid $contentId): ?LearningContentAccessPolicy
    {
        $entity = $this->find($contentId);

        return $entity instanceof LearningContentAccessPolicy ? $entity : null;
    }
}
