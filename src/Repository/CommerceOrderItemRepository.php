<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CommerceOrderItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CommerceOrderItem>
 */
class CommerceOrderItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommerceOrderItem::class);
    }

    public function findOneById(Uuid $id): ?CommerceOrderItem
    {
        $entity = $this->find($id);

        return $entity instanceof CommerceOrderItem ? $entity : null;
    }

    public function save(CommerceOrderItem $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<CommerceOrderItem> */
    public function findForOrder(Uuid $orderId): array
    {
        /** @var list<CommerceOrderItem> $rows */
        $rows = $this->createQueryBuilder('i')
            ->andWhere('IDENTITY(i.order) = :orderId')
            ->setParameter('orderId', $orderId, 'uuid')
            ->orderBy('i.createdAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
