<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Reloads users from the database under a consistent lock order (UUID ascending).
 *
 * Institution write locks must be taken by callers before user locks when an institution row is involved.
 */
final class InstitutionalUserReloader
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, User> keyed by RFC4122; missing users are omitted
     */
    public function lockExistingByIds(array $ids, LockMode $lockMode = LockMode::PESSIMISTIC_READ): array
    {
        $unique = [];
        foreach ($ids as $id) {
            $unique[$id->toRfc4122()] = $id;
        }
        ksort($unique);

        $locked = [];
        foreach ($unique as $key => $id) {
            $user = $this->entityManager->find(User::class, $id, $lockMode);
            if ($user instanceof User) {
                $locked[$key] = $user;
            }
        }

        return $locked;
    }

    public function lockOne(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_READ): ?User
    {
        $user = $this->entityManager->find(User::class, $id, $lockMode);

        return $user instanceof User ? $user : null;
    }
}
