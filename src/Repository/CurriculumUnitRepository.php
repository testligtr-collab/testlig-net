<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CurriculumProgram;
use App\Entity\CurriculumUnit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CurriculumUnit>
 */
class CurriculumUnitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CurriculumUnit::class);
    }

    public function findOneById(Uuid $id): ?CurriculumUnit
    {
        return $this->find($id);
    }

    public function existsWithCode(CurriculumProgram $program, string $code): bool
    {
        return null !== $this->createQueryBuilder('u')
            ->select('1')
            ->andWhere('u.program = :program')
            ->andWhere('u.code = :code')
            ->setParameter('program', $program->getId(), 'uuid')
            ->setParameter('code', $code)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsWithPosition(CurriculumProgram $program, int $position): bool
    {
        return null !== $this->createQueryBuilder('u')
            ->select('1')
            ->andWhere('u.program = :program')
            ->andWhere('u.position = :position')
            ->setParameter('program', $program->getId(), 'uuid')
            ->setParameter('position', $position)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<CurriculumUnit>
     */
    public function findByProgram(CurriculumProgram $program): array
    {
        /** @var list<CurriculumUnit> $rows */
        $rows = $this->createQueryBuilder('u')
            ->andWhere('u.program = :program')
            ->setParameter('program', $program->getId(), 'uuid')
            ->orderBy('u.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function save(CurriculumUnit $unit, bool $flush = true): void
    {
        $this->getEntityManager()->persist($unit);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
