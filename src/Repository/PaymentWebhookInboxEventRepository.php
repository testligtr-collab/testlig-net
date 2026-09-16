<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentWebhookInboxEvent;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentWebhookInboxStatus;
use App\Exception\CommerceException;
use App\Time\UtcInstant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * @extends ServiceEntityRepository<PaymentWebhookInboxEvent>
 */
final class PaymentWebhookInboxEventRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($registry, PaymentWebhookInboxEvent::class);
    }

    public function save(PaymentWebhookInboxEvent $event, bool $flush = true): void
    {
        $this->getEntityManager()->persist($event);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByProviderEvent(
        string $providerCode,
        PaymentProviderEnvironment $environment,
        string $providerEventReference,
    ): ?PaymentWebhookInboxEvent {
        return $this->findOneBy([
            'providerCode' => $providerCode,
            'environment' => $environment,
            'providerEventReference' => $providerEventReference,
        ]);
    }

    public function findOneById(Uuid $id): ?PaymentWebhookInboxEvent
    {
        return $this->find($id);
    }

    public function findFreshById(Uuid $id): ?PaymentWebhookInboxEvent
    {
        $result = $this->getEntityManager()->createQueryBuilder()
            ->select('e')
            ->from(PaymentWebhookInboxEvent::class, 'e')
            ->where('e.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $result instanceof PaymentWebhookInboxEvent ? $result : null;
    }

    public function findFreshForUpdate(Uuid $id): ?PaymentWebhookInboxEvent
    {
        $result = $this->getEntityManager()->createQueryBuilder()
            ->select('e')
            ->from(PaymentWebhookInboxEvent::class, 'e')
            ->where('e.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $result instanceof PaymentWebhookInboxEvent ? $result : null;
    }

    /**
     * Conditionally claims an inbox row for processing (lease CAS under row lock).
     *
     * @return array{event: PaymentWebhookInboxEvent, claimToken: Uuid}|null
     */
    public function claimForProcessing(Uuid $id): ?array
    {
        $em = $this->getEntityManager();
        try {
            return $em->wrapInTransaction(function () use ($id, $em): ?array {
                $event = $this->findFreshForUpdate($id);
                if (!$event instanceof PaymentWebhookInboxEvent) {
                    return null;
                }
                if ($event->getProcessingStatus()->isTerminal()) {
                    return null;
                }

                $now = UtcInstant::ensure($this->clock->now());
                $claimToken = new UuidV7();
                try {
                    $event->applyClaim($claimToken, $now);
                } catch (CommerceException) {
                    return null;
                }
                $em->flush();

                return ['event' => $event, 'claimToken' => $claimToken];
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }
    }

    /**
     * Due inbox ids: received, due retry_pending, or stale processing lease.
     * Ordered by COALESCE(next_retry_at, received_at) ASC, id ASC.
     *
     * @return list<Uuid>
     */
    public function findDueEventIds(
        int $limit = 25,
        ?string $providerCode = null,
        ?PaymentProviderEnvironment $environment = null,
        ?\DateTimeImmutable $now = null,
    ): array {
        if ($limit < 1) {
            return [];
        }
        $limit = min($limit, 100);
        $now = UtcInstant::ensure($now ?? $this->clock->now());

        $sql = 'SELECT LOWER(HEX(id)) AS id_hex FROM payment_webhook_inbox_events WHERE (
            processing_status = :received
            OR (processing_status = :retry_pending AND next_retry_at <= :now)
            OR (processing_status = :processing AND lease_expires_at <= :now)
        )';
        $params = [
            'received' => PaymentWebhookInboxStatus::Received->value,
            'retry_pending' => PaymentWebhookInboxStatus::RetryPending->value,
            'processing' => PaymentWebhookInboxStatus::Processing->value,
            'now' => $now->format('Y-m-d H:i:s'),
        ];

        if (null !== $providerCode) {
            $sql .= ' AND provider_code = :provider_code';
            $params['provider_code'] = $providerCode;
        }
        if ($environment instanceof PaymentProviderEnvironment) {
            $sql .= ' AND environment = :environment';
            $params['environment'] = $environment->value;
        }

        $sql .= ' ORDER BY COALESCE(next_retry_at, received_at) ASC, id ASC LIMIT '.$limit;

        /** @var list<string|null> $hexIds */
        $hexIds = $this->getEntityManager()->getConnection()->fetchFirstColumn($sql, $params);
        $ids = [];
        foreach ($hexIds as $hex) {
            if (!\is_string($hex) || 32 !== \strlen($hex)) {
                continue;
            }
            $ids[] = Uuid::fromBinary((string) hex2bin($hex));
        }

        return $ids;
    }

    public function countDue(
        ?string $providerCode,
        ?PaymentProviderEnvironment $env,
        \DateTimeImmutable $now,
    ): int {
        return $this->countLifecycle(
            '(e.processingStatus = :received)
             OR (e.processingStatus = :retryPending AND e.nextRetryAt <= :now)
             OR (e.processingStatus = :processing AND e.leaseExpiresAt <= :now)',
            [
                'received' => PaymentWebhookInboxStatus::Received,
                'retryPending' => PaymentWebhookInboxStatus::RetryPending,
                'processing' => PaymentWebhookInboxStatus::Processing,
                'now' => $now,
            ],
            $providerCode,
            $env,
        );
    }

    public function countStaleLease(
        ?string $providerCode,
        ?PaymentProviderEnvironment $env,
        \DateTimeImmutable $now,
    ): int {
        return $this->countLifecycle(
            'e.processingStatus = :processing AND e.leaseExpiresAt <= :now',
            [
                'processing' => PaymentWebhookInboxStatus::Processing,
                'now' => $now,
            ],
            $providerCode,
            $env,
        );
    }

    public function countActiveLease(
        ?string $providerCode,
        ?PaymentProviderEnvironment $env,
        \DateTimeImmutable $now,
    ): int {
        return $this->countLifecycle(
            'e.processingStatus = :processing AND e.leaseExpiresAt > :now',
            [
                'processing' => PaymentWebhookInboxStatus::Processing,
                'now' => $now,
            ],
            $providerCode,
            $env,
        );
    }

    public function countByStatus(
        PaymentWebhookInboxStatus $status,
        ?string $providerCode = null,
        ?PaymentProviderEnvironment $env = null,
    ): int {
        return $this->countLifecycle(
            'e.processingStatus = :status',
            ['status' => $status],
            $providerCode,
            $env,
        );
    }

    public function oldestDueAgeSeconds(
        ?string $providerCode,
        ?PaymentProviderEnvironment $env,
        \DateTimeImmutable $now,
    ): ?int {
        $qb = $this->createQueryBuilder('e')
            ->select('MIN(COALESCE(e.nextRetryAt, e.receivedAt))')
            ->andWhere(
                '(e.processingStatus = :received)
                 OR (e.processingStatus = :retryPending AND e.nextRetryAt <= :now)
                 OR (e.processingStatus = :processing AND e.leaseExpiresAt <= :now)',
            )
            ->setParameter('received', PaymentWebhookInboxStatus::Received)
            ->setParameter('retryPending', PaymentWebhookInboxStatus::RetryPending)
            ->setParameter('processing', PaymentWebhookInboxStatus::Processing)
            ->setParameter('now', $now)
            ->setMaxResults(1);

        if (null !== $providerCode) {
            $qb->andWhere('e.providerCode = :providerCode')
                ->setParameter('providerCode', $providerCode);
        }
        if ($env instanceof PaymentProviderEnvironment) {
            $qb->andWhere('e.environment = :env')
                ->setParameter('env', $env);
        }

        $oldest = $qb->getQuery()->getSingleScalarResult();
        if (!\is_string($oldest) || '' === $oldest) {
            return null;
        }
        $oldestAt = new \DateTimeImmutable($oldest);

        return max(0, $now->getTimestamp() - $oldestAt->getTimestamp());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countLifecycle(
        string $where,
        array $params,
        ?string $providerCode,
        ?PaymentProviderEnvironment $env,
    ): int {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere($where);

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }
        if (null !== $providerCode) {
            $qb->andWhere('e.providerCode = :providerCode')
                ->setParameter('providerCode', $providerCode);
        }
        if ($env instanceof PaymentProviderEnvironment) {
            $qb->andWhere('e.environment = :env')
                ->setParameter('env', $env);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
