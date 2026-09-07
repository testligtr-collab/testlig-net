<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByNormalizedEmail(string $normalizedEmail): ?User
    {
        return $this->findOneBy(['normalizedEmail' => $normalizedEmail]);
    }

    public function existsWithNormalizedEmail(string $normalizedEmail): bool
    {
        return null !== $this->findOneByNormalizedEmail($normalizedEmail);
    }

    public function findOneById(Uuid $id): ?User
    {
        return $this->find($id);
    }

    public function save(User $user, bool $flush = true): void
    {
        $this->getEntityManager()->persist($user);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
