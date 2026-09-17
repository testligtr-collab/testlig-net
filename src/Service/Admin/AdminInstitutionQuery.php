<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminInstitutionDetailView;
use App\Dto\AdminInstitutionListItemView;
use App\Dto\AdminPagedResult;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\InstitutionType;
use App\Exception\CommerceException;
use App\Security\AdminAuthorization;
use App\Service\InstitutionalFreshEntityLoader;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Allowlisted institution queries for the admin panel.
 */
final class AdminInstitutionQuery
{
    private const ALLOWED_FILTER_KEYS = [
        'q',
        'type',
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
     * @return AdminPagedResult<AdminInstitutionListItemView>
     */
    public function listInstitutions(Uuid $actorId, array $rawFilters = []): AdminPagedResult
    {
        $this->actorGuard->requireInstitutionsView($actorId);
        $filters = $this->normalizeFilters($rawFilters);
        $page = AdminPagination::normalizePage((int) ($filters['page'] ?? 1));
        $pageSize = AdminPagination::normalizePageSize((int) ($filters['page_size'] ?? AdminPagination::DEFAULT_PAGE_SIZE));

        $qb = $this->em->createQueryBuilder()
            ->select('i')
            ->from(Institution::class, 'i');

        if (isset($filters['q']) && \is_string($filters['q']) && '' !== $filters['q']) {
            try {
                $term = AdminLikeEscape::normalizeSearch($filters['q']);
            } catch (\InvalidArgumentException) {
                throw CommerceException::invalidInput('Search query is too long.');
            }
            if (null !== $term) {
                $pattern = AdminLikeEscape::containsPattern($term);
                $qb->andWhere("(i.name LIKE :q ESCAPE '!' OR i.slug LIKE :q ESCAPE '!')")
                    ->setParameter('q', $pattern);
            }
        }

        if (isset($filters['type']) && \is_string($filters['type']) && '' !== $filters['type']) {
            $type = InstitutionType::tryFrom($filters['type']);
            if (!$type instanceof InstitutionType) {
                throw CommerceException::invalidInput('Invalid institution type filter.');
            }
            $qb->andWhere('i.type = :type')->setParameter('type', $type);
        }

        if (isset($filters['status']) && \is_string($filters['status']) && '' !== $filters['status']) {
            $status = InstitutionStatus::tryFrom($filters['status']);
            if (!$status instanceof InstitutionStatus) {
                throw CommerceException::invalidInput('Invalid institution status filter.');
            }
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }

        $countQb = clone $qb;
        $total = (int) $countQb
            ->resetDQLPart('select')
            ->resetDQLPart('orderBy')
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<Institution> $rows */
        $rows = $qb
            ->orderBy('i.createdAt', 'DESC')
            ->addOrderBy('i.id', 'ASC')
            ->setFirstResult(AdminPagination::offset($page, $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $counts = $this->loadActiveMemberCounts(array_map(
            static fn (Institution $i): Uuid => $i->getId(),
            $rows,
        ));

        $items = [];
        foreach ($rows as $institution) {
            $id = $institution->getId();
            $items[] = new AdminInstitutionListItemView(
                id: $id,
                shortRef: AdminShortRef::fromUuid($id),
                name: $institution->getName(),
                slug: $institution->getSlug(),
                type: $institution->getType(),
                status: $institution->getStatus(),
                locale: $institution->getLocale(),
                timezone: $institution->getTimezone(),
                activeMemberCount: $counts[$id->toRfc4122()] ?? 0,
                createdAt: $institution->getCreatedAt(),
            );
        }

        return new AdminPagedResult($items, $page, $pageSize, $total);
    }

    public function getDetail(Uuid $actorId, Uuid $institutionId): AdminInstitutionDetailView
    {
        $actor = $this->actorGuard->requireInstitutionsView($actorId);

        return $this->em->wrapInTransaction(function () use ($actor, $institutionId): AdminInstitutionDetailView {
            $institution = $this->freshEntities->findFreshLockedInstitution(
                $institutionId,
                LockMode::PESSIMISTIC_READ,
            );
            if (!$institution instanceof Institution) {
                throw CommerceException::notFound();
            }

            $activeMembers = $this->countActiveMembers($institutionId);
            $activeOwners = $this->countActiveOwners($institutionId);
            $canManage = $this->adminAuthorization->canManageInstitutions($actor);
            $canViewMemberships = $this->adminAuthorization->canViewMemberships($actor);

            return new AdminInstitutionDetailView(
                id: $institution->getId(),
                shortRef: AdminShortRef::fromUuid($institution->getId()),
                name: $institution->getName(),
                slug: $institution->getSlug(),
                type: $institution->getType(),
                status: $institution->getStatus(),
                locale: $institution->getLocale(),
                timezone: $institution->getTimezone(),
                activeMemberCount: $activeMembers,
                activeOwnerCount: $activeOwners,
                createdAt: $institution->getCreatedAt(),
                updatedAt: $institution->getUpdatedAt(),
                canManageStatus: $canManage,
                canViewMemberships: $canViewMemberships,
                allowedStatusTargets: $canManage ? $institution->getStatus()->allowedTransitions() : [],
            );
        });
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, int>
     */
    private function loadActiveMemberCounts(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{institutionId: Uuid, cnt: string|int}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(m.institution) AS institutionId, COUNT(m.id) AS cnt')
            ->from(InstitutionMembership::class, 'm')
            ->andWhere('m.institution IN (:ids)')
            ->andWhere('m.status = :active')
            ->setParameter('ids', $ids)
            ->setParameter('active', InstitutionMembershipStatus::Active)
            ->groupBy('m.institution')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $id = $row['institutionId'];
            $map[$id->toRfc4122()] = (int) $row['cnt'];
        }

        return $map;
    }

    private function countActiveMembers(Uuid $institutionId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(InstitutionMembership::class, 'm')
            ->andWhere('IDENTITY(m.institution) = :id')
            ->andWhere('m.status = :active')
            ->setParameter('id', $institutionId)
            ->setParameter('active', InstitutionMembershipStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countActiveOwners(Uuid $institutionId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(InstitutionMembership::class, 'm')
            ->andWhere('IDENTITY(m.institution) = :id')
            ->andWhere('m.role = :role')
            ->andWhere('m.status = :active')
            ->setParameter('id', $institutionId)
            ->setParameter('role', InstitutionMembershipRole::Owner)
            ->setParameter('active', InstitutionMembershipStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();
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
