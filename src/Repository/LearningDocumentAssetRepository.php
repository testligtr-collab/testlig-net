<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningDocumentAsset;
use App\Entity\User;
use App\Enum\LearningDocumentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LearningDocumentAsset>
 */
class LearningDocumentAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningDocumentAsset::class);
    }

    public function save(LearningDocumentAsset $asset, bool $flush = true): void
    {
        $this->getEntityManager()->persist($asset);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, LearningDocumentAsset>
     */
    public function findMappedByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $qb = $this->createQueryBuilder('d');
        $clauses = [];
        foreach ($ids as $index => $id) {
            $name = 'id'.$index;
            $clauses[] = 'd.id = :'.$name;
            $qb->setParameter($name, $id, 'uuid');
        }
        /** @var list<LearningDocumentAsset> $rows */
        $rows = $qb->andWhere(implode(' OR ', $clauses))->getQuery()->getResult();
        $mapped = [];
        foreach ($rows as $row) {
            $mapped[$row->getId()->toRfc4122()] = $row;
        }

        return $mapped;
    }

    /**
     * @return list<LearningDocumentAsset>
     */
    public function findCandidates(User $actor, bool $includePlatformPending): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.status IN (:statuses)')
            ->setParameter('statuses', [LearningDocumentStatus::Pending, LearningDocumentStatus::Ready])
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(100);
        if (!$includePlatformPending) {
            $qb->andWhere('d.createdBy = :actor')->setParameter('actor', $actor->getId(), 'uuid');
        }

        /** @var list<LearningDocumentAsset> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }
}
