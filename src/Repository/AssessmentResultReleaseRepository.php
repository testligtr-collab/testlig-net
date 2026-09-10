<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentResultRelease;
use App\Enum\ResultReleaseStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentResultRelease>
 */
class AssessmentResultReleaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentResultRelease::class);
    }

    public function findOneById(Uuid $id): ?AssessmentResultRelease
    {
        return $this->find($id);
    }

    public function findMaxReleaseNumber(Uuid $attemptId): int
    {
        $max = $this->createQueryBuilder('r')
            ->select('MAX(r.releaseNumber)')
            ->andWhere('r.attempt = :attemptId')
            ->setParameter('attemptId', $attemptId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max;
    }

    public function findActiveReleasedForAttempt(Uuid $attemptId): ?AssessmentResultRelease
    {
        /** @var AssessmentResultRelease|null $release */
        $release = $this->createQueryBuilder('r')
            ->andWhere('r.attempt = :attemptId')
            ->andWhere('r.status = :status')
            ->setParameter('attemptId', $attemptId, 'uuid')
            ->setParameter('status', ResultReleaseStatus::Released)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $release;
    }

    public function save(AssessmentResultRelease $release, bool $flush = true): void
    {
        $this->getEntityManager()->persist($release);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
