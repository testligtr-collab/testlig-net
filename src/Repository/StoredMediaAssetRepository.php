<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StoredMediaAsset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<StoredMediaAsset>
 */
class StoredMediaAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoredMediaAsset::class);
    }

    public function findOneById(Uuid $id): ?StoredMediaAsset
    {
        return $this->find($id);
    }

    public function save(StoredMediaAsset $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
