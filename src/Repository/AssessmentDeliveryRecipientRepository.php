<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentDeliveryRecipient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentDeliveryRecipient>
 */
class AssessmentDeliveryRecipientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentDeliveryRecipient::class);
    }

    public function findOneById(Uuid $id): ?AssessmentDeliveryRecipient
    {
        return $this->find($id);
    }

    public function save(AssessmentDeliveryRecipient $recipient, bool $flush = true): void
    {
        $this->getEntityManager()->persist($recipient);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
