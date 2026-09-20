<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PhoneVerificationClaim;
use App\Entity\User;
use App\Enum\PhoneVerificationPurpose;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PhoneVerificationClaim>
 */
class PhoneVerificationClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PhoneVerificationClaim::class);
    }

    public function findOneById(Uuid $id): ?PhoneVerificationClaim
    {
        return $this->find($id);
    }

    /**
     * Newest claim for user+purpose (pending uniqueness enforced in 2.22.2b).
     */
    public function findLatestForUserPurpose(User $user, PhoneVerificationPurpose $purpose): ?PhoneVerificationClaim
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.purpose = :purpose')
            ->setParameter('user', $user)
            ->setParameter('purpose', $purpose)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(PhoneVerificationClaim $claim, bool $flush = true): void
    {
        $this->getEntityManager()->persist($claim);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
