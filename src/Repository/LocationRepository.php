<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Location;
use App\Enum\LocationLevel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Location>
 */
final class LocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Location::class);
    }

    public function hasActiveSchoolChain(): bool
    {
        $id = $this->chainQuery()
            ->select('school.id')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $id;
    }

    public function findActiveChain(Uuid $schoolId, Uuid $districtId, Uuid $provinceId): ?Location
    {
        $result = $this->chainQuery()
            ->andWhere('school.id = :schoolId')
            ->andWhere('district.id = :districtId')
            ->andWhere('province.id = :provinceId')
            ->setParameter('schoolId', $schoolId, 'uuid')
            ->setParameter('districtId', $districtId, 'uuid')
            ->setParameter('provinceId', $provinceId, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Location ? $result : null;
    }

    /**
     * Active provinces, or the active children of one active province or district.
     *
     * @return list<Location>
     */
    public function findActiveChildren(?Uuid $parentId): array
    {
        if (null === $parentId) {
            /** @var list<Location> $rows */
            $rows = $this->createQueryBuilder('location')
                ->andWhere('location.parent IS NULL')
                ->andWhere('location.level = :level')
                ->andWhere('location.active = true')
                ->setParameter('level', LocationLevel::Province)
                ->orderBy('location.name', 'ASC')
                ->addOrderBy('location.id', 'ASC')
                ->getQuery()
                ->getResult();

            return $rows;
        }

        /** @var list<Location> $rows */
        $rows = $this->createQueryBuilder('location')
            ->innerJoin('location.parent', 'parent')
            ->andWhere('parent.id = :parentId')
            ->andWhere('parent.active = true')
            ->andWhere('location.active = true')
            ->andWhere('(parent.level = :province AND location.level = :district) OR (parent.level = :districtLevel AND location.level = :school)')
            ->setParameter('parentId', $parentId, 'uuid')
            ->setParameter('province', LocationLevel::Province)
            ->setParameter('district', LocationLevel::District)
            ->setParameter('districtLevel', LocationLevel::District)
            ->setParameter('school', LocationLevel::School)
            ->orderBy('location.name', 'ASC')
            ->addOrderBy('location.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    private function chainQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('school')
            ->innerJoin('school.parent', 'district')
            ->addSelect('district')
            ->innerJoin('district.parent', 'province')
            ->addSelect('province')
            ->andWhere('school.level = :schoolLevel')
            ->andWhere('district.level = :districtLevel')
            ->andWhere('province.level = :provinceLevel')
            ->andWhere('school.active = true')
            ->andWhere('district.active = true')
            ->andWhere('province.active = true')
            ->andWhere('province.parent IS NULL')
            ->setParameter('schoolLevel', LocationLevel::School)
            ->setParameter('districtLevel', LocationLevel::District)
            ->setParameter('provinceLevel', LocationLevel::Province);
    }
}
