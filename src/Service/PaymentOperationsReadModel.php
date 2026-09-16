<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PaymentAttemptOperationsView;
use App\Dto\PaymentDiscrepancyView;
use App\Dto\PaymentOperationsSummaryView;
use App\Dto\PaymentReconciliationRunView;
use App\Dto\PaymentWebhookQueueSummaryView;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentReconciliationItem;
use App\Entity\PaymentReconciliationRun;
use App\Entity\User;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentWebhookInboxStatus;
use App\Exception\CommerceException;
use App\Repository\PaymentAttemptRepository;
use App\Repository\PaymentReconciliationItemRepository;
use App\Repository\PaymentReconciliationRunRepository;
use App\Repository\PaymentWebhookInboxEventRepository;
use App\Security\CommerceAuthorization;
use App\Time\UtcInstant;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only payment operations views for a future admin UI. No controllers here.
 */
final class PaymentOperationsReadModel
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FreshUserLoader $freshUsers,
        private readonly CommerceAuthorization $commerceAuthorization,
        private readonly PaymentAttemptRepository $attempts,
        private readonly PaymentWebhookInboxEventRepository $inbox,
        private readonly PaymentReconciliationRunRepository $runs,
        private readonly PaymentReconciliationItemRepository $items,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getOperationsSummary(
        Uuid $actorId,
        ?string $providerCode = null,
        ?PaymentProviderEnvironment $environment = null,
    ): PaymentOperationsSummaryView {
        $this->assertOperator($actorId);
        $now = UtcInstant::ensure($this->clock->now());

        $attemptCounts = [];
        foreach (PaymentAttemptStatus::cases() as $status) {
            $qb = $this->em->createQueryBuilder()
                ->select('COUNT(a.id)')
                ->from(PaymentAttempt::class, 'a')
                ->andWhere('a.status = :status')
                ->setParameter('status', $status);
            if (null !== $providerCode) {
                $qb->andWhere('a.providerCode = :providerCode')
                    ->setParameter('providerCode', $providerCode);
            }
            if ($environment instanceof PaymentProviderEnvironment) {
                $qb->andWhere('a.environment = :environment')
                    ->setParameter('environment', $environment);
            }
            $attemptCounts[$status->value] = (int) $qb->getQuery()->getSingleScalarResult();
        }

        $reconMatched = (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(r.matchedCount), 0)')
            ->from(PaymentReconciliationRun::class, 'r')
            ->getQuery()
            ->getSingleScalarResult();
        $reconDisc = (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(r.discrepancyCount), 0)')
            ->from(PaymentReconciliationRun::class, 'r')
            ->getQuery()
            ->getSingleScalarResult();
        $reconFailed = (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(r.failedCount), 0)')
            ->from(PaymentReconciliationRun::class, 'r')
            ->getQuery()
            ->getSingleScalarResult();

        return new PaymentOperationsSummaryView(
            attemptCountsByStatus: $attemptCounts,
            dueWebhookCount: $this->inbox->countDue($providerCode, $environment, $now),
            activeLeaseCount: $this->inbox->countActiveLease($providerCode, $environment, $now),
            staleLeaseCount: $this->inbox->countStaleLease($providerCode, $environment, $now),
            deadLetterCount: $this->inbox->countByStatus(PaymentWebhookInboxStatus::DeadLetter, $providerCode, $environment),
            rejectedWebhookCount: $this->inbox->countByStatus(PaymentWebhookInboxStatus::Rejected, $providerCode, $environment),
            oldestDueAgeSeconds: $this->inbox->oldestDueAgeSeconds($providerCode, $environment, $now),
            reconciliationMatchedCount: $reconMatched,
            reconciliationDiscrepancyCount: $reconDisc,
            reconciliationFailedCount: $reconFailed,
        );
    }

    public function getAttemptOperationsView(Uuid $actorId, Uuid $attemptId): PaymentAttemptOperationsView
    {
        $this->assertOperator($actorId);
        $attempt = $this->attempts->findOneById($attemptId);
        if (!$attempt instanceof PaymentAttempt) {
            throw CommerceException::notFound();
        }

        return new PaymentAttemptOperationsView(
            attemptId: $attempt->getId(),
            status: $attempt->getStatus(),
            providerCode: $attempt->getProviderCode(),
            environment: $attempt->getEnvironment(),
            amountMinor: $attempt->getAmountMinor(),
            currency: $attempt->getCurrency(),
            orderPublicReference: $attempt->getOrder()->getPublicReference(),
            createdAt: $attempt->getCreatedAt(),
            authorizedAt: $attempt->getAuthorizedAt(),
            capturedAt: $attempt->getCapturedAt(),
            failureCode: $attempt->getFailureCode(),
        );
    }

    public function getWebhookQueueSummary(
        Uuid $actorId,
        ?string $providerCode = null,
        ?PaymentProviderEnvironment $environment = null,
    ): PaymentWebhookQueueSummaryView {
        $this->assertOperator($actorId);
        $now = UtcInstant::ensure($this->clock->now());

        return new PaymentWebhookQueueSummaryView(
            dueCount: $this->inbox->countDue($providerCode, $environment, $now),
            receivedCount: $this->inbox->countByStatus(PaymentWebhookInboxStatus::Received, $providerCode, $environment),
            retryPendingCount: $this->inbox->countByStatus(PaymentWebhookInboxStatus::RetryPending, $providerCode, $environment),
            processingCount: $this->inbox->countByStatus(PaymentWebhookInboxStatus::Processing, $providerCode, $environment),
            activeLeaseCount: $this->inbox->countActiveLease($providerCode, $environment, $now),
            staleLeaseCount: $this->inbox->countStaleLease($providerCode, $environment, $now),
            deadLetterCount: $this->inbox->countByStatus(PaymentWebhookInboxStatus::DeadLetter, $providerCode, $environment),
            rejectedCount: $this->inbox->countByStatus(PaymentWebhookInboxStatus::Rejected, $providerCode, $environment),
            processedCount: $this->inbox->countByStatus(PaymentWebhookInboxStatus::Processed, $providerCode, $environment),
            oldestDueAgeSeconds: $this->inbox->oldestDueAgeSeconds($providerCode, $environment, $now),
        );
    }

    public function getReconciliationRunView(Uuid $actorId, Uuid $runId): PaymentReconciliationRunView
    {
        $this->assertOperator($actorId);
        $run = $this->runs->findOneById($runId);
        if (!$run instanceof PaymentReconciliationRun) {
            throw CommerceException::notFound();
        }

        return new PaymentReconciliationRunView(
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

    /**
     * @return list<PaymentDiscrepancyView>
     */
    public function getDiscrepanciesForRun(
        Uuid $actorId,
        Uuid $runId,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        $this->assertOperator($actorId);
        $run = $this->runs->findOneById($runId);
        if (!$run instanceof PaymentReconciliationRun) {
            throw CommerceException::notFound();
        }

        $views = [];
        foreach ($this->items->findDiscrepanciesByRun($run, $limit, $offset) as $item) {
            $views[] = $this->toDiscrepancyView($item);
        }

        return $views;
    }

    public function countDiscrepanciesForRun(Uuid $actorId, Uuid $runId): int
    {
        $this->assertOperator($actorId);
        $run = $this->runs->findOneById($runId);
        if (!$run instanceof PaymentReconciliationRun) {
            throw CommerceException::notFound();
        }

        return $this->items->countDiscrepanciesByRun($run);
    }

    private function toDiscrepancyView(PaymentReconciliationItem $item): PaymentDiscrepancyView
    {
        return new PaymentDiscrepancyView(
            itemId: $item->getId(),
            runId: $item->getRun()->getId(),
            paymentAttemptId: $item->getPaymentAttempt()->getId(),
            expectedState: $item->getExpectedState(),
            providerState: $item->getProviderState(),
            outcome: $item->getOutcome(),
            action: $item->getAction(),
            checkedAt: $item->getCheckedAt(),
            reasonCode: $item->getReasonCode(),
        );
    }

    private function assertOperator(Uuid $actorId): void
    {
        $this->em->wrapInTransaction(function () use ($actorId): void {
            $actor = $this->freshUsers->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
            if (!$actor instanceof User) {
                throw CommerceException::unauthorized();
            }
            $this->commerceAuthorization->assertCanOperatePayments($actor);
        });
    }
}
