<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Institution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AcademicYear>
 */
class AcademicYearRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AcademicYear::class);
    }

    public function findOneById(Uuid $id): ?AcademicYear
    {
        return $this->find($id);
    }

    public function existsWithNormalizedName(Institution $institution, string $normalizedName): bool
    {
        return null !== $this->createQueryBuilder('y')
            ->select('1')
            ->andWhere('y.institution = :institution')
            ->andWhere('y.normalizedName = :normalizedName')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('normalizedName', $normalizedName)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Inclusive date-range overlap within the same institution.
     */
    public function hasOverlappingRange(
        Institution $institution,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ?Uuid $excludeId = null,
    ): bool {
        $qb = $this->createQueryBuilder('y')
            ->select('1')
            ->andWhere('y.institution = :institution')
            ->andWhere('y.startsAt <= :endsAt')
            ->andWhere('y.endsAt >= :startsAt')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('startsAt', $startsAt)
            ->setParameter('endsAt', $endsAt)
            ->setMaxResults(1);

        if (null !== $excludeId) {
            $qb->andWhere('y.id != :excludeId')
                ->setParameter('excludeId', $excludeId, 'uuid');
        }

        return null !== $qb->getQuery()->getOneOrNullResult();
    }

    public function save(AcademicYear $year, bool $flush = true): void
    {
        $this->getEntityManager()->persist($year);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
