<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InstitutionStudentInvitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<InstitutionStudentInvitation>
 */
class InstitutionStudentInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstitutionStudentInvitation::class);
    }

    public function save(InstitutionStudentInvitation $invitation, bool $flush = true): void
    {
        $this->getEntityManager()->persist($invitation);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByDigest(string $digest): ?InstitutionStudentInvitation
    {
        $invitation = $this->findOneBy(['tokenDigest' => $digest]);

        return $invitation instanceof InstitutionStudentInvitation ? $invitation : null;
    }

    public function findOneByDigestForUpdate(string $digest): ?InstitutionStudentInvitation
    {
        $query = $this->createQueryBuilder('i')
            ->andWhere('i.tokenDigest = :digest')
            ->setParameter('digest', $digest)
            ->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $invitation = $query->getOneOrNullResult();

        return $invitation instanceof InstitutionStudentInvitation ? $invitation : null;
    }

    public function findOneByIdForUpdate(Uuid $id): ?InstitutionStudentInvitation
    {
        $query = $this->createQueryBuilder('i')
            ->andWhere('i.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $invitation = $query->getOneOrNullResult();

        return $invitation instanceof InstitutionStudentInvitation ? $invitation : null;
    }
}
