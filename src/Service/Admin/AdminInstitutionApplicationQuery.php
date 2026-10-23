<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminInstitutionApplicationList;
use App\Dto\AdminInstitutionApplicationRow;
use App\Dto\AdminInstitutionApplicationSummary;
use App\Dto\AdminPagedResult;
use App\Entity\InstitutionApplication;
use App\Enum\OnboardingApplicationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Read model for the SuperAdmin institution-application queue.
 *
 * Counts are unfiltered database totals. Search and status narrow only the page.
 */
final class AdminInstitutionApplicationQuery
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function summary(): AdminInstitutionApplicationSummary
    {
        $row = $this->em->getConnection()->fetchAssociative(<<<'SQL'
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
                   COALESCE(SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END), 0) AS approved_count,
                   COALESCE(SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END), 0) AS rejected_count,
                   COALESCE(SUM(CASE WHEN status = 'withdrawn' THEN 1 ELSE 0 END), 0) AS withdrawn_count,
                   COALESCE(SUM(CASE WHEN status = 'superseded' THEN 1 ELSE 0 END), 0) AS superseded_count
            FROM institution_applications
            SQL);

        if (false === $row) {
            return new AdminInstitutionApplicationSummary(0, 0, 0, 0, 0, 0);
        }

        return new AdminInstitutionApplicationSummary(
            $this->intFrom($row['total'] ?? null),
            $this->intFrom($row['pending_count'] ?? null),
            $this->intFrom($row['approved_count'] ?? null),
            $this->intFrom($row['rejected_count'] ?? null),
            $this->intFrom($row['withdrawn_count'] ?? null),
            $this->intFrom($row['superseded_count'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    public function search(array $raw): AdminInstitutionApplicationList
    {
        $filters = $this->filters($raw);
        $pageSize = AdminPagination::normalizePageSize($this->positiveInt($raw['page_size'] ?? null, AdminPagination::DEFAULT_PAGE_SIZE));
        $count = $this->countMatching($filters['q'], $filters['status']);
        $totalPages = max(1, (int) ceil($count / $pageSize));
        $page = min(AdminPagination::normalizePage($this->positiveInt($raw['page'] ?? null, 1)), $totalPages);

        $qb = $this->em->createQueryBuilder()
            ->select('a', 'u')
            ->from(InstitutionApplication::class, 'a')
            ->innerJoin('a.user', 'u')
            ->orderBy('a.submittedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult(AdminPagination::offset($page, $pageSize))
            ->setMaxResults($pageSize);
        $this->applyFilters($qb, $filters['q'], $filters['status']);

        $items = [];
        foreach ($qb->getQuery()->getResult() as $application) {
            if ($application instanceof InstitutionApplication) {
                $items[] = AdminInstitutionApplicationRow::fromApplication($application);
            }
        }

        return new AdminInstitutionApplicationList(
            new AdminPagedResult($items, $page, $pageSize, $count),
            $filters,
        );
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{q: string, status: string}
     */
    private function filters(array $raw): array
    {
        $q = '';
        if (isset($raw['q']) && \is_string($raw['q'])) {
            $term = trim($raw['q']);
            if (mb_strlen($term) > AdminLikeEscape::MAX_QUERY_LENGTH) {
                $term = mb_substr($term, 0, AdminLikeEscape::MAX_QUERY_LENGTH);
            }
            $q = AdminLikeEscape::normalizeSearch($term) ?? '';
        }

        $status = '';
        if (isset($raw['status']) && \is_string($raw['status'])) {
            $parsed = OnboardingApplicationStatus::tryFrom($raw['status']);
            $status = $parsed instanceof OnboardingApplicationStatus ? $parsed->value : '';
        }

        return ['q' => $q, 'status' => $status];
    }

    private function countMatching(string $q, string $status): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(InstitutionApplication::class, 'a')
            ->innerJoin('a.user', 'u');
        $this->applyFilters($qb, $q, $status);
        $count = $qb->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function applyFilters(QueryBuilder $qb, string $q, string $status): void
    {
        if ('' !== $q) {
            $qb->andWhere(
                "(u.firstName LIKE :q ESCAPE '!' OR u.lastName LIKE :q ESCAPE '!' OR u.email LIKE :q ESCAPE '!' OR u.normalizedEmail LIKE :q ESCAPE '!' OR CONCAT(u.firstName, ' ', u.lastName) LIKE :q ESCAPE '!' OR a.proposedName LIKE :q ESCAPE '!' OR a.proposedNormalizedName LIKE :q ESCAPE '!')",
            )->setParameter('q', AdminLikeEscape::containsPattern($q));
        }
        $parsed = OnboardingApplicationStatus::tryFrom($status);
        if ($parsed instanceof OnboardingApplicationStatus) {
            $qb->andWhere('a.status = :status')->setParameter('status', $parsed);
        }
    }

    private function positiveInt(mixed $value, int $default): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        return $default;
    }

    private function intFrom(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
