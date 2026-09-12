<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccessPackageVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AccessPackageVersion>
 */
class AccessPackageVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessPackageVersion::class);
    }

    public function findOneById(Uuid $id): ?AccessPackageVersion
    {
        $entity = $this->find($id);

        return $entity instanceof AccessPackageVersion ? $entity : null;
    }

    public function save(AccessPackageVersion $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AccessPackageVersion $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findActiveForPackage(Uuid $packageId): ?AccessPackageVersion
    {
        $entity = $this->createQueryBuilder('v')
            ->andWhere('IDENTITY(v.package) = :packageId')
            ->andWhere('v.status = :status')
            ->setParameter('packageId', $packageId, 'uuid')
            ->setParameter('status', \App\Enum\AccessPackageVersionStatus::Active)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entity instanceof AccessPackageVersion ? $entity : null;
    }

    public function nextVersionNumber(Uuid $packageId): int
    {
        $max = $this->createQueryBuilder('v')
            ->select('MAX(v.versionNumber)')
            ->andWhere('IDENTITY(v.package) = :packageId')
            ->setParameter('packageId', $packageId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 1 : ((int) $max) + 1;
    }
}
