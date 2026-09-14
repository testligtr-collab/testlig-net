<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentEvent;
use App\Enum\PaymentEventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Append-only log repository: no remove() by design.
 *
 * @extends ServiceEntityRepository<PaymentEvent>
 */
class PaymentEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentEvent::class);
    }

    public function findOneById(Uuid $id): ?PaymentEvent
    {
        $entity = $this->find($id);

        return $entity instanceof PaymentEvent ? $entity : null;
    }

    public function findOneByIdempotencyKeyHash(string $idempotencyKeyHash): ?PaymentEvent
    {
        $entity = $this->findOneBy(['idempotencyKeyHash' => $idempotencyKeyHash]);

        return $entity instanceof PaymentEvent ? $entity : null;
    }

    public function save(PaymentEvent $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findLatestForAttempt(Uuid $attemptId): ?PaymentEvent
    {
        $entity = $this->createQueryBuilder('e')
            ->andWhere('IDENTITY(e.attempt) = :attemptId')
            ->setParameter('attemptId', $attemptId, 'uuid')
            ->orderBy('e.sequenceNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entity instanceof PaymentEvent ? $entity : null;
    }

    public function findOneForAttemptByType(Uuid $attemptId, PaymentEventType $eventType): ?PaymentEvent
    {
        $entity = $this->createQueryBuilder('e')
            ->andWhere('IDENTITY(e.attempt) = :attemptId')
            ->andWhere('e.eventType = :eventType')
            ->setParameter('attemptId', $attemptId, 'uuid')
            ->setParameter('eventType', $eventType)
            ->orderBy('e.sequenceNumber', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entity instanceof PaymentEvent ? $entity : null;
    }

    /** @return list<PaymentEvent> */
    public function findChainForAttempt(Uuid $attemptId): array
    {
        /** @var list<PaymentEvent> $rows */
        $rows = $this->createQueryBuilder('e')
            ->andWhere('IDENTITY(e.attempt) = :attemptId')
            ->setParameter('attemptId', $attemptId, 'uuid')
            ->orderBy('e.sequenceNumber', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
