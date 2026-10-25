<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InstitutionBranch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<InstitutionBranch>
 */
class InstitutionBranchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstitutionBranch::class);
    }

    public function findOneById(Uuid $id): ?InstitutionBranch
    {
        return $this->find($id);
    }

    public function existsWithCode(Uuid $institutionId, string $normalizedCode, ?Uuid $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->andWhere('IDENTITY(b.institution) = :institution')
            ->andWhere('b.normalizedCode = :code')
            ->setParameter('institution', $institutionId, 'uuid')
            ->setParameter('code', $normalizedCode);
        if ($exceptId instanceof Uuid) {
            $qb->andWhere('b.id <> :except')->setParameter('except', $exceptId, 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function save(InstitutionBranch $branch, bool $flush = true): void
    {
        $this->getEntityManager()->persist($branch);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
