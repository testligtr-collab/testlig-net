<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CatalogTopic;
use App\Entity\CatalogUnit;
use App\Enum\CatalogPublicationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CatalogTopic>
 */
final class CatalogTopicRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogTopic::class);
    }

    public function findOneById(Uuid $id): ?CatalogTopic
    {
        return $this->find($id);
    }

    public function findOneByUnitAndSlug(CatalogUnit $unit, string $slug): ?CatalogTopic
    {
        /** @var CatalogTopic|null $topic */
        $topic = $this->createQueryBuilder('t')
            ->andWhere('t.unit = :unit')
            ->andWhere('t.slug = :slug')
            ->setParameter('unit', $unit->getId(), 'uuid')
            ->setParameter('slug', $slug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $topic;
    }

    public function findOneBySourceIdentity(?string $version, ?string $code, int $occurrence): ?CatalogTopic
    {
        if (null === $version || null === $code) {
            return null;
        }

        return $this->findOneBy([
            'sourceVersion' => $version,
            'sourceCode' => $code,
            'sourceOccurrence' => $occurrence,
        ]);
    }

    /**
     * @return list<CatalogTopic>
     */
    public function findByUnitOrdered(CatalogUnit $unit): array
    {
        /** @var list<CatalogTopic> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.unit = :unit')
            ->setParameter('unit', $unit->getId(), 'uuid')
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<CatalogTopic>
     */
    public function findPublishedByUnit(CatalogUnit $unit): array
    {
        /** @var list<CatalogTopic> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.unit = :unit')
            ->andWhere('t.status = :status')
            ->setParameter('unit', $unit->getId(), 'uuid')
            ->setParameter('status', CatalogPublicationStatus::Published)
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function existsSlugForUnit(CatalogUnit $unit, string $slug, ?Uuid $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.unit = :unit')
            ->andWhere('t.slug = :slug')
            ->setParameter('unit', $unit->getId(), 'uuid')
            ->setParameter('slug', $slug);
        if ($exceptId instanceof Uuid) {
            $qb->andWhere('t.id != :except')->setParameter('except', $exceptId, 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function countByUnit(CatalogUnit $unit): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.unit = :unit')
            ->setParameter('unit', $unit->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(CatalogTopic $topic, bool $flush = true): void
    {
        $this->getEntityManager()->persist($topic);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
