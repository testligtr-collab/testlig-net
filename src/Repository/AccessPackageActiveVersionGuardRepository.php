<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccessPackageActiveVersionGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AccessPackageActiveVersionGuard>
 */
class AccessPackageActiveVersionGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessPackageActiveVersionGuard::class);
    }

    public function findOneById(Uuid $id): ?AccessPackageActiveVersionGuard
    {
        $entity = $this->find($id);

        return $entity instanceof AccessPackageActiveVersionGuard ? $entity : null;
    }

    public function save(AccessPackageActiveVersionGuard $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AccessPackageActiveVersionGuard $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
