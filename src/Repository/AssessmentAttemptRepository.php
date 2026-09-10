<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentAttempt;
use App\Enum\AssessmentAttemptStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentAttempt>
 */
class AssessmentAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentAttempt::class);
    }

    public function findOneById(Uuid $id): ?AssessmentAttempt
    {
        return $this->find($id);
    }

    public function countForRecipient(Uuid $deliveryId, Uuid $recipientId): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.delivery = :deliveryId')
            ->andWhere('a.recipient = :recipientId')
            ->setParameter('deliveryId', $deliveryId, 'uuid')
            ->setParameter('recipientId', $recipientId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findActiveForRecipient(Uuid $deliveryId, Uuid $recipientId): ?AssessmentAttempt
    {
        /** @var AssessmentAttempt|null $attempt */
        $attempt = $this->createQueryBuilder('a')
            ->andWhere('a.delivery = :deliveryId')
            ->andWhere('a.recipient = :recipientId')
            ->andWhere('a.status = :status')
            ->setParameter('deliveryId', $deliveryId, 'uuid')
            ->setParameter('recipientId', $recipientId, 'uuid')
            ->setParameter('status', AssessmentAttemptStatus::InProgress)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $attempt;
    }

    public function findMaxAttemptNumber(Uuid $deliveryId, Uuid $recipientId): int
    {
        $max = $this->createQueryBuilder('a')
            ->select('MAX(a.attemptNumber)')
            ->andWhere('a.delivery = :deliveryId')
            ->andWhere('a.recipient = :recipientId')
            ->setParameter('deliveryId', $deliveryId, 'uuid')
            ->setParameter('recipientId', $recipientId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max;
    }

    public function findFreshAttempt(Uuid $id, LockMode $lockMode): ?AssessmentAttempt
    {
        $query = $this->createQueryBuilder('a')
            ->andWhere('a.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode($lockMode);

        /** @var AssessmentAttempt|null $attempt */
        $attempt = $query->getOneOrNullResult();

        return $attempt;
    }

    public function save(AssessmentAttempt $attempt, bool $flush = true): void
    {
        $this->getEntityManager()->persist($attempt);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
