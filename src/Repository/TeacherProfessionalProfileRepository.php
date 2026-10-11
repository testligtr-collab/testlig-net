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
        return $this->findOneBy(['user' => $user]);
    }
}
