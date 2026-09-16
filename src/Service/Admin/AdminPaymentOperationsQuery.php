<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminPagedResult;
use App\Dto\AdminPaymentAttemptListItem;
use App\Dto\PaymentAttemptOperationsView;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentReconciliationItem;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentReconciliationItemOutcome;
use App\Exception\CommerceException;
use App\Service\PaymentOperationsReadModel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Allowlisted, paginated payment attempt queries for the admin panel.
 *
 * @phpstan-type PaymentFilters array{
 *     status?: ?string,
 *     provider?: ?string,
 *     environment?: ?string,
 *     created_from?: ?string,
 *     created_to?: ?string,
 *     needs_reconciliation?: ?bool,
 *     order_public_reference?: ?string,
 *     page?: int,
 *     page_size?: int
 * }
 */
final class AdminPaymentOperationsQuery
{
    private const ALLOWED_FILTER_KEYS = [
        'status',
        'provider',
        'environment',
        'created_from',
        'created_to',
        'needs_reconciliation',
        'order_public_reference',
        'page',
        'page_size',
    ];

    public function __construct(
        private readonly AdminActorGuard $actorGuard,
        private readonly EntityManagerInterface $em,
        private readonly PaymentOperationsReadModel $paymentOps,
    ) {
    }

    /**
     * @param array<string, mixed> $rawFilters
     *
     * @return AdminPagedResult<AdminPaymentAttemptListItem>
     */
    public function listAttempts(Uuid $actorId, array $rawFilters = []): AdminPagedResult
    {
        $this->actorGuard->requirePaymentOps($actorId);
        $filters = $this->normalizeFilters($rawFilters);
        $page = AdminPagination::normalizePage((int) ($filters['page'] ?? 1));
        $pageSize = AdminPagination::normalizePageSize((int) ($filters['page_size'] ?? AdminPagination::DEFAULT_PAGE_SIZE));

        $qb = $this->em->createQueryBuilder()
            ->select('a', 'o')
            ->from(PaymentAttempt::class, 'a')
            ->innerJoin('a.order', 'o');

        if (isset($filters['status']) && \is_string($filters['status']) && '' !== $filters['status']) {
            $status = PaymentAttemptStatus::tryFrom($filters['status']);
            if (!$status instanceof PaymentAttemptStatus) {
                throw CommerceException::invalidInput('Invalid payment status filter.');
            }
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }

        if (isset($filters['provider']) && \is_string($filters['provider']) && '' !== $filters['provider']) {
            if (1 !== preg_match(PaymentAttempt::PROVIDER_CODE_PATTERN, $filters['provider'])) {
                throw CommerceException::invalidInput('Invalid provider filter.');
            }
            $qb->andWhere('a.providerCode = :provider')->setParameter('provider', $filters['provider']);
        }

        if (isset($filters['environment']) && \is_string($filters['environment']) && '' !== $filters['environment']) {
            $env = PaymentProviderEnvironment::tryFrom($filters['environment']);
            if (!$env instanceof PaymentProviderEnvironment) {
                throw CommerceException::invalidInput('Invalid environment filter.');
            }
            $qb->andWhere('a.environment = :environment')->setParameter('environment', $env);
        }

        if (isset($filters['created_from']) && \is_string($filters['created_from']) && '' !== $filters['created_from']) {
            $from = $this->parseDateBoundary($filters['created_from'], true);
            $qb->andWhere('a.createdAt >= :createdFrom')->setParameter('createdFrom', $from);
        }

        if (isset($filters['created_to']) && \is_string($filters['created_to']) && '' !== $filters['created_to']) {
            $to = $this->parseDateBoundary($filters['created_to'], false);
            $qb->andWhere('a.createdAt <= :createdTo')->setParameter('createdTo', $to);
        }

        if (isset($filters['order_public_reference']) && \is_string($filters['order_public_reference']) && '' !== $filters['order_public_reference']) {
            $qb->andWhere('o.publicReference = :orderRef')
                ->setParameter('orderRef', $filters['order_public_reference']);
        }

        if (\array_key_exists('needs_reconciliation', $filters) && null !== $filters['needs_reconciliation']) {
            $needs = (bool) $filters['needs_reconciliation'];
            $sub = $this->em->createQueryBuilder()
                ->select('1')
                ->from(PaymentReconciliationItem::class, 'ri')
                ->andWhere('IDENTITY(ri.paymentAttempt) = a.id')
                ->andWhere('ri.outcome != :matchedOutcome')
                ->getDQL();
            $qb->setParameter('matchedOutcome', PaymentReconciliationItemOutcome::Matched);
            if ($needs) {
                $qb->andWhere($qb->expr()->exists($sub));
            } else {
                $qb->andWhere($qb->expr()->not($qb->expr()->exists($sub)));
            }
        }

        $countQb = clone $qb;
        $total = (int) $countQb
            ->resetDQLPart('select')
            ->resetDQLPart('orderBy')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<PaymentAttempt> $rows */
        $rows = $qb
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'ASC')
            ->setFirstResult(AdminPagination::offset($page, $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $needsMap = $this->loadNeedsReconciliationMap(array_map(
            static fn (PaymentAttempt $a): Uuid => $a->getId(),
            $rows,
        ));

        $items = [];
        foreach ($rows as $attempt) {
            $id = $attempt->getId();
            $items[] = new AdminPaymentAttemptListItem(
                attemptId: $id,
                status: $attempt->getStatus(),
                providerCode: $attempt->getProviderCode(),
                environment: $attempt->getEnvironment(),
                amountMinor: $attempt->getAmountMinor(),
                currency: $attempt->getCurrency(),
                orderPublicReference: $attempt->getOrder()->getPublicReference(),
                createdAt: $attempt->getCreatedAt(),
                needsReconciliation: $needsMap[$id->toRfc4122()] ?? false,
            );
        }

        return new AdminPagedResult($items, $page, $pageSize, $total);
    }

    public function getAttemptDetail(Uuid $actorId, Uuid $attemptId): PaymentAttemptOperationsView
    {
        $this->actorGuard->requirePaymentOps($actorId);

        try {
            return $this->paymentOps->getAttemptOperationsView($actorId, $attemptId);
        } catch (CommerceException $e) {
            throw $e;
        }
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

    private function parseDateBoundary(string $value, bool $startOfDay): \DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$dt instanceof \DateTimeImmutable) {
            throw CommerceException::invalidInput('Invalid date filter.');
        }

        return $startOfDay
            ? $dt->setTime(0, 0, 0)
            : $dt->setTime(23, 59, 59);
    }

    /**
     * @param list<Uuid> $attemptIds
     *
     * @return array<string, bool>
     */
    private function loadNeedsReconciliationMap(array $attemptIds): array
    {
        if ([] === $attemptIds) {
            return [];
        }

        /** @var list<array{attemptId: mixed}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(ri.paymentAttempt) AS attemptId')
            ->from(PaymentReconciliationItem::class, 'ri')
            ->andWhere('IDENTITY(ri.paymentAttempt) IN (:ids)')
            ->andWhere('ri.outcome != :matched')
            ->setParameter('ids', $attemptIds)
            ->setParameter('matched', PaymentReconciliationItemOutcome::Matched)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($attemptIds as $id) {
            $map[$id->toRfc4122()] = false;
        }
        foreach ($rows as $row) {
            $raw = $row['attemptId'];
            if ($raw instanceof Uuid) {
                $map[$raw->toRfc4122()] = true;
            } elseif (\is_string($raw) && '' !== $raw) {
                $map[$raw] = true;
            }
        }

        return $map;
    }
}
