<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminPagedResult;
use App\Dto\AdminUserDetailView;
use App\Dto\AdminUserListItemView;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\CommerceException;
use App\Security\AdminAuthorization;
use App\Service\FreshUserLoader;
use App\Service\PrivilegedUserActorGuard;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Allowlisted, paginated user queries for the admin identity panel.
 *
 * @phpstan-type UserFilters array{
 *     q?: ?string,
 *     status?: ?string,
 *     role?: ?string,
 *     verified?: ?string,
 *     page?: int,
 *     page_size?: int
 * }
 */
final class AdminUserQuery
{
    private const ALLOWED_FILTER_KEYS = [
        'q',
        'status',
        'role',
        'verified',
        'page',
        'page_size',
    ];

    public function __construct(
        private readonly AdminActorGuard $actorGuard,
        private readonly AdminAuthorization $adminAuthorization,
        private readonly PrivilegedUserActorGuard $privilegedGuard,
        private readonly FreshUserLoader $freshUsers,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed> $rawFilters
     *
     * @return AdminPagedResult<AdminUserListItemView>
     */
    public function listUsers(Uuid $actorId, array $rawFilters = []): AdminPagedResult
    {
        $this->actorGuard->requireUsersView($actorId);
        $filters = $this->normalizeFilters($rawFilters);
        $page = AdminPagination::normalizePage((int) ($filters['page'] ?? 1));
        $pageSize = AdminPagination::normalizePageSize((int) ($filters['page_size'] ?? AdminPagination::DEFAULT_PAGE_SIZE));

        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u');

        if (isset($filters['q']) && \is_string($filters['q']) && '' !== $filters['q']) {
            try {
                $term = AdminLikeEscape::normalizeSearch($filters['q']);
            } catch (\InvalidArgumentException $e) {
                throw CommerceException::invalidInput('Search query is too long.');
            }
            if (null !== $term) {
                $pattern = AdminLikeEscape::containsPattern($term);
                $qb->andWhere(
                    "(u.firstName LIKE :q ESCAPE '!' OR u.lastName LIKE :q ESCAPE '!' OR u.email LIKE :q ESCAPE '!' OR u.normalizedEmail LIKE :q ESCAPE '!')",
                )->setParameter('q', $pattern);
            }
        }

        if (isset($filters['status']) && \is_string($filters['status']) && '' !== $filters['status']) {
            $status = UserStatus::tryFrom($filters['status']);
            if (!$status instanceof UserStatus) {
                throw CommerceException::invalidInput('Invalid user status filter.');
            }
            $qb->andWhere('u.status = :status')->setParameter('status', $status);
        }

        if (isset($filters['role']) && \is_string($filters['role']) && '' !== $filters['role']) {
            $role = UserRole::tryFrom($filters['role']);
            if (!$role instanceof UserRole) {
                throw CommerceException::invalidInput('Invalid user role filter.');
            }
            if (UserRole::User === $role) {
                // ROLE_USER is implicit; match users with empty stored global_roles.
                $qb->andWhere('u.globalRoles = :emptyRoles')->setParameter('emptyRoles', []);
            } else {
                $roleJson = json_encode($role->value, \JSON_THROW_ON_ERROR);
                $qb->andWhere('JSON_CONTAINS(u.globalRoles, :roleJson) = 1')
                    ->setParameter('roleJson', $roleJson);
            }
        }

        if (\array_key_exists('verified', $filters) && null !== $filters['verified'] && '' !== $filters['verified']) {
            $verified = $this->parseBoolFilter($filters['verified']);
            if ($verified) {
                $qb->andWhere('u.emailVerifiedAt IS NOT NULL');
            } else {
                $qb->andWhere('u.emailVerifiedAt IS NULL');
            }
        }

        $countQb = clone $qb;
        $total = (int) $countQb
            ->resetDQLPart('select')
            ->resetDQLPart('orderBy')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<User> $rows */
        $rows = $qb
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'ASC')
            ->setFirstResult(AdminPagination::offset($page, $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($rows as $user) {
            $items[] = $this->toListItem($user);
        }

        return new AdminPagedResult($items, $page, $pageSize, $total);
    }

    public function getDetail(Uuid $actorId, Uuid $userId): AdminUserDetailView
    {
        $actor = $this->actorGuard->requireUsersView($actorId);

        return $this->em->wrapInTransaction(function () use ($actor, $actorId, $userId): AdminUserDetailView {
            $target = $this->freshUsers->findFreshLockedUser($userId, LockMode::PESSIMISTIC_READ);
            if (!$target instanceof User) {
                throw CommerceException::notFound();
            }

            $canManageGate = $this->adminAuthorization->canManageUsers($actor);
            $isSelf = $actorId->equals($userId);
            $isProtectedSa = $this->privilegedGuard->isSuperAdmin($target);
            $plainAdminBlocked = $this->privilegedGuard->isPlainAdmin($actor)
                && $this->privilegedGuard->isAdmin($target);

            $canMutate = $canManageGate && !$isSelf && !$isProtectedSa && !$plainAdminBlocked;

            $assignable = [];
            if ($canMutate) {
                foreach (UserRole::cases() as $role) {
                    if (UserRole::User === $role || UserRole::SuperAdmin === $role) {
                        continue;
                    }
                    if ($this->privilegedGuard->isPlainAdmin($actor) && UserRole::Admin === $role) {
                        continue;
                    }
                    $assignable[] = $role->value;
                }
            }

            return new AdminUserDetailView(
                id: $target->getId(),
                shortRef: AdminShortRef::fromUuid($target->getId()),
                firstName: $target->getFirstName(),
                lastName: $target->getLastName(),
                email: $target->getEmail(),
                status: $target->getStatus(),
                globalRoles: $target->getRoles(),
                emailVerified: null !== $target->getEmailVerifiedAt(),
                createdAt: $target->getCreatedAt(),
                canManageRoles: $canMutate,
                canManageStatus: $canMutate,
                allowedStatusTargets: $canMutate ? $target->getStatus()->allowedTransitions() : [],
                assignableRoleValues: $assignable,
                isProtectedSuperAdmin: $isProtectedSa,
                isSelf: $isSelf,
            );
        });
    }

    private function toListItem(User $user): AdminUserListItemView
    {
        return new AdminUserListItemView(
            id: $user->getId(),
            shortRef: AdminShortRef::fromUuid($user->getId()),
            firstName: $user->getFirstName(),
            lastName: $user->getLastName(),
            email: $user->getEmail(),
            status: $user->getStatus(),
            globalRoles: $user->getRoles(),
            emailVerified: null !== $user->getEmailVerifiedAt(),
            createdAt: $user->getCreatedAt(),
        );
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            if (!\in_array($key, self::ALLOWED_FILTER_KEYS, true)) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function parseBoolFilter(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (!\is_string($value) && !\is_int($value)) {
            throw CommerceException::invalidInput('Invalid verified filter.');
        }
        $normalized = strtolower(trim((string) $value));
        if (\in_array($normalized, ['1', 'true', 'yes'], true)) {
            return true;
        }
        if (\in_array($normalized, ['0', 'false', 'no'], true)) {
            return false;
        }

        throw CommerceException::invalidInput('Invalid verified filter.');
    }
}
