<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentReconciliationRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PaymentReconciliationRun>
 */
final class PaymentReconciliationRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentReconciliationRun::class);
    }

    public function save(PaymentReconciliationRun $run, bool $flush = true): void
    {
        $this->getEntityManager()->persist($run);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneById(Uuid $id): ?PaymentReconciliationRun
    {
        $run = $this->find($id);

        return $run instanceof PaymentReconciliationRun ? $run : null;
    }
}
