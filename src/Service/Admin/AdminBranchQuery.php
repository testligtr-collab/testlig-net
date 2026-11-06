<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminBranchDetailView;
use App\Dto\AdminBranchInstitutionChoice;
use App\Dto\AdminBranchListItemView;
use App\Dto\AdminBranchListSummary;
use App\Dto\AdminPagedResult;
use App\Entity\Institution;
use App\Entity\InstitutionBranch;
use App\Enum\InstitutionBranchKind;
use App\Enum\InstitutionBranchStatus;
use App\Enum\InstitutionStatus;
use App\Exception\CommerceException;
use App\Security\AdminAuthorization;
use App\Service\InstitutionalFreshEntityLoader;
use App\Service\InstitutionBranchAccess;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Allowlisted branch queries. Counters ignore the active filters.
 */
final class AdminBranchQuery
{
    private const ALLOWED_FILTER_KEYS = [
        'q',
        'institution',
        'kind',
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
     * @return AdminPagedResult<AdminBranchListItemView>
     */
    public function listBranches(Uuid $actorId, array $rawFilters = []): AdminPagedResult
    {
        $this->actorGuard->requireBranchesView($actorId);
        $filters = $this->normalizeFilters($rawFilters);
        $page = AdminPagination::normalizePage((int) ($filters['page'] ?? 1));
        $pageSize = AdminPagination::normalizePageSize((int) ($filters['page_size'] ?? AdminPagination::DEFAULT_PAGE_SIZE));

        $qb = $this->em->createQueryBuilder()
            ->select('b', 'i')
            ->from(InstitutionBranch::class, 'b')
            ->join('b.institution', 'i');
        $this->applyFilters($qb, $filters);

        $countQb = clone $qb;
        $total = (int) $countQb
            ->resetDQLPart('select')
            ->resetDQLPart('orderBy')
            ->select('COUNT(b.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<InstitutionBranch> $rows */
        $rows = $qb
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('b.id', 'ASC')
            ->setFirstResult(AdminPagination::offset($page, $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($rows as $branch) {
            $institution = $branch->getInstitution();
            $items[] = new AdminBranchListItemView(
                id: $branch->getId()->toRfc4122(),
                name: $branch->getName(),
                code: $branch->getCode(),
                kind: $branch->getKind(),
                status: $branch->getStatus(),
                institutionId: $institution->getId()->toRfc4122(),
                institutionName: $institution->getName(),
                institutionStatus: $institution->getStatus(),
                accessNote: InstitutionBranchAccess::note($branch->getStatus(), $institution->getStatus()),
                accessOpen: InstitutionBranchAccess::isOpen($branch->getStatus(), $institution->getStatus()),
            );
        }

        return new AdminPagedResult($items, $page, $pageSize, $total);
    }

    public function summarize(Uuid $actorId): AdminBranchListSummary
    {
        $this->actorGuard->requireBranchesView($actorId);

        /** @var list<array{status: InstitutionBranchStatus|string, cnt: string|int}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('b.status AS status, COUNT(b.id) AS cnt')
            ->from(InstitutionBranch::class, 'b')
            ->groupBy('b.status')
            ->getQuery()
            ->getArrayResult();

        $total = 0;
        $active = 0;
        $pending = 0;
        foreach ($rows as $row) {
            $count = (int) $row['cnt'];
            $total += $count;
            $status = $row['status'] instanceof InstitutionBranchStatus ? $row['status']->value : (string) $row['status'];
            if (InstitutionBranchStatus::Active->value === $status) {
                $active = $count;
            }
            if (InstitutionBranchStatus::Pending->value === $status) {
                $pending = $count;
            }
        }

        $linked = (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT i.id)')
            ->from(InstitutionBranch::class, 'b')
            ->join('b.institution', 'i')
            ->getQuery()
            ->getSingleScalarResult();

        return new AdminBranchListSummary($total, $active, $pending, $linked);
    }

    public function getDetail(Uuid $actorId, Uuid $branchId): AdminBranchDetailView
    {
        $actor = $this->actorGuard->requireBranchesView($actorId);

        return $this->em->wrapInTransaction(function () use ($actor, $branchId): AdminBranchDetailView {
            $branch = $this->freshEntities->findFreshLockedInstitutionBranch($branchId, LockMode::PESSIMISTIC_READ);
            if (!$branch instanceof InstitutionBranch) {
                throw CommerceException::notFound();
            }
            $institution = $this->freshEntities->findFreshLockedInstitution($branch->getInstitution()->getId(), LockMode::PESSIMISTIC_READ);
            if (!$institution instanceof Institution) {
                throw CommerceException::notFound();
            }

            $targets = $branch->getStatus()->allowedTransitions();
            if (InstitutionStatus::Active !== $institution->getStatus()) {
                $targets = array_values(array_filter(
                    $targets,
                    static fn (InstitutionBranchStatus $status): bool => InstitutionBranchStatus::Active !== $status,
                ));
            }

            return new AdminBranchDetailView(
                id: $branch->getId()->toRfc4122(),
                shortRef: AdminShortRef::fromUuid($branch->getId()),
                name: $branch->getName(),
                code: $branch->getCode(),
                kind: $branch->getKind(),
                status: $branch->getStatus(),
                institutionId: $institution->getId()->toRfc4122(),
                institutionName: $institution->getName(),
                institutionStatus: $institution->getStatus(),
                accessNote: InstitutionBranchAccess::note($branch->getStatus(), $institution->getStatus()),
                accessOpen: InstitutionBranchAccess::isOpen($branch->getStatus(), $institution->getStatus()),
                createdAt: $branch->getCreatedAt(),
                updatedAt: $branch->getUpdatedAt(),
                allowedStatusTargets: $targets,
                canManage: $this->adminAuthorization->canManageBranches($actor),
            );
        });
    }

    /**
     * @return list<AdminBranchInstitutionChoice>
     */
    public function institutionChoices(Uuid $actorId): array
    {
        $this->actorGuard->requireBranchesView($actorId);

        /** @var list<array{id: Uuid|string, name: string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('i.id AS id, i.name AS name')
            ->from(Institution::class, 'i')
            ->orderBy('i.name', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $choices = [];
        foreach ($rows as $row) {
            $id = $row['id'] instanceof Uuid ? $row['id']->toRfc4122() : (string) $row['id'];
            $choices[] = new AdminBranchInstitutionChoice($id, $row['name']);
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $rawFilters
     *
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $rawFilters): array
    {
        $filters = [];
        foreach (self::ALLOWED_FILTER_KEYS as $key) {
            if (\array_key_exists($key, $rawFilters)) {
                $filters[$key] = $rawFilters[$key];
            }
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (isset($filters['q']) && \is_string($filters['q']) && '' !== $filters['q']) {
            try {
                $term = AdminLikeEscape::normalizeSearch($filters['q']);
            } catch (\InvalidArgumentException) {
                throw CommerceException::invalidInput('Search query is too long.');
            }
            if (null !== $term) {
                $pattern = AdminLikeEscape::containsPattern($term);
                $qb->andWhere("(b.name LIKE :q ESCAPE '!' OR b.code LIKE :q ESCAPE '!')")
                    ->setParameter('q', $pattern);
            }
        }

        if (isset($filters['institution']) && \is_string($filters['institution']) && '' !== $filters['institution']) {
            if (!Uuid::isValid($filters['institution'])) {
                throw CommerceException::invalidInput('Invalid institution filter.');
            }
            $qb->andWhere('IDENTITY(b.institution) = :institution')
                ->setParameter('institution', Uuid::fromString($filters['institution']), 'uuid');
        }

        if (isset($filters['kind']) && \is_string($filters['kind']) && '' !== $filters['kind']) {
            $kind = InstitutionBranchKind::tryFrom($filters['kind']);
            if (!$kind instanceof InstitutionBranchKind) {
                throw CommerceException::invalidInput('Invalid branch kind filter.');
            }
            $qb->andWhere('b.kind = :kind')->setParameter('kind', $kind);
        }

        if (isset($filters['status']) && \is_string($filters['status']) && '' !== $filters['status']) {
            $status = InstitutionBranchStatus::tryFrom($filters['status']);
            if (!$status instanceof InstitutionBranchStatus) {
                throw CommerceException::invalidInput('Invalid branch status filter.');
            }
            $qb->andWhere('b.status = :status')->setParameter('status', $status);
        }
    }
}
