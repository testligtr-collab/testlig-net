<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningContent;
use App\Entity\Subject;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LearningContent>
 */
class LearningContentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningContent::class);
    }

    public function findOneById(Uuid $id): ?LearningContent
    {
        return $this->find($id);
    }

    /**
     * Published platform contents for a canonical subject (placement bind choices).
     *
     * @return list<LearningContent>
     */
    public function findPublishedPlatformBySubject(Subject $subject): array
    {
        /** @var list<LearningContent> $rows */
        $rows = $this->createQueryBuilder('c')
            ->andWhere('IDENTITY(c.subject) = :subjectId')
            ->andWhere('c.scope = :scope')
            ->andWhere('c.status = :status')
            ->setParameter('subjectId', $subject->getId(), 'uuid')
            ->setParameter('scope', LearningContentScope::Platform)
            ->setParameter('status', LearningContentStatus::Published)
            ->orderBy('c.title', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function save(LearningContent $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
