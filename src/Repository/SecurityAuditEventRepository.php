<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SecurityAuditEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecurityAuditEvent>
 */
class SecurityAuditEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityAuditEvent::class);
    }

    public function save(SecurityAuditEvent $event, bool $flush = true): void
    {
        $this->getEntityManager()->persist($event);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function countByAction(string $actionValue): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.action = :action')
            ->setParameter('action', $actionValue)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
