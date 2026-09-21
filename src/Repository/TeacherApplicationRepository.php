<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TeacherApplication;
use App\Entity\User;
use App\Enum\OnboardingApplicationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<TeacherApplication>
 */
class TeacherApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeacherApplication::class);
    }

    public function findOneById(Uuid $id): ?TeacherApplication
    {
        return $this->find($id);
    }

    public function findOpenForUserForUpdate(User $user): ?TeacherApplication
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

        return $result instanceof TeacherApplication ? $result : null;
    }

    public function findLatestApprovedForUser(User $user): ?TeacherApplication
    {
        return $this->createQueryBuilder('a')
            ->andWhere('IDENTITY(a.user) = :userId')
            ->andWhere('a.status = :status')
            ->setParameter('userId', $user->getId(), 'uuid')
            ->setParameter('status', OnboardingApplicationStatus::Approved)
            ->orderBy('a.decidedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneForUserForUpdate(User $user, Uuid $applicationId): ?TeacherApplication
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

        return $result instanceof TeacherApplication ? $result : null;
    }

    public function save(TeacherApplication $application, bool $flush = true): void
    {
        $this->getEntityManager()->persist($application);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
