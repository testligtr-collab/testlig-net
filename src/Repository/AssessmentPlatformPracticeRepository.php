<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Assessment;
use App\Entity\AssessmentPlatformPractice;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssessmentPlatformPractice>
 */
class AssessmentPlatformPracticeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentPlatformPractice::class);
    }

    public function save(AssessmentPlatformPractice $practice, bool $flush = true): void
    {
        $this->getEntityManager()->persist($practice);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findForUserAndAssessment(User $user, Assessment $assessment): ?AssessmentPlatformPractice
    {
        $practice = $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.assessment = :assessment')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('assessment', $assessment->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $practice instanceof AssessmentPlatformPractice ? $practice : null;
    }
}
