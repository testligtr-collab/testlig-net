<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Institution;
use App\Entity\Question;
use App\Entity\User;
use App\Enum\QuestionScope;
use App\Enum\QuestionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Question>
 */
class QuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Question::class);
    }

    public function findOneById(Uuid $id): ?Question
    {
        return $this->find($id);
    }

    /**
     * @return list<Question>
     */
    public function findPublishedPlatform(): array
    {
        /** @var list<Question> $rows */
        $rows = $this->createQueryBuilder('q')
            ->andWhere('q.scope = :scope')
            ->andWhere('q.status = :status')
            ->setParameter('scope', QuestionScope::Platform)
            ->setParameter('status', QuestionStatus::Published)
            ->orderBy('q.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<Question>
     */
    public function findPublishedForInstitution(Institution $institution): array
    {
        /** @var list<Question> $rows */
        $rows = $this->createQueryBuilder('q')
            ->andWhere('q.scope = :scope')
            ->andWhere('q.institution = :institution')
            ->andWhere('q.status = :status')
            ->setParameter('scope', QuestionScope::Institution)
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('status', QuestionStatus::Published)
            ->orderBy('q.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<Question>
     */
    public function findPlatformVisible(User $actor, bool $seeAll): array
    {
        $qb = $this->createQueryBuilder('q')
            ->andWhere('q.scope = :scope')
            ->setParameter('scope', QuestionScope::Platform)
            ->orderBy('q.updatedAt', 'DESC')
            ->addOrderBy('q.id', 'DESC')
            ->setMaxResults(100);
        if (!$seeAll) {
            $qb->andWhere('q.status = :published OR IDENTITY(q.createdBy) = :actor')
                ->setParameter('published', QuestionStatus::Published)
                ->setParameter('actor', $actor->getId(), 'uuid');
        }

        /** @var list<Question> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    public function save(Question $question, bool $flush = true): void
    {
        $this->getEntityManager()->persist($question);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
