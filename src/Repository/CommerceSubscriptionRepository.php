<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CommerceSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CommerceSubscription>
 */
class CommerceSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommerceSubscription::class);
    }

    public function findOneById(Uuid $id): ?CommerceSubscription
    {
        $entity = $this->find($id);

        return $entity instanceof CommerceSubscription ? $entity : null;
    }

    public function save(CommerceSubscription $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneForOrderOffer(Uuid $orderId, Uuid $offerId): ?CommerceSubscription
    {
        $entity = $this->createQueryBuilder('s')
            ->andWhere('IDENTITY(s.order) = :orderId')
            ->andWhere('IDENTITY(s.offer) = :offerId')
            ->setParameter('orderId', $orderId, 'uuid')
            ->setParameter('offerId', $offerId, 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entity instanceof CommerceSubscription ? $entity : null;
    }
}
