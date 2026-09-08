<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
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

    /**
     * Parameterized MariaDB JSON_CONTAINS check — does not load all users into memory.
     */
    public function existsWithSuperAdminRole(): bool
    {
        $connection = $this->getEntityManager()->getConnection();
        $roleJson = json_encode(UserRole::SuperAdmin->value, \JSON_THROW_ON_ERROR);
        $result = $connection->fetchOne(
            'SELECT 1 FROM users WHERE JSON_CONTAINS(global_roles, :role, \'$\') = 1 LIMIT 1',
            ['role' => $roleJson],
        );

        return false !== $result && null !== $result;
    }

    public function save(User $user, bool $flush = true): void
    {
        $this->getEntityManager()->persist($user);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}
