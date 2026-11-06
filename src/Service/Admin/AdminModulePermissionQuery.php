<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminModulePermissionAccount;
use App\Dto\AdminModulePermissionDetail;
use App\Dto\AdminPagedResult;
use App\Entity\User;
use App\Enum\ModeratorModuleGrant;
use App\Enum\UserRole;
use App\Exception\CommerceException;
use App\Repository\UserModulePermissionRepository;
use App\Repository\UserModulePermissionStateRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Accounts whose direct module grants a superadmin may edit.
 *
 * A target keeps ROLE_MODERATOR and does not keep ROLE_ADMIN or ROLE_SUPER_ADMIN.
 */
final class AdminModulePermissionQuery
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly UserModulePermissionRepository $grants,
        private readonly UserModulePermissionStateRepository $states,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return AdminPagedResult<AdminModulePermissionAccount>
     */
    public function list(array $filters): AdminPagedResult
    {
        $page = AdminPagination::normalizePage((int) ($filters['page'] ?? 1));
        $pageSize = AdminPagination::normalizePageSize((int) ($filters['page_size'] ?? AdminPagination::DEFAULT_PAGE_SIZE));
        $qb = $this->eligibleQuery();
        $this->applySearch($qb, $filters);
        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
        /** @var list<User> $users */
        $users = $qb
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->addOrderBy('u.email', 'ASC')
            ->setFirstResult(AdminPagination::offset($page, $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();
        $counts = $this->grantCounts($users);
        $items = [];
        foreach ($users as $user) {
            $items[] = new AdminModulePermissionAccount(
                id: $user->getId()->toRfc4122(),
                fullName: trim($user->getFirstName().' '.$user->getLastName()),
                email: $user->getEmail(),
                status: $user->getStatus()->value,
                roleLabel: $this->roleLabel($user),
                directGrantCount: $counts[$user->getId()->toRfc4122()] ?? 0,
            );
        }

        return new AdminPagedResult($items, $page, $pageSize, $total);
    }

    public function detail(Uuid $id): ?AdminModulePermissionDetail
    {
        $user = $this->users->findOneById($id);
        if (!$user instanceof User || !$this->isEligible($user)) {
            return null;
        }
        $codes = [];
        foreach ($this->grants->codesFor($user->getId()) as $code) {
            if (null !== ModeratorModuleGrant::tryFrom($code)) {
                $codes[] = $code;
            }
        }
        sort($codes);

        return new AdminModulePermissionDetail(
            id: $user->getId()->toRfc4122(),
            fullName: trim($user->getFirstName().' '.$user->getLastName()),
            email: $user->getEmail(),
            status: $user->getStatus()->value,
            appliesNow: 'active' === $user->getStatus()->value && null !== $user->getEmailVerifiedAt(),
            roleLabel: $this->roleLabel($user),
            grantCodes: $codes,
            version: $this->states->versionFor($user->getId()),
        );
    }

    private function eligibleQuery(): \Doctrine\ORM\QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->andWhere('u.globalRoles LIKE :moderatorRole')
            ->andWhere('u.globalRoles NOT LIKE :adminRole')
            ->andWhere('u.globalRoles NOT LIKE :superAdminRole')
            ->setParameter('moderatorRole', '%"'.UserRole::Moderator->value.'"%')
            ->setParameter('adminRole', '%"'.UserRole::Admin->value.'"%')
            ->setParameter('superAdminRole', '%"'.UserRole::SuperAdmin->value.'"%');
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applySearch(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (!isset($filters['q']) || !\is_string($filters['q']) || '' === $filters['q']) {
            return;
        }
        try {
            $term = AdminLikeEscape::normalizeSearch($filters['q']);
        } catch (\InvalidArgumentException) {
            throw CommerceException::invalidInput('Search query is too long.');
        }
        if (null === $term) {
            return;
        }
        $qb->andWhere(
            "(u.firstName LIKE :q ESCAPE '!' OR u.lastName LIKE :q ESCAPE '!' OR u.email LIKE :q ESCAPE '!' OR u.normalizedEmail LIKE :q ESCAPE '!')",
        )->setParameter('q', AdminLikeEscape::containsPattern($term));
    }

    private function isEligible(User $user): bool
    {
        $values = [];
        foreach ($user->getGlobalRoleEnums() as $role) {
            $values[] = $role->value;
        }

        return \in_array(UserRole::Moderator->value, $values, true)
            && !\in_array(UserRole::Admin->value, $values, true)
            && !\in_array(UserRole::SuperAdmin->value, $values, true);
    }

    private function roleLabel(User $user): string
    {
        $labels = [];
        foreach ($user->getGlobalRoleEnums() as $role) {
            if (UserRole::User === $role) {
                continue;
            }
            $labels[] = self::labelForRole($role);
        }

        return [] === $labels ? 'Moderatör' : implode(', ', $labels);
    }

    public static function labelForRole(UserRole $role): string
    {
        return match ($role) {
            UserRole::Student => 'Öğrenci',
            UserRole::Parent => 'Veli',
            UserRole::Teacher => 'Öğretmen',
            UserRole::ExpertTeacher => 'Uzman öğretmen',
            UserRole::HeadTeacher => 'Zümre başkanı',
            UserRole::InstitutionManager => 'Kurum yöneticisi',
            UserRole::Moderator => 'Moderatör',
            UserRole::Admin => 'Yönetici',
            UserRole::SuperAdmin => 'Süper Yönetici',
            UserRole::User => 'Kullanıcı',
        };
    }

    /**
     * @param list<User> $users
     *
     * @return array<string, int>
     */
    private function grantCounts(array $users): array
    {
        if ([] === $users) {
            return [];
        }
        $placeholders = [];
        $params = [];
        $types = [];
        foreach ($users as $index => $user) {
            $name = 'user'.$index;
            $placeholders[] = ':'.$name;
            $params[$name] = $user->getId()->toBinary();
            $types[$name] = ParameterType::BINARY;
        }
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT user_id, permission_code FROM user_module_permissions WHERE user_id IN ('.implode(', ', $placeholders).')',
            $params,
            $types,
        );
        $counts = [];
        foreach ($rows as $row) {
            $code = $row['permission_code'] ?? null;
            $rawId = $row['user_id'] ?? null;
            if (!\is_string($code) || null === ModeratorModuleGrant::tryFrom($code) || !\is_string($rawId) || 16 !== \strlen($rawId)) {
                continue;
            }
            $key = Uuid::fromBinary($rawId)->toRfc4122();
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }
}
