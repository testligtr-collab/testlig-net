<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentRefund;
use App\Enum\PaymentRefundStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PaymentRefund>
 */
class PaymentRefundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentRefund::class);
    }

    public function findOneById(Uuid $id): ?PaymentRefund
    {
        $entity = $this->find($id);

        return $entity instanceof PaymentRefund ? $entity : null;
    }

    public function findOneByIdempotencyKeyHash(string $idempotencyKeyHash): ?PaymentRefund
    {
        $entity = $this->findOneBy(['idempotencyKeyHash' => $idempotencyKeyHash]);

        return $entity instanceof PaymentRefund ? $entity : null;
    }

    public function save(PaymentRefund $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function nextRefundNumber(Uuid $attemptId): int
    {
        $max = $this->createQueryBuilder('r')
            ->select('MAX(r.refundNumber)')
            ->andWhere('IDENTITY(r.paymentAttempt) = :attemptId')
            ->setParameter('attemptId', $attemptId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return (\is_int($max) || \is_string($max) ? (int) $max : 0) + 1;
    }

    /**
     * Sum of non-failed refunds (requested + succeeded) used for the refund cap check.
     */
    public function reservedAmountMinor(Uuid $attemptId): int
    {
        $sum = $this->createQueryBuilder('r')
            ->select('SUM(r.amountMinor)')
            ->andWhere('IDENTITY(r.paymentAttempt) = :attemptId')
            ->andWhere('r.status <> :failed')
            ->setParameter('attemptId', $attemptId, 'uuid')
            ->setParameter('failed', PaymentRefundStatus::Failed)
            ->getQuery()
            ->getSingleScalarResult();

        return \is_int($sum) || \is_string($sum) ? (int) $sum : 0;
    }

    public function findOpenForAttempt(Uuid $attemptId): ?PaymentRefund
    {
        $entity = $this->createQueryBuilder('r')
            ->andWhere('IDENTITY(r.paymentAttempt) = :attemptId')
            ->andWhere('r.status = :requested')
            ->setParameter('attemptId', $attemptId, 'uuid')
            ->setParameter('requested', PaymentRefundStatus::Requested)
            ->orderBy('r.refundNumber', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entity instanceof PaymentRefund ? $entity : null;
    }
}
