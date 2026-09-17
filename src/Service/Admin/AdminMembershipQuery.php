<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminMembershipListItemView;
use App\Dto\AdminPagedResult;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Exception\CommerceException;
use App\Security\AdminAuthorization;
use App\Service\InstitutionalFreshEntityLoader;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Membership listings for a single institution (admin panel).
 */
final class AdminMembershipQuery
{
    private const ALLOWED_FILTER_KEYS = [
        'q',
        'role',
        'status',
        'page',
        'page_size',
    ];

    public function __construct(
        private readonly AdminActorGuard $actorGuard,
        private readonly AdminAuthorization $adminAuthorization,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed> $rawFilters
     *
     * @return array{institution: Institution, result: AdminPagedResult<AdminMembershipListItemView>}
     */
    public function listForInstitution(Uuid $actorId, Uuid $institutionId, array $rawFilters = []): array
    {
        $actor = $this->actorGuard->requireMembershipsView($actorId);
        $canManage = $this->adminAuthorization->canManageMemberships($actor);

        $institution = $this->em->wrapInTransaction(function () use ($institutionId): Institution {
            $locked = $this->freshEntities->findFreshLockedInstitution(
                $institutionId,
                LockMode::PESSIMISTIC_READ,
            );
            if (!$locked instanceof Institution) {
                throw CommerceException::notFound();
            }

            return $locked;
        });

        $filters = $this->normalizeFilters($rawFilters);
        $page = AdminPagination::normalizePage((int) ($filters['page'] ?? 1));
        $pageSize = AdminPagination::normalizePageSize((int) ($filters['page_size'] ?? AdminPagination::DEFAULT_PAGE_SIZE));

        $qb = $this->em->createQueryBuilder()
            ->select('m', 'u')
            ->from(InstitutionMembership::class, 'm')
            ->innerJoin('m.user', 'u')
            ->andWhere('IDENTITY(m.institution) = :institutionId')
            ->setParameter('institutionId', $institutionId, 'uuid');

        if (isset($filters['q']) && \is_string($filters['q']) && '' !== $filters['q']) {
            try {
                $term = AdminLikeEscape::normalizeSearch($filters['q']);
            } catch (\InvalidArgumentException) {
                throw CommerceException::invalidInput('Search query is too long.');
            }
            if (null !== $term) {
                $pattern = AdminLikeEscape::containsPattern($term);
                $qb->andWhere(
                    "(u.firstName LIKE :q ESCAPE '!' OR u.lastName LIKE :q ESCAPE '!' OR u.email LIKE :q ESCAPE '!')",
                )->setParameter('q', $pattern);
            }
        }

        if (isset($filters['role']) && \is_string($filters['role']) && '' !== $filters['role']) {
            $role = InstitutionMembershipRole::tryFrom($filters['role']);
            if (!$role instanceof InstitutionMembershipRole) {
                throw CommerceException::invalidInput('Invalid membership role filter.');
            }
            $qb->andWhere('m.role = :role')->setParameter('role', $role);
        }

        if (isset($filters['status']) && \is_string($filters['status']) && '' !== $filters['status']) {
            $status = InstitutionMembershipStatus::tryFrom($filters['status']);
            if (!$status instanceof InstitutionMembershipStatus) {
                throw CommerceException::invalidInput('Invalid membership status filter.');
            }
            $qb->andWhere('m.status = :status')->setParameter('status', $status);
        }

        $countQb = clone $qb;
        $total = (int) $countQb
            ->resetDQLPart('select')
            ->resetDQLPart('orderBy')
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<InstitutionMembership> $rows */
        $rows = $qb
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'ASC')
            ->setFirstResult(AdminPagination::offset($page, $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $institutionActive = InstitutionStatus::Active === $institution->getStatus();
        $items = [];
        foreach ($rows as $membership) {
            $user = $membership->getUser();
            $status = $membership->getStatus();
            $role = $membership->getRole();
            $manage = $canManage && $institutionActive;

            $items[] = new AdminMembershipListItemView(
                membershipId: $membership->getId(),
                userId: $user->getId(),
                shortRef: AdminShortRef::fromUuid($membership->getId()),
                firstName: $user->getFirstName(),
                lastName: $user->getLastName(),
                email: $user->getEmail(),
                role: $role,
                status: $status,
                joinedAt: $membership->getJoinedAt(),
                suspendedAt: $membership->getSuspendedAt(),
                endedAt: $membership->getEndedAt(),
                canChangeRole: $manage
                    && InstitutionMembershipStatus::Active === $status
                    && InstitutionMembershipRole::Owner !== $role,
                canSuspend: $manage && InstitutionMembershipStatus::Active === $status,
                canReactivate: $manage && InstitutionMembershipStatus::Suspended === $status,
                canEnd: $manage && \in_array($status, [
                    InstitutionMembershipStatus::Active,
                    InstitutionMembershipStatus::Suspended,
                ], true),
            );
        }

        return [
            'institution' => $institution,
            'result' => new AdminPagedResult($items, $page, $pageSize, $total),
        ];
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
}
