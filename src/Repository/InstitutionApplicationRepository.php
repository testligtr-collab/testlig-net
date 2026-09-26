<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InstitutionApplication;
use App\Entity\User;
use App\Enum\OnboardingApplicationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<InstitutionApplication>
 */
class InstitutionApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstitutionApplication::class);
    }

    public function findOneById(Uuid $id): ?InstitutionApplication
    {
        return $this->find($id);
    }

    public function findLatestForUser(User $user): ?InstitutionApplication
    {
        return $this->createQueryBuilder('a')
            ->andWhere('IDENTITY(a.user) = :userId')
            ->setParameter('userId', $user->getId(), 'uuid')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOpenForUser(User $user): ?InstitutionApplication
    {
        return $this->createQueryBuilder('a')
            ->andWhere('IDENTITY(a.user) = :userId')
            ->andWhere('a.status = :status')
            ->setParameter('userId', $user->getId(), 'uuid')
            ->setParameter('status', OnboardingApplicationStatus::Pending)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOpenForUserForUpdate(User $user): ?InstitutionApplication
    {
        $query = $this->createQueryBuilder('a')
            ->andWhere('IDENTITY(a.user) = :userId')
            ->andWhere('a.status = :status')
            ->setParameter('userId', $user->getId(), 'uuid')
            ->setParameter('status', OnboardingApplicationStatus::Pending)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);

        $result = $query->getOneOrNullResult();

        return $result instanceof InstitutionApplication ? $result : null;
    }

    public function findOneForUserForUpdate(User $user, Uuid $applicationId): ?InstitutionApplication
    {
        $query = $this->createQueryBuilder('a')
            ->andWhere('a.id = :id')
            ->andWhere('IDENTITY(a.user) = :userId')
            ->setParameter('id', $applicationId, 'uuid')
            ->setParameter('userId', $user->getId(), 'uuid')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);

        $result = $query->getOneOrNullResult();

        return $result instanceof InstitutionApplication ? $result : null;
    }

    public function save(InstitutionApplication $application, bool $flush = true): void
    {
        $this->getEntityManager()->persist($application);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
