<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminAcademicYearDetailView;
use App\Dto\AdminAcademicYearInstitutionChoice;
use App\Dto\AdminAcademicYearListItemView;
use App\Dto\AdminAcademicYearListSummary;
use App\Dto\AdminPagedResult;
use App\Entity\AcademicYear;
use App\Entity\Institution;
use App\Enum\AcademicYearStatus;
use App\Enum\InstitutionStatus;
use App\Exception\CommerceException;
use App\Presentation\AcademicYearLabels;
use App\Security\AdminAuthorization;
use App\Service\InstitutionalFreshEntityLoader;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Allowlisted academic-year queries. Counters ignore the active filters.
 */
final class AdminAcademicYearQuery
{
    private const ALLOWED_FILTER_KEYS = [
        'q',
        'institution',
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
     * @return AdminPagedResult<AdminAcademicYearListItemView>
     */
    public function listYears(Uuid $actorId, array $rawFilters = []): AdminPagedResult
    {
        $this->actorGuard->requireAcademicYearsView($actorId);
        $filters = $this->normalizeFilters($rawFilters);
        $page = AdminPagination::normalizePage((int) ($filters['page'] ?? 1));
        $pageSize = AdminPagination::normalizePageSize((int) ($filters['page_size'] ?? AdminPagination::DEFAULT_PAGE_SIZE));

        $qb = $this->em->createQueryBuilder()
            ->select('y', 'i')
            ->from(AcademicYear::class, 'y')
            ->join('y.institution', 'i');
        $this->applyFilters($qb, $filters);

        $countQb = clone $qb;
        $total = (int) $countQb
            ->resetDQLPart('select')
            ->resetDQLPart('orderBy')
            ->select('COUNT(y.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<AcademicYear> $rows */
        $rows = $qb
            ->orderBy('y.startsAt', 'DESC')
            ->addOrderBy('y.id', 'ASC')
            ->setFirstResult(AdminPagination::offset($page, $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($rows as $year) {
            $institution = $year->getInstitution();
            $items[] = new AdminAcademicYearListItemView(
                id: $year->getId()->toRfc4122(),
                name: $year->getName(),
                status: $year->getStatus(),
                rangeLabel: AcademicYearLabels::range($year->getStartsAt(), $year->getEndsAt()),
                institutionId: $institution->getId()->toRfc4122(),
                institutionName: $institution->getName(),
            );
        }

        return new AdminPagedResult($items, $page, $pageSize, $total);
    }

    public function summarize(Uuid $actorId): AdminAcademicYearListSummary
    {
        $this->actorGuard->requireAcademicYearsView($actorId);

        /** @var list<array{status: AcademicYearStatus|string, cnt: string|int}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('y.status AS status, COUNT(y.id) AS cnt')
            ->from(AcademicYear::class, 'y')
            ->groupBy('y.status')
            ->getQuery()
            ->getArrayResult();

        $total = 0;
        $active = 0;
        foreach ($rows as $row) {
            $count = (int) $row['cnt'];
            $total += $count;
            $status = $row['status'] instanceof AcademicYearStatus ? $row['status']->value : (string) $row['status'];
            if (AcademicYearStatus::Active->value === $status) {
                $active = $count;
            }
        }

        $linked = (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT i.id)')
            ->from(AcademicYear::class, 'y')
            ->join('y.institution', 'i')
            ->getQuery()
            ->getSingleScalarResult();

        $withoutActiveYear = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM institutions i
             WHERE i.status = ?
               AND NOT EXISTS (
                   SELECT 1 FROM academic_years y
                   WHERE y.institution_id = i.id AND y.status = ?
               )',
            [InstitutionStatus::Active->value, AcademicYearStatus::Active->value],
        );

        return new AdminAcademicYearListSummary($total, $active, $withoutActiveYear, $linked);
    }

    public function getDetail(Uuid $actorId, Uuid $yearId): AdminAcademicYearDetailView
    {
        $actor = $this->actorGuard->requireAcademicYearsView($actorId);

        return $this->em->wrapInTransaction(function () use ($actor, $yearId): AdminAcademicYearDetailView {
            $year = $this->freshEntities->findFreshLockedAcademicYear($yearId, LockMode::PESSIMISTIC_READ);
            if (!$year instanceof AcademicYear) {
                throw CommerceException::notFound();
            }
            $institution = $this->freshEntities->findFreshLockedInstitution($year->getInstitution()->getId(), LockMode::PESSIMISTIC_READ);
            if (!$institution instanceof Institution) {
                throw CommerceException::notFound();
            }

            $active = $this->em->createQueryBuilder()
                ->select('y')
                ->from(AcademicYear::class, 'y')
                ->andWhere('IDENTITY(y.institution) = :institution')
                ->andWhere('y.status = :active')
                ->setParameter('institution', $institution->getId(), 'uuid')
                ->setParameter('active', AcademicYearStatus::Active)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            $activeYear = $active instanceof AcademicYear ? $active : null;
            $canActivate = AcademicYearStatus::Planned === $year->getStatus()
                && InstitutionStatus::Active === $institution->getStatus()
                && $this->adminAuthorization->canManageAcademicYears($actor);

            return new AdminAcademicYearDetailView(
                id: $year->getId()->toRfc4122(),
                shortRef: AdminShortRef::fromUuid($year->getId()),
                name: $year->getName(),
                status: $year->getStatus(),
                rangeLabel: AcademicYearLabels::range($year->getStartsAt(), $year->getEndsAt()),
                institutionId: $institution->getId()->toRfc4122(),
                institutionName: $institution->getName(),
                institutionStatus: $institution->getStatus(),
                activeYearId: $activeYear?->getId()->toRfc4122(),
                activeYearName: $activeYear?->getName(),
                canActivate: $canActivate,
            );
        });
    }

    /**
     * @return list<AdminAcademicYearInstitutionChoice>
     */
    public function institutionChoices(Uuid $actorId): array
    {
        $this->actorGuard->requireAcademicYearsView($actorId);

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
            $choices[] = new AdminAcademicYearInstitutionChoice($id, $row['name']);
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
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (isset($filters['q']) && \is_string($filters['q']) && '' !== $filters['q']) {
            try {
                $term = AdminLikeEscape::normalizeSearch($filters['q']);
            } catch (\InvalidArgumentException) {
                throw CommerceException::invalidInput('Search query is too long.');
            }
            if (null !== $term) {
                $pattern = AdminLikeEscape::containsPattern($term);
                $qb->andWhere("y.name LIKE :q ESCAPE '!'")
                    ->setParameter('q', $pattern);
            }
        }

        if (isset($filters['institution']) && \is_string($filters['institution']) && '' !== $filters['institution']) {
            if (!Uuid::isValid($filters['institution'])) {
                throw CommerceException::invalidInput('Invalid institution filter.');
            }
            $qb->andWhere('IDENTITY(y.institution) = :institution')
                ->setParameter('institution', Uuid::fromString($filters['institution']), 'uuid');
        }

        if (isset($filters['status']) && \is_string($filters['status']) && '' !== $filters['status']) {
            $status = AcademicYearStatus::tryFrom($filters['status']);
            if (!$status instanceof AcademicYearStatus) {
                throw CommerceException::invalidInput('Invalid academic year status filter.');
            }
            $qb->andWhere('y.status = :status')->setParameter('status', $status);
        }
    }
}
