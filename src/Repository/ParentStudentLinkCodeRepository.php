<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ParentStudentLinkCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ParentStudentLinkCode>
 */
class ParentStudentLinkCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParentStudentLinkCode::class);
    }

    public function findOneByDigest(string $digest): ?ParentStudentLinkCode
    {
        $code = $this->findOneBy(['tokenDigest' => $digest]);

        return $code instanceof ParentStudentLinkCode ? $code : null;
    }

    /**
     * Pessimistic write reload. Requires an open transaction.
     */
    public function findOneByDigestForUpdate(string $digest): ?ParentStudentLinkCode
    {
        $query = $this->createQueryBuilder('c')
            ->andWhere('c.tokenDigest = :digest')
            ->setParameter('digest', $digest)
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof ParentStudentLinkCode ? $result : null;
    }

    /**
     * @return list<ParentStudentLinkCode>
     */
    public function findUnconsumedForStudentForUpdate(Uuid $studentId): array
    {
        $query = $this->createQueryBuilder('c')
            ->andWhere('c.student = :student')
            ->andWhere('c.consumedAt IS NULL')
            ->andWhere('c.revokedAt IS NULL')
            ->setParameter('student', $studentId, 'uuid')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);

        /** @var list<ParentStudentLinkCode> $rows */
        $rows = $query->getResult();

        return $rows;
    }

    public function findLiveForStudent(Uuid $studentId, \DateTimeImmutable $now): ?ParentStudentLinkCode
    {
        $result = $this->createQueryBuilder('c')
            ->andWhere('c.student = :student')
            ->andWhere('c.consumedAt IS NULL')
            ->andWhere('c.revokedAt IS NULL')
            ->andWhere('c.expiresAt > :now')
            ->setParameter('student', $studentId, 'uuid')
            ->setParameter('now', $now)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof ParentStudentLinkCode ? $result : null;
    }

    public function save(ParentStudentLinkCode $code, bool $flush = true): void
    {
        $this->getEntityManager()->persist($code);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
