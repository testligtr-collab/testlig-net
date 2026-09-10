<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentDelivery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentDelivery>
 */
class AssessmentDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentDelivery::class);
    }

    public function findOneById(Uuid $id): ?AssessmentDelivery
    {
        return $this->find($id);
    }

    public function save(AssessmentDelivery $delivery, bool $flush = true): void
    {
        $this->getEntityManager()->persist($delivery);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
