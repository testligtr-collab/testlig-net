<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CommerceOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CommerceOrder>
 */
class CommerceOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommerceOrder::class);
    }

    public function findOneById(Uuid $id): ?CommerceOrder
    {
        $entity = $this->find($id);

        return $entity instanceof CommerceOrder ? $entity : null;
    }

    /**
     * Receipt lookups only — never an authorization decision input.
     */
    public function findOneByPublicReference(string $publicReference): ?CommerceOrder
    {
        $entity = $this->findOneBy(['publicReference' => $publicReference]);

        return $entity instanceof CommerceOrder ? $entity : null;
    }

    public function save(CommerceOrder $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(CommerceOrder $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
