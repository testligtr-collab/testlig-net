<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningContentRevision;
use App\Entity\LearningContentRevisionAsset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LearningContentRevisionAsset>
 */
class LearningContentRevisionAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningContentRevisionAsset::class);
    }

    public function findOneById(Uuid $id): ?LearningContentRevisionAsset
    {
        return $this->find($id);
    }

    /**
     * @return list<LearningContentRevisionAsset>
     */
    public function findByRevision(LearningContentRevision $revision): array
    {
        /** @var list<LearningContentRevisionAsset> $rows */
        $rows = $this->findBy(['revision' => $revision], ['position' => 'ASC']);

        return $rows;
    }

    public function save(LearningContentRevisionAsset $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(LearningContentRevisionAsset $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
