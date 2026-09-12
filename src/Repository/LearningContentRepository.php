<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningContent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LearningContent>
 */
class LearningContentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningContent::class);
    }

    public function findOneById(Uuid $id): ?LearningContent
    {
        return $this->find($id);
    }

    public function save(LearningContent $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
