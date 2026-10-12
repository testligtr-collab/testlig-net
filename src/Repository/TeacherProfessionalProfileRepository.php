<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TeacherProfessionalProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeacherProfessionalProfile>
 */
final class TeacherProfessionalProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeacherProfessionalProfile::class);
    }

    public function findOneByUser(User $user): ?TeacherProfessionalProfile
    {
        $result = $this->createQueryBuilder('profile')
            ->leftJoin('profile.schoolLocation', 'school')->addSelect('school')
            ->leftJoin('school.parent', 'district')->addSelect('district')
            ->leftJoin('district.parent', 'province')->addSelect('province')
            ->andWhere('IDENTITY(profile.user) = :userId')
            ->setParameter('userId', $user->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof TeacherProfessionalProfile ? $result : null;
    }
}
