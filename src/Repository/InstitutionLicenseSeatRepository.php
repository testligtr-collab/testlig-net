<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InstitutionLicenseSeat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<InstitutionLicenseSeat>
 */
class InstitutionLicenseSeatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstitutionLicenseSeat::class);
    }

    public function findOneById(Uuid $id): ?InstitutionLicenseSeat
    {
        $entity = $this->find($id);

        return $entity instanceof InstitutionLicenseSeat ? $entity : null;
    }

    public function save(InstitutionLicenseSeat $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(InstitutionLicenseSeat $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function countActiveForLicense(Uuid $licenseId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('IDENTITY(s.license) = :licenseId')
            ->andWhere('s.status = :status')
            ->setParameter('licenseId', $licenseId, 'uuid')
            ->setParameter('status', \App\Enum\InstitutionLicenseSeatStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findActiveForLicenseAndMembership(
        Uuid $licenseId,
        Uuid $membershipId,
    ): ?InstitutionLicenseSeat {
        $entity = $this->createQueryBuilder('s')
            ->andWhere('IDENTITY(s.license) = :licenseId')
            ->andWhere('IDENTITY(s.membership) = :membershipId')
            ->andWhere('s.status = :status')
            ->setParameter('licenseId', $licenseId, 'uuid')
            ->setParameter('membershipId', $membershipId, 'uuid')
            ->setParameter('status', \App\Enum\InstitutionLicenseSeatStatus::Active)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entity instanceof InstitutionLicenseSeat ? $entity : null;
    }

    /** @return list<InstitutionLicenseSeat> */
    public function findActiveForUser(Uuid $userId): array
    {
        /** @var list<InstitutionLicenseSeat> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('IDENTITY(s.user) = :userId')
            ->andWhere('s.status = :status')
            ->setParameter('userId', $userId, 'uuid')
            ->setParameter('status', \App\Enum\InstitutionLicenseSeatStatus::Active)
            ->orderBy('s.assignedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
