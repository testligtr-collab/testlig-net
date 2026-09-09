<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CurriculumTopic;
use App\Entity\CurriculumUnit;
use App\Enum\CurriculumContentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CurriculumTopic>
 */
class CurriculumTopicRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CurriculumTopic::class);
    }

    public function findOneById(Uuid $id): ?CurriculumTopic
    {
        return $this->find($id);
    }

    public function existsWithCode(CurriculumUnit $unit, string $code): bool
    {
        return null !== $this->createQueryBuilder('t')
            ->select('1')
            ->andWhere('t.unit = :unit')
            ->andWhere('t.code = :code')
            ->setParameter('unit', $unit->getId(), 'uuid')
            ->setParameter('code', $code)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsRootPosition(CurriculumUnit $unit, int $position): bool
    {
        return null !== $this->createQueryBuilder('t')
            ->select('1')
            ->andWhere('t.unit = :unit')
            ->andWhere('t.parent IS NULL')
            ->andWhere('t.position = :position')
            ->setParameter('unit', $unit->getId(), 'uuid')
            ->setParameter('position', $position)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsChildPosition(CurriculumUnit $unit, CurriculumTopic $parent, int $position): bool
    {
        return null !== $this->createQueryBuilder('t')
            ->select('1')
            ->andWhere('t.unit = :unit')
            ->andWhere('t.parent = :parent')
            ->andWhere('t.position = :position')
            ->setParameter('unit', $unit->getId(), 'uuid')
            ->setParameter('parent', $parent->getId(), 'uuid')
            ->setParameter('position', $position)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countActiveChildren(CurriculumTopic $topic): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.parent = :parent')
            ->andWhere('t.status = :status')
            ->setParameter('parent', $topic->getId(), 'uuid')
            ->setParameter('status', CurriculumContentStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<CurriculumTopic>
     */
    public function findByUnit(CurriculumUnit $unit): array
    {
        /** @var list<CurriculumTopic> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.unit = :unit')
            ->setParameter('unit', $unit->getId(), 'uuid')
            ->orderBy('t.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function save(CurriculumTopic $topic, bool $flush = true): void
    {
        $this->getEntityManager()->persist($topic);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
