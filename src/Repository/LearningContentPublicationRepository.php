<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningContentPublication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LearningContentPublication>
 */
class LearningContentPublicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningContentPublication::class);
    }

    public function findOneById(Uuid $id): ?LearningContentPublication
    {
        return $this->find($id);
    }

    public function save(LearningContentPublication $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
