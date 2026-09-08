<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

/**
 * Loads User rows with an explicit identity-map bypass.
 *
 * Guarantee: never trusts in-memory managed state. Always re-hydrates from the
 * database via DQL + {@see Query::HINT_REFRESH}, optionally under a pessimistic lock.
 *
 * Callers that lock multiple users must pass ids once; locks are applied in UUID
 * ascending order with a single lock per distinct user.
 */
final class FreshUserLoader
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
    public function findFreshLockedUsers(array $ids, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): array
    {
        $unique = [];
        foreach ($ids as $id) {
            $unique[$id->toRfc4122()] = $id;
        }
        ksort($unique);

        $locked = [];
        foreach ($unique as $key => $id) {
            $user = $this->findFreshLockedUser($id, $lockMode);
            if ($user instanceof User) {
                $locked[$key] = $user;
            }
        }

        return $locked;
    }

    public function findFreshLockedUser(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?User
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id = :id')
            ->setParameter('id', $id, 'uuid');

        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode($lockMode);

        $result = $query->getOneOrNullResult();

        return $result instanceof User ? $result : null;
    }
}
