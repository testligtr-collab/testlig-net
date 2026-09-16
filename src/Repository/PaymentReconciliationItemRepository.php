<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentReconciliationItem;
use App\Entity\PaymentReconciliationRun;
use App\Enum\PaymentReconciliationItemOutcome;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PaymentReconciliationItem>
 */
final class PaymentReconciliationItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentReconciliationItem::class);
    }

    public function save(PaymentReconciliationItem $item, bool $flush = true): void
    {
        $this->getEntityManager()->persist($item);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneById(Uuid $id): ?PaymentReconciliationItem
    {
        $item = $this->find($id);

        return $item instanceof PaymentReconciliationItem ? $item : null;
    }

    /**
     * @return list<PaymentReconciliationItem>
     */
    public function findByRun(PaymentReconciliationRun $run): array
    {
        /** @var list<PaymentReconciliationItem> $items */
        $items = $this->findBy(['run' => $run], ['checkedAt' => 'ASC', 'id' => 'ASC']);

        return $items;
    }

    public function countDiscrepanciesByRun(PaymentReconciliationRun $run): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.run = :run')
            ->andWhere('i.outcome <> :matched')
            ->setParameter('run', $run->getId(), 'uuid')
            ->setParameter('matched', PaymentReconciliationItemOutcome::Matched)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<PaymentReconciliationItem>
     */
    public function findDiscrepanciesByRun(
        PaymentReconciliationRun $run,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.run = :run')
            ->andWhere('i.outcome <> :matched')
            ->setParameter('run', $run->getId(), 'uuid')
            ->setParameter('matched', PaymentReconciliationItemOutcome::Matched)
            ->orderBy('i.checkedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }
        if (null !== $offset) {
            $qb->setFirstResult($offset);
        }

        /** @var list<PaymentReconciliationItem> $items */
        $items = $qb->getQuery()->getResult();

        return $items;
    }
}
