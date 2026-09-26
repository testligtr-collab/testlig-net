<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Assessment;
use App\Entity\User;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\GradeLevel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Assessment>
 */
class AssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assessment::class);
    }

    public function findOneById(Uuid $id): ?Assessment
    {
        return $this->find($id);
    }

    /**
     * @return list<Assessment>
     */
    public function findPlatformVisible(User $actor, bool $seeAll): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.scope = :scope')
            ->setParameter('scope', AssessmentScope::Platform)
            ->orderBy('a.updatedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(100);
        if (!$seeAll) {
            $qb->andWhere('a.status = :published OR IDENTITY(a.createdBy) = :actor')
                ->setParameter('published', AssessmentStatus::Published)
                ->setParameter('actor', $actor->getId(), 'uuid');
        }

        /** @var list<Assessment> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * @return list<Assessment>
     */
    public function findPublishedPlatformForGrade(GradeLevel $grade): array
    {
        /** @var list<Assessment> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.scope = :scope')
            ->andWhere('a.status = :status')
            ->andWhere('a.gradeLevel = :grade')
            ->setParameter('scope', AssessmentScope::Platform)
            ->setParameter('status', AssessmentStatus::Published)
            ->setParameter('grade', $grade)
            ->orderBy('a.publishedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findPublishedPlatformByCode(string $code, GradeLevel $grade): ?Assessment
    {
        $assessment = $this->createQueryBuilder('a')
            ->andWhere('a.code = :code')
            ->andWhere('a.scope = :scope')
            ->andWhere('a.status = :status')
            ->andWhere('a.gradeLevel = :grade')
            ->setParameter('code', $code)
            ->setParameter('scope', AssessmentScope::Platform)
            ->setParameter('status', AssessmentStatus::Published)
            ->setParameter('grade', $grade)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $assessment instanceof Assessment ? $assessment : null;
    }

    public function save(Assessment $assessment, bool $flush = true): void
    {
        $this->getEntityManager()->persist($assessment);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
