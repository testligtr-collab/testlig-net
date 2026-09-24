<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
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

    public function findOneByNormalizedPhone(string $normalizedPhone): ?User
    {
        return $this->findOneBy(['normalizedPhone' => $normalizedPhone]);
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
     * Re-load a user under pessimistic write lock. Requires an open transaction.
     */
    public function findOneByIdForUpdate(Uuid $id): ?User
    {
        $query = $this->createQueryBuilder('u')
            ->andWhere('u.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);

        $result = $query->getOneOrNullResult();

        return $result instanceof User ? $result : null;
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

    /**
     * First active + email-verified SuperAdmin (ops import actor). No PII returned beyond User entity.
     */
    public function findOneActiveVerifiedSuperAdmin(): ?User
    {
        $roleJson = json_encode(UserRole::SuperAdmin->value, \JSON_THROW_ON_ERROR);
        $idBinary = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT id FROM users
              WHERE status = :status
                AND email_verified_at IS NOT NULL
                AND JSON_CONTAINS(global_roles, :role, \'$\') = 1
              ORDER BY created_at ASC
              LIMIT 1',
            [
                'status' => UserStatus::Active->value,
                'role' => $roleJson,
            ],
        );
        if (false === $idBinary || null === $idBinary) {
            return null;
        }

        $id = Uuid::fromBinary((string) $idBinary);

        return $this->findOneById($id);
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
