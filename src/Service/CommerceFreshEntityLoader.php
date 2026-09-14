<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccessPackage;
use App\Entity\AccessPackageVersion;
use App\Entity\CommerceFulfillment;
use App\Entity\CommerceOrder;
use App\Entity\CommerceOrderItem;
use App\Entity\CommerceSubscription;
use App\Entity\CommercialOffer;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentRefund;
use App\Entity\PaymentWebhookInboxEvent;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

/**
 * Identity-map bypassing loaders for commerce aggregates.
 *
 * Every read used for an authorization or integrity decision goes through HINT_REFRESH so
 * a stale in-memory entity can never widen a purchase, settlement, or fulfillment.
 *
 * Canonical lock order (see docs/architecture-commerce-payment.md):
 * Institution → purchaser/membership → CommercialOffer → AccessPackage → AccessPackageVersion
 * → CommerceOrder → CommerceOrderItem → CommerceSubscription → PaymentAttempt
 * → PaymentEvent/PaymentRefund → CommerceFulfillment → AccessLicense → Audit.
 */
final class CommerceFreshEntityLoader
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findFreshOffer(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_READ): ?CommercialOffer
    {
        $entity = $this->findFresh(CommercialOffer::class, $id, $lockMode);

        return $entity instanceof CommercialOffer ? $entity : null;
    }

    public function findFreshPackage(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_READ): ?AccessPackage
    {
        $entity = $this->findFresh(AccessPackage::class, $id, $lockMode);

        return $entity instanceof AccessPackage ? $entity : null;
    }

    public function findFreshPackageVersion(
        Uuid $id,
        LockMode $lockMode = LockMode::PESSIMISTIC_READ,
    ): ?AccessPackageVersion {
        $entity = $this->findFresh(AccessPackageVersion::class, $id, $lockMode);

        return $entity instanceof AccessPackageVersion ? $entity : null;
    }

    public function findFreshOrder(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?CommerceOrder
    {
        $entity = $this->findFresh(CommerceOrder::class, $id, $lockMode);

        return $entity instanceof CommerceOrder ? $entity : null;
    }

    public function findFreshOrderItem(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_READ): ?CommerceOrderItem
    {
        $entity = $this->findFresh(CommerceOrderItem::class, $id, $lockMode);

        return $entity instanceof CommerceOrderItem ? $entity : null;
    }

    public function findFreshSubscription(
        Uuid $id,
        LockMode $lockMode = LockMode::PESSIMISTIC_WRITE,
    ): ?CommerceSubscription {
        $entity = $this->findFresh(CommerceSubscription::class, $id, $lockMode);

        return $entity instanceof CommerceSubscription ? $entity : null;
    }

    public function findFreshPaymentAttempt(
        Uuid $id,
        LockMode $lockMode = LockMode::PESSIMISTIC_WRITE,
    ): ?PaymentAttempt {
        $entity = $this->findFresh(PaymentAttempt::class, $id, $lockMode);

        return $entity instanceof PaymentAttempt ? $entity : null;
    }

    public function findFreshRefund(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?PaymentRefund
    {
        $entity = $this->findFresh(PaymentRefund::class, $id, $lockMode);

        return $entity instanceof PaymentRefund ? $entity : null;
    }

    public function findFreshFulfillment(
        Uuid $id,
        LockMode $lockMode = LockMode::PESSIMISTIC_WRITE,
    ): ?CommerceFulfillment {
        $entity = $this->findFresh(CommerceFulfillment::class, $id, $lockMode);

        return $entity instanceof CommerceFulfillment ? $entity : null;
    }

    public function findFreshWebhookInbox(
        Uuid $id,
        LockMode $lockMode = LockMode::PESSIMISTIC_WRITE,
    ): ?PaymentWebhookInboxEvent {
        $entity = $this->findFresh(PaymentWebhookInboxEvent::class, $id, $lockMode);

        return $entity instanceof PaymentWebhookInboxEvent ? $entity : null;
    }

    /**
     * @return list<CommerceOrderItem>
     */
    public function findFreshOrderItems(Uuid $orderId): array
    {
        /** @var list<CommerceOrderItem> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(CommerceOrderItem::class, 'i')
            ->where('IDENTITY(i.order) = :orderId')
            ->setParameter('orderId', $orderId, 'uuid')
            ->orderBy('i.createdAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $rows;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function findFresh(string $class, Uuid $id, LockMode $lockMode): ?object
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($class, 'e')
            ->where('e.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode($lockMode);

        /** @var T|null $result */
        $result = $query->getOneOrNullResult();

        return $result;
    }
}
