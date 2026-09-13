<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CommercialOffer;
use App\Enum\CommercialOfferStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CommercialOffer>
 */
class CommercialOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommercialOffer::class);
    }

    public function findOneById(Uuid $id): ?CommercialOffer
    {
        $entity = $this->find($id);

        return $entity instanceof CommercialOffer ? $entity : null;
    }

    public function findOneByCode(string $code): ?CommercialOffer
    {
        $entity = $this->findOneBy(['code' => $code]);

        return $entity instanceof CommercialOffer ? $entity : null;
    }

    public function save(CommercialOffer $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(CommercialOffer $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<CommercialOffer> */
    public function findActiveForPackage(Uuid $packageId): array
    {
        /** @var list<CommercialOffer> $rows */
        $rows = $this->createQueryBuilder('o')
            ->andWhere('IDENTITY(o.package) = :packageId')
            ->andWhere('o.status = :status')
            ->setParameter('packageId', $packageId, 'uuid')
            ->setParameter('status', CommercialOfferStatus::Active)
            ->orderBy('o.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
