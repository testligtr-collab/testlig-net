<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentAttempt;
use App\Enum\PaymentProviderEnvironment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PaymentAttempt>
 */
class PaymentAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentAttempt::class);
    }

    public function findOneById(Uuid $id): ?PaymentAttempt
    {
        $entity = $this->find($id);

        return $entity instanceof PaymentAttempt ? $entity : null;
    }

    public function findOneByIdempotencyKeyHash(string $idempotencyKeyHash): ?PaymentAttempt
    {
        $entity = $this->findOneBy(['idempotencyKeyHash' => $idempotencyKeyHash]);

        return $entity instanceof PaymentAttempt ? $entity : null;
    }

    public function save(PaymentAttempt $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function nextAttemptNumber(Uuid $orderId): int
    {
        $max = $this->createQueryBuilder('a')
            ->select('MAX(a.attemptNumber)')
            ->andWhere('IDENTITY(a.order) = :orderId')
            ->setParameter('orderId', $orderId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return (\is_int($max) || \is_string($max) ? (int) $max : 0) + 1;
    }

    /**
     * @return list<PaymentAttempt>
     */
    public function findForReconciliation(
        string $providerCode,
        PaymentProviderEnvironment $environment,
        int $limit,
    ): array {
        if ($limit < 1) {
            return [];
        }

        /** @var list<PaymentAttempt> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.providerCode = :providerCode')
            ->andWhere('a.environment = :environment')
            ->setParameter('providerCode', $providerCode)
            ->setParameter('environment', $environment)
            ->orderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
