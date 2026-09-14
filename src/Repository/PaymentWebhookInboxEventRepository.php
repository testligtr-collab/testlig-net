<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentWebhookInboxEvent;
use App\Enum\PaymentProviderEnvironment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PaymentWebhookInboxEvent>
 */
final class PaymentWebhookInboxEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
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
}
