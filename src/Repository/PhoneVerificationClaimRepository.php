<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PhoneVerificationClaim;
use App\Entity\User;
use App\Enum\PhoneVerificationPurpose;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
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
            ->andWhere('IDENTITY(c.user) = :userId')
            ->andWhere('c.purpose = :purpose')
            ->setParameter('userId', $user->getId(), 'uuid')
            ->setParameter('purpose', $purpose)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Open (not consumed, not revoked) claims for user+purpose. Caller must hold a write lock on the user.
     *
     * @return list<PhoneVerificationClaim>
     */
    public function findOpenForUserPurposeForUpdate(User $user, PhoneVerificationPurpose $purpose): array
    {
        $query = $this->createQueryBuilder('c')
            ->andWhere('IDENTITY(c.user) = :userId')
            ->andWhere('c.purpose = :purpose')
            ->andWhere('c.consumedAt IS NULL')
            ->andWhere('c.revokedAt IS NULL')
            ->setParameter('userId', $user->getId(), 'uuid')
            ->setParameter('purpose', $purpose)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);

        /** @var list<PhoneVerificationClaim> $rows */
        $rows = $query->getResult();

        return $rows;
    }

    /**
     * Load a claim for the given user under pessimistic write lock (user must already be locked).
     */
    public function findOneForUserForUpdate(User $user, Uuid $claimId): ?PhoneVerificationClaim
    {
        $query = $this->createQueryBuilder('c')
            ->andWhere('c.id = :claimId')
            ->andWhere('IDENTITY(c.user) = :userId')
            ->setParameter('claimId', $claimId, 'uuid')
            ->setParameter('userId', $user->getId(), 'uuid')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);

        $result = $query->getOneOrNullResult();

        return $result instanceof PhoneVerificationClaim ? $result : null;
    }

    public function save(PhoneVerificationClaim $claim, bool $flush = true): void
    {
        $this->getEntityManager()->persist($claim);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
