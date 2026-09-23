<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CatalogSubject;
use App\Entity\CatalogUnit;
use App\Enum\CatalogPublicationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CatalogUnit>
 */
final class CatalogUnitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogUnit::class);
    }

    public function findOneById(Uuid $id): ?CatalogUnit
    {
        return $this->find($id);
    }

    public function findOneBySubjectAndSlug(CatalogSubject $subject, string $slug): ?CatalogUnit
    {
        /** @var CatalogUnit|null $unit */
        $unit = $this->createQueryBuilder('u')
            ->andWhere('u.subject = :subject')
            ->andWhere('u.slug = :slug')
            ->setParameter('subject', $subject->getId(), 'uuid')
            ->setParameter('slug', $slug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $unit;
    }

    /**
     * @return list<CatalogUnit>
     */
    public function findBySubjectOrdered(CatalogSubject $subject): array
    {
        /** @var list<CatalogUnit> $rows */
        $rows = $this->createQueryBuilder('u')
            ->andWhere('u.subject = :subject')
            ->setParameter('subject', $subject->getId(), 'uuid')
            ->orderBy('u.position', 'ASC')
            ->addOrderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<CatalogUnit>
     */
    public function findPublishedBySubject(CatalogSubject $subject): array
    {
        /** @var list<CatalogUnit> $rows */
        $rows = $this->createQueryBuilder('u')
            ->andWhere('u.subject = :subject')
            ->andWhere('u.status = :status')
            ->setParameter('subject', $subject->getId(), 'uuid')
            ->setParameter('status', CatalogPublicationStatus::Published)
            ->orderBy('u.position', 'ASC')
            ->addOrderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function existsSlugForSubject(CatalogSubject $subject, string $slug, ?Uuid $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.subject = :subject')
            ->andWhere('u.slug = :slug')
            ->setParameter('subject', $subject->getId(), 'uuid')
            ->setParameter('slug', $slug);
        if ($exceptId instanceof Uuid) {
            $qb->andWhere('u.id != :except')->setParameter('except', $exceptId, 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function countBySubject(CatalogSubject $subject): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.subject = :subject')
            ->setParameter('subject', $subject->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(CatalogUnit $unit, bool $flush = true): void
    {
        $this->getEntityManager()->persist($unit);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
