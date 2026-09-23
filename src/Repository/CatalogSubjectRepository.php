<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CatalogSubject;
use App\Enum\CatalogPublicationStatus;
use App\Enum\GradeLevel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CatalogSubject>
 */
final class CatalogSubjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogSubject::class);
    }

    public function findOneById(Uuid $id): ?CatalogSubject
    {
        return $this->find($id);
    }

    public function findOneByGradeAndSlug(GradeLevel $grade, string $slug): ?CatalogSubject
    {
        return $this->findOneBy(['gradeLevel' => $grade, 'slug' => $slug]);
    }

    /**
     * @return list<CatalogSubject>
     */
    public function findPublishedByGrade(GradeLevel $grade): array
    {
        /** @var list<CatalogSubject> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.gradeLevel = :grade')
            ->andWhere('s.status = :status')
            ->setParameter('grade', $grade)
            ->setParameter('status', CatalogPublicationStatus::Published)
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<CatalogSubject>
     */
    public function findAllOrdered(): array
    {
        /** @var list<CatalogSubject> $rows */
        $rows = $this->createQueryBuilder('s')
            ->orderBy('s.gradeLevel', 'ASC')
            ->addOrderBy('s.position', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function existsSlugForGrade(GradeLevel $grade, string $slug, ?Uuid $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.gradeLevel = :grade')
            ->andWhere('s.slug = :slug')
            ->setParameter('grade', $grade)
            ->setParameter('slug', $slug);
        if ($exceptId instanceof Uuid) {
            $qb->andWhere('s.id != :except')->setParameter('except', $exceptId, 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function save(CatalogSubject $subject, bool $flush = true): void
    {
        $this->getEntityManager()->persist($subject);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
