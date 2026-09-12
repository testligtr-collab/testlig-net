<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccessLicense;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AccessLicense>
 */
class AccessLicenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessLicense::class);
    }

    public function findOneById(Uuid $id): ?AccessLicense
    {
        $entity = $this->find($id);

        return $entity instanceof AccessLicense ? $entity : null;
    }

    public function save(AccessLicense $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AccessLicense $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<AccessLicense> */
    public function findActiveUserLicenses(Uuid $userId): array
    {
        /** @var list<AccessLicense> $rows */
        $rows = $this->createQueryBuilder('l')
            ->andWhere('IDENTITY(l.user) = :userId')
            ->andWhere('l.status = :status')
            ->setParameter('userId', $userId, 'uuid')
            ->setParameter('status', \App\Enum\AccessLicenseStatus::Active)
            ->orderBy('l.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** @return list<AccessLicense> */
    public function findActiveInstitutionLicenses(Uuid $institutionId): array
    {
        /** @var list<AccessLicense> $rows */
        $rows = $this->createQueryBuilder('l')
            ->andWhere('IDENTITY(l.institution) = :institutionId')
            ->andWhere('l.status = :status')
            ->setParameter('institutionId', $institutionId, 'uuid')
            ->setParameter('status', \App\Enum\AccessLicenseStatus::Active)
            ->orderBy('l.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
