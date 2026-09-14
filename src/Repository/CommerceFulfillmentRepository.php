<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CommerceFulfillment;
use App\Enum\CommerceFulfillmentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CommerceFulfillment>
 */
class CommerceFulfillmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommerceFulfillment::class);
    }

    public function findOneById(Uuid $id): ?CommerceFulfillment
    {
        $entity = $this->find($id);

        return $entity instanceof CommerceFulfillment ? $entity : null;
    }

    public function findOneByIdempotencyKeyHash(string $idempotencyKeyHash): ?CommerceFulfillment
    {
        $entity = $this->findOneBy(['idempotencyKeyHash' => $idempotencyKeyHash]);

        return $entity instanceof CommerceFulfillment ? $entity : null;
    }

    public function save(CommerceFulfillment $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function nextFulfillmentNumber(Uuid $orderId): int
    {
        $max = $this->createQueryBuilder('f')
            ->select('MAX(f.fulfillmentNumber)')
            ->andWhere('IDENTITY(f.order) = :orderId')
            ->setParameter('orderId', $orderId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return (\is_int($max) || \is_string($max) ? (int) $max : 0) + 1;
    }

    public function findCompletedForOrderItem(Uuid $orderItemId): ?CommerceFulfillment
    {
        $entity = $this->createQueryBuilder('f')
            ->andWhere('IDENTITY(f.orderItem) = :orderItemId')
            ->andWhere('f.status = :status')
            ->andWhere('f.subscription IS NULL')
            ->setParameter('orderItemId', $orderItemId, 'uuid')
            ->setParameter('status', CommerceFulfillmentStatus::Completed)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entity instanceof CommerceFulfillment ? $entity : null;
    }

    public function findCompletedForSubscriptionPeriod(Uuid $subscriptionId, string $periodKey): ?CommerceFulfillment
    {
        $entity = $this->createQueryBuilder('f')
            ->andWhere('IDENTITY(f.subscription) = :subscriptionId')
            ->andWhere('f.periodKey = :periodKey')
            ->andWhere('f.status = :status')
            ->setParameter('subscriptionId', $subscriptionId, 'uuid')
            ->setParameter('periodKey', $periodKey)
            ->setParameter('status', CommerceFulfillmentStatus::Completed)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entity instanceof CommerceFulfillment ? $entity : null;
    }
}
