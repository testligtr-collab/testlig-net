<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StudentProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<StudentProfile>
 */
final class StudentProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StudentProfile::class);
    }

    public function findOneByUser(User $user): ?StudentProfile
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function findOneByUserId(Uuid $userId): ?StudentProfile
    {
        return $this->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.user) = :userId')
            ->setParameter('userId', $userId, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(StudentProfile $profile, bool $flush = true): void
    {
        $this->getEntityManager()->persist($profile);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
