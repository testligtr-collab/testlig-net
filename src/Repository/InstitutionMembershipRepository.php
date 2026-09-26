<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<InstitutionMembership>
 */
class InstitutionMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstitutionMembership::class);
    }

    public function findOneById(Uuid $id): ?InstitutionMembership
    {
        return $this->find($id);
    }

    public function findMembership(User $user, Institution $institution): ?InstitutionMembership
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->andWhere('m.institution = :institution')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveMembership(User $user, Institution $institution): ?InstitutionMembership
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->andWhere('m.institution = :institution')
            ->andWhere('m.status = :status')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('status', InstitutionMembershipStatus::Active)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function hasActiveMembership(User $user, Institution $institution): bool
    {
        return null !== $this->findActiveMembership($user, $institution);
    }

    public function countActiveOwners(Institution $institution): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.institution = :institution')
            ->andWhere('m.role = :role')
            ->andWhere('m.status = :status')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('role', InstitutionMembershipRole::Owner)
            ->setParameter('status', InstitutionMembershipStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<InstitutionMembership>
     */
    public function findByInstitution(Institution $institution): array
    {
        /** @var list<InstitutionMembership> $rows */
        $rows = $this->createQueryBuilder('m')
            ->andWhere('m.institution = :institution')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Active owner/manager rows on active institutions for one user.
     *
     * @return list<InstitutionMembership>
     */
    public function findActiveLeadership(User $user): array
    {
        /** @var list<InstitutionMembership> $rows */
        $rows = $this->createQueryBuilder('m')
            ->addSelect('i')
            ->innerJoin('m.institution', 'i')
            ->andWhere('m.user = :user')
            ->andWhere('m.status = :membershipStatus')
            ->andWhere('m.role IN (:roles)')
            ->andWhere('i.status = :institutionStatus')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('membershipStatus', InstitutionMembershipStatus::Active)
            ->setParameter('roles', [InstitutionMembershipRole::Owner, InstitutionMembershipRole::Manager])
            ->setParameter('institutionStatus', InstitutionStatus::Active)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function hasAnyMembership(User $user): bool
    {
        return null !== $this->createQueryBuilder('m')
            ->select('1')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(InstitutionMembership $membership, bool $flush = true): void
    {
        $this->getEntityManager()->persist($membership);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
