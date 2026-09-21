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
