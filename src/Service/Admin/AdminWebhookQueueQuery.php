<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminPagedResult;
use App\Dto\AdminWebhookInboxListItem;
use App\Entity\PaymentWebhookInboxEvent;
use App\Enum\PaymentWebhookInboxStatus;
use App\Exception\CommerceException;
use App\Repository\PaymentWebhookInboxEventRepository;
use App\Time\UtcInstant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Webhook inbox list/detail for admin payment operators.
 */
final class AdminWebhookQueueQuery
{
    public const TAB_DUE = 'due';
    public const TAB_RETRY_PENDING = 'retry_pending';
    public const TAB_PROCESSING = 'processing';
    public const TAB_STALE_LEASE = 'stale_lease';
    public const TAB_DEAD_LETTER = 'dead_letter';
    public const TAB_REJECTED = 'rejected';
    public const TAB_PROCESSED = 'processed';

    /**
     * @return list<string>
     */
    public static function allowedTabs(): array
    {
        return [
            self::TAB_DUE,
            self::TAB_RETRY_PENDING,
            self::TAB_PROCESSING,
            self::TAB_STALE_LEASE,
            self::TAB_DEAD_LETTER,
            self::TAB_REJECTED,
            self::TAB_PROCESSED,
        ];
    }

    public function __construct(
        private readonly AdminActorGuard $actorGuard,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly PaymentWebhookInboxEventRepository $events,
    ) {
    }

    /**
     * @return AdminPagedResult<AdminWebhookInboxListItem>
     */
    public function listByTab(Uuid $actorId, string $tab, int $page = 1, int $pageSize = AdminPagination::DEFAULT_PAGE_SIZE): AdminPagedResult
    {
        $this->actorGuard->requirePaymentOps($actorId);
        if (!\in_array($tab, self::allowedTabs(), true)) {
            throw CommerceException::invalidInput('Invalid webhook status tab.');
        }

        $page = AdminPagination::normalizePage($page);
        $pageSize = AdminPagination::normalizePageSize($pageSize);
        $now = UtcInstant::ensure($this->clock->now());

        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(PaymentWebhookInboxEvent::class, 'e');

        match ($tab) {
            self::TAB_DUE => $qb->andWhere(
                '(e.processingStatus = :received)
                 OR (e.processingStatus = :retryPending AND e.nextRetryAt <= :now)
                 OR (e.processingStatus = :processing AND e.leaseExpiresAt <= :now)',
            )
                ->setParameter('received', PaymentWebhookInboxStatus::Received)
                ->setParameter('retryPending', PaymentWebhookInboxStatus::RetryPending)
                ->setParameter('processing', PaymentWebhookInboxStatus::Processing)
                ->setParameter('now', $now),
            self::TAB_STALE_LEASE => $qb->andWhere('e.processingStatus = :processing AND e.leaseExpiresAt <= :now')
                ->setParameter('processing', PaymentWebhookInboxStatus::Processing)
                ->setParameter('now', $now),
            self::TAB_RETRY_PENDING => $qb->andWhere('e.processingStatus = :status')
                ->setParameter('status', PaymentWebhookInboxStatus::RetryPending),
            self::TAB_PROCESSING => $qb->andWhere('e.processingStatus = :status AND (e.leaseExpiresAt IS NULL OR e.leaseExpiresAt > :now)')
                ->setParameter('status', PaymentWebhookInboxStatus::Processing)
                ->setParameter('now', $now),
            self::TAB_DEAD_LETTER => $qb->andWhere('e.processingStatus = :status')
                ->setParameter('status', PaymentWebhookInboxStatus::DeadLetter),
            self::TAB_REJECTED => $qb->andWhere('e.processingStatus = :status')
                ->setParameter('status', PaymentWebhookInboxStatus::Rejected),
            self::TAB_PROCESSED => $qb->andWhere('e.processingStatus = :status')
                ->setParameter('status', PaymentWebhookInboxStatus::Processed),
            default => throw CommerceException::invalidInput('Invalid webhook status tab.'),
        };

        $countQb = clone $qb;
        $total = (int) $countQb
            ->resetDQLPart('select')
            ->resetDQLPart('orderBy')
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<PaymentWebhookInboxEvent> $rows */
        $rows = $qb
            ->orderBy('e.receivedAt', 'DESC')
            ->addOrderBy('e.id', 'ASC')
            ->setFirstResult(AdminPagination::offset($page, $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($rows as $event) {
            $items[] = $this->toItem($event, $now);
        }

        return new AdminPagedResult($items, $page, $pageSize, $total);
    }

    public function getDetail(Uuid $actorId, Uuid $eventId): AdminWebhookInboxListItem
    {
        $this->actorGuard->requirePaymentOps($actorId);
        $event = $this->events->findFreshById($eventId);
        if (!$event instanceof PaymentWebhookInboxEvent) {
            throw CommerceException::notFound();
        }

        return $this->toItem($event, UtcInstant::ensure($this->clock->now()));
    }

    private function toItem(PaymentWebhookInboxEvent $event, \DateTimeImmutable $now): AdminWebhookInboxListItem
    {
        $lease = $event->getLeaseExpiresAt();
        $isStale = PaymentWebhookInboxStatus::Processing === $event->getProcessingStatus()
            && $lease instanceof \DateTimeImmutable
            && $lease <= $now;

        $attempt = $event->getPaymentAttempt();

        return new AdminWebhookInboxListItem(
            eventId: $event->getId(),
            status: $event->getProcessingStatus(),
            providerCode: $event->getProviderCode(),
            environment: $event->getEnvironment(),
            eventType: $event->getEventType(),
            receivedAt: $event->getReceivedAt(),
            nextRetryAt: $event->getNextRetryAt(),
            leaseExpiresAt: $lease,
            attemptCount: $event->getAttemptCount(),
            lastFailureReasonCode: $event->getLastFailureReasonCode(),
            paymentAttemptId: $attempt?->getId(),
            isStaleLease: $isStale,
            canRequeue: PaymentWebhookInboxStatus::DeadLetter === $event->getProcessingStatus(),
        );
    }
}
