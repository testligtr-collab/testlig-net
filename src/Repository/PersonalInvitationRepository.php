<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PersonalInvitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PersonalInvitation>
 */
class PersonalInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonalInvitation::class);
    }

    public function findOneById(Uuid $id): ?PersonalInvitation
    {
        return $this->find($id);
    }

    /**
     * Pessimistic write reload. Requires an open transaction.
     */
    public function findOneByIdForUpdate(Uuid $id): ?PersonalInvitation
    {
        $query = $this->createQueryBuilder('i')
            ->andWhere('i.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery();
        $query->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
        $query->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true);

        $result = $query->getOneOrNullResult();

        return $result instanceof PersonalInvitation ? $result : null;
    }

    public function findOneByCodeDigest(string $codeDigest): ?PersonalInvitation
    {
        return $this->findOneBy(['codeDigest' => $codeDigest]);
    }

    public function save(PersonalInvitation $invitation, bool $flush = true): void
    {
        $this->getEntityManager()->persist($invitation);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
