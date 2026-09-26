<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ParentStudentLink;
use App\Enum\ParentStudentLinkStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ParentStudentLink>
 */
class ParentStudentLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParentStudentLink::class);
    }

    public function findOneById(Uuid $id): ?ParentStudentLink
    {
        return $this->find($id);
    }

    public function findOneByPersonalInvitationId(Uuid $invitationId): ?ParentStudentLink
    {
        return $this->findOneBy(['personalInvitation' => $invitationId]);
    }

    /**
     * Pessimistic write reload. Requires an open transaction.
     */
    public function findOneByIdForUpdate(Uuid $id): ?ParentStudentLink
    {
        $query = $this->createQueryBuilder('l')
            ->andWhere('l.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery();
        $query->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        $query->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true);

        $result = $query->getOneOrNullResult();

        return $result instanceof ParentStudentLink ? $result : null;
    }

    public function findOneByPersonalInvitationIdForUpdate(Uuid $invitationId): ?ParentStudentLink
    {
        $query = $this->createQueryBuilder('l')
            ->andWhere('l.personalInvitation = :invitationId')
            ->setParameter('invitationId', $invitationId, 'uuid')
            ->getQuery();
        $query->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        $query->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true);

        $result = $query->getOneOrNullResult();

        return $result instanceof ParentStudentLink ? $result : null;
    }

    public function countVerifiedForStudent(Uuid $studentId): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.student = :student')
            ->andWhere('l.status = :verified')
            ->setParameter('student', $studentId, 'uuid')
            ->setParameter('verified', ParentStudentLinkStatus::Verified)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<ParentStudentLink>
     */
    public function findVerifiedForStudent(Uuid $studentId): array
    {
        /** @var list<ParentStudentLink> $rows */
        $rows = $this->createQueryBuilder('l')
            ->addSelect('parent')
            ->innerJoin('l.parent', 'parent')
            ->andWhere('l.student = :student')
            ->andWhere('l.status = :verified')
            ->setParameter('student', $studentId, 'uuid')
            ->setParameter('verified', ParentStudentLinkStatus::Verified)
            ->orderBy('l.verifiedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<ParentStudentLink>
     */
    public function findVerifiedForParent(Uuid $parentId): array
    {
        /** @var list<ParentStudentLink> $rows */
        $rows = $this->createQueryBuilder('l')
            ->addSelect('student')
            ->innerJoin('l.student', 'student')
            ->andWhere('l.parent = :parent')
            ->andWhere('l.status = :verified')
            ->setParameter('parent', $parentId, 'uuid')
            ->setParameter('verified', ParentStudentLinkStatus::Verified)
            ->orderBy('l.verifiedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function save(ParentStudentLink $link, bool $flush = true): void
    {
        $this->getEntityManager()->persist($link);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
