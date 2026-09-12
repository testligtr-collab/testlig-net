<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccessPackageCatalogGrant;
use App\Entity\AccessPackageVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AccessPackageCatalogGrant>
 */
class AccessPackageCatalogGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessPackageCatalogGrant::class);
    }

    public function findOneById(Uuid $id): ?AccessPackageCatalogGrant
    {
        $entity = $this->find($id);

        return $entity instanceof AccessPackageCatalogGrant ? $entity : null;
    }

    public function save(AccessPackageCatalogGrant $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AccessPackageCatalogGrant $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<AccessPackageCatalogGrant> */
    public function findByVersion(AccessPackageVersion $version): array
    {
        /** @var list<AccessPackageCatalogGrant> $rows */
        $rows = $this->findBy(['version' => $version], ['createdAt' => 'ASC']);

        return $rows;
    }
}
