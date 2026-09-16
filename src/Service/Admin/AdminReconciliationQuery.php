<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminPagedResult;
use App\Dto\PaymentDiscrepancyView;
use App\Dto\PaymentReconciliationRunView;
use App\Entity\PaymentReconciliationRun;
use App\Exception\CommerceException;
use App\Service\PaymentOperationsReadModel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Reconciliation run list/detail for admin payment operators.
 */
final class AdminReconciliationQuery
{
    public function __construct(
        private readonly AdminActorGuard $actorGuard,
        private readonly EntityManagerInterface $em,
        private readonly PaymentOperationsReadModel $paymentOps,
    ) {
    }

    /**
     * @return AdminPagedResult<PaymentReconciliationRunView>
     */
    public function listRuns(Uuid $actorId, int $page = 1, int $pageSize = AdminPagination::DEFAULT_PAGE_SIZE): AdminPagedResult
    {
        $this->actorGuard->requirePaymentOps($actorId);
        $page = AdminPagination::normalizePage($page);
        $pageSize = AdminPagination::normalizePageSize($pageSize);

        $total = (int) $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(PaymentReconciliationRun::class, 'r')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<PaymentReconciliationRun> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('r')
            ->from(PaymentReconciliationRun::class, 'r')
            ->orderBy('r.startedAt', 'DESC')
            ->addOrderBy('r.id', 'ASC')
            ->setFirstResult(AdminPagination::offset($page, $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($rows as $run) {
            $items[] = new PaymentReconciliationRunView(
                runId: $run->getId(),
                providerCode: $run->getProviderCode(),
                environment: $run->getEnvironment(),
                mode: $run->getMode(),
                status: $run->getStatus(),
                reasonCode: $run->getReasonCode(),
                startedAt: $run->getStartedAt(),
                completedAt: $run->getCompletedAt(),
                checkedCount: $run->getCheckedCount(),
                matchedCount: $run->getMatchedCount(),
                discrepancyCount: $run->getDiscrepancyCount(),
                failedCount: $run->getFailedCount(),
            );
        }

        return new AdminPagedResult($items, $page, $pageSize, $total);
    }

    public function getRunDetail(Uuid $actorId, Uuid $runId): PaymentReconciliationRunView
    {
        $this->actorGuard->requirePaymentOps($actorId);

        try {
            return $this->paymentOps->getReconciliationRunView($actorId, $runId);
        } catch (CommerceException $e) {
            throw $e;
        }
    }

    /**
     * @return AdminPagedResult<PaymentDiscrepancyView>
     */
    public function getDiscrepancies(
        Uuid $actorId,
        Uuid $runId,
        int $page = 1,
        int $pageSize = AdminPagination::DEFAULT_PAGE_SIZE,
    ): AdminPagedResult {
        $this->actorGuard->requirePaymentOps($actorId);
        $page = AdminPagination::normalizePage($page);
        $pageSize = AdminPagination::normalizePageSize($pageSize);

        $total = $this->paymentOps->countDiscrepanciesForRun($actorId, $runId);
        $items = $this->paymentOps->getDiscrepanciesForRun(
            $actorId,
            $runId,
            $pageSize,
            AdminPagination::offset($page, $pageSize),
        );

        return new AdminPagedResult($items, $page, $pageSize, $total);
    }
}
