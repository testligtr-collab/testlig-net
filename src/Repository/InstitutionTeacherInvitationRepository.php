<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InstitutionTeacherInvitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<InstitutionTeacherInvitation>
 */
class InstitutionTeacherInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstitutionTeacherInvitation::class);
    }

    public function save(InstitutionTeacherInvitation $invitation, bool $flush = true): void
    {
        $this->getEntityManager()->persist($invitation);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByDigest(string $digest): ?InstitutionTeacherInvitation
    {
        $invitation = $this->findOneBy(['tokenDigest' => $digest]);

        return $invitation instanceof InstitutionTeacherInvitation ? $invitation : null;
    }

    public function findOneByDigestForUpdate(string $digest): ?InstitutionTeacherInvitation
    {
        $query = $this->createQueryBuilder('i')
            ->andWhere('i.tokenDigest = :digest')
            ->setParameter('digest', $digest)
            ->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $invitation = $query->getOneOrNullResult();

        return $invitation instanceof InstitutionTeacherInvitation ? $invitation : null;
    }

    public function findOneByIdForUpdate(Uuid $id): ?InstitutionTeacherInvitation
    {
        $query = $this->createQueryBuilder('i')
            ->andWhere('i.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $invitation = $query->getOneOrNullResult();

        return $invitation instanceof InstitutionTeacherInvitation ? $invitation : null;
    }
}
