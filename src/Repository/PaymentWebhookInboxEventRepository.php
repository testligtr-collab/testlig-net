<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentWebhookInboxEvent;
use App\Enum\PaymentProviderEnvironment;
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
}
