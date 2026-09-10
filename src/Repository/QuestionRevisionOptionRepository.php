<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuestionRevision;
use App\Entity\QuestionRevisionOption;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<QuestionRevisionOption>
 */
class QuestionRevisionOptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestionRevisionOption::class);
    }

    /**
     * @return list<QuestionRevisionOption>
     */
    public function findByRevision(QuestionRevision $revision): array
    {
        /** @var list<QuestionRevisionOption> $rows */
        $rows = $this->createQueryBuilder('o')
            ->andWhere('o.revision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->orderBy('o.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function existsForRevisionAndStableKey(Uuid $revisionId, string $stableKey): bool
    {
        return null !== $this->createQueryBuilder('o')
            ->select('1')
            ->andWhere('o.revision = :revisionId')
            ->andWhere('o.stableKey = :stableKey')
            ->setParameter('revisionId', $revisionId, 'uuid')
            ->setParameter('stableKey', $stableKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<string>
     */
    public function listStableKeysForRevisionOrdered(Uuid $revisionId): array
    {
        /** @var list<string> $keys */
        $keys = $this->createQueryBuilder('o')
            ->select('o.stableKey')
            ->andWhere('o.revision = :revisionId')
            ->setParameter('revisionId', $revisionId, 'uuid')
            ->orderBy('o.position', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return $keys;
    }

    public function save(QuestionRevisionOption $option, bool $flush = true): void
    {
        $this->getEntityManager()->persist($option);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
