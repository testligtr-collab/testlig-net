<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

/**
 * Loads institution-domain entities with an explicit identity-map bypass.
 *
 * Guarantee: never trusts the in-memory managed state. Always re-hydrates from the
 * database via DQL + {@see Query::HINT_REFRESH}, optionally under a pessimistic lock.
 *
 * Lock order for callers that mutate: Institution → Users (UUID asc) → Membership.
 */
final class InstitutionalFreshEntityLoader
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
    public function findFreshLockedUsers(array $ids, LockMode $lockMode = LockMode::PESSIMISTIC_READ): array
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

    public function findFreshLockedUser(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_READ): ?User
    {
        $entity = $this->findFresh(User::class, $id, $lockMode);

        return $entity instanceof User ? $entity : null;
    }

    public function findFreshLockedInstitution(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?Institution
    {
        $entity = $this->findFresh(Institution::class, $id, $lockMode);

        return $entity instanceof Institution ? $entity : null;
    }

    public function findFreshLockedMembership(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?InstitutionMembership
    {
        $entity = $this->findFresh(InstitutionMembership::class, $id, $lockMode);

        return $entity instanceof InstitutionMembership ? $entity : null;
    }

    /**
     * Fresh membership for (user, institution). Uses HINT_REFRESH; optional lock.
     * Call only after the institution row is already locked when used in mutations.
     */
    public function findFreshMembershipForUser(
        Uuid $userId,
        Uuid $institutionId,
        ?LockMode $lockMode = null,
    ): ?InstitutionMembership {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(InstitutionMembership::class, 'm')
            ->where('m.user = :userId')
            ->andWhere('m.institution = :institutionId')
            ->setParameter('userId', $userId, 'uuid')
            ->setParameter('institutionId', $institutionId, 'uuid');

        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        if (null !== $lockMode) {
            $query->setLockMode($lockMode);
        }

        $result = $query->getOneOrNullResult();

        return $result instanceof InstitutionMembership ? $result : null;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function findFresh(string $class, Uuid $id, ?LockMode $lockMode): ?object
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($class, 'e')
            ->where('e.id = :id')
            ->setParameter('id', $id, 'uuid');

        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        if (null !== $lockMode) {
            $query->setLockMode($lockMode);
        }

        /** @var T|null $result */
        $result = $query->getOneOrNullResult();

        return $result;
    }
}
