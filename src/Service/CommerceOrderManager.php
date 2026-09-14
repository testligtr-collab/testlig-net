<?php

declare(strict_types=1);

namespace App\Service;

use App\Commerce\CommerceInputNormalizer;
use App\Commerce\CommerceMoneyPolicy;
use App\Commerce\CommerceOrderHasher;
use App\Dto\SecurityAuditContext;
use App\Entity\AccessPackage;
use App\Entity\AccessPackageVersion;
use App\Entity\CommerceOrder;
use App\Entity\CommerceOrderItem;
use App\Entity\CommercialOffer;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\AccessPackageStatus;
use App\Enum\AccessPackageVersionStatus;
use App\Enum\CommerceCancellationReasonCode;
use App\Enum\CommercePurchaserType;
use App\Enum\CommercialOfferBillingType;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CommerceException;
use App\Money\Money;
use App\Repository\CommerceOrderItemRepository;
use App\Repository\CommerceOrderRepository;
use App\Security\CommerceAuthorization;
use App\Time\UtcInstant;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Order lifecycle: draft creation with frozen line snapshots, cancellation, lazy expiry.
 *
 * Lock order: Institution → purchaser/membership → CommercialOffer → AccessPackage
 * → AccessPackageVersion → CommerceOrder → CommerceOrderItem → Audit
 */
final class CommerceOrderManager
{
    public const DEFAULT_TTL_SECONDS = 3600;

    public const MAX_TTL_SECONDS = 604800;

    public const MAX_ITEMS_PER_ORDER = 10;

    public function __construct(
        private readonly CommerceOrderRepository $orders,
        private readonly CommerceOrderItemRepository $orderItems,
        private readonly CommerceAuthorization $authorization,
        private readonly CommercialOfferManager $offerManager,
        private readonly CommerceOrderHasher $orderHasher,
        private readonly CommerceMoneyPolicy $moneyPolicy,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly CommerceFreshEntityLoader $freshCommerce,
        private readonly EntityManagerInterface $entityManager,
        private readonly \Psr\Clock\ClockInterface $clock,
    ) {
    }

    /**
     * @param non-empty-list<array{offer: CommercialOffer, quantity: int}> $lines
     */
    public function createUserOrder(
        User $actor,
        User $purchaser,
        array $lines,
        string $reasonCode,
        int $discountAmountMinor = 0,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ): CommerceOrder {
        return $this->createOrder(
            $actor,
            CommercePurchaserType::User,
            $purchaser,
            null,
            $lines,
            $reasonCode,
            $discountAmountMinor,
            $ttlSeconds,
        );
    }

    /**
     * @param non-empty-list<array{offer: CommercialOffer, quantity: int}> $lines
     */
    public function createInstitutionOrder(
        User $actor,
        Institution $institution,
        array $lines,
        string $reasonCode,
        int $discountAmountMinor = 0,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ): CommerceOrder {
        return $this->createOrder(
            $actor,
            CommercePurchaserType::Institution,
            null,
            $institution,
            $lines,
            $reasonCode,
            $discountAmountMinor,
            $ttlSeconds,
        );
    }

    public function cancel(
        CommerceOrder $order,
        User $actor,
        CommerceCancellationReasonCode $cancellationReasonCode,
        string $reasonCode,
    ): CommerceOrder {
        $reasonCode = CommerceInputNormalizer::reasonCode($reasonCode);
        $orderId = $order->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $orderId,
                $actorId,
                $cancellationReasonCode,
                $reasonCode,
            ): CommerceOrder {
                $locked = $this->requireLockedOrder($orderId);
                $freshActor = $this->requireFreshActor($actorId);
                $this->authorization->assertCanManageOrder($freshActor, $locked);

                $locked->cancel($cancellationReasonCode, $this->utcNow());
                $this->recordAudit(
                    SecurityAuditAction::CommerceOrderCancelled,
                    $freshActor,
                    SecurityAuditActorType::User,
                    $locked,
                    $reasonCode,
                );
                $this->entityManager->flush();

                return $locked;
            });
        } catch (CommerceException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }
    }

    /**
     * Lazy expiry: an unpaid order past `expiresAt` becomes `expired` in the same TX.
     * Recorded as a System audit — no user actor is required to garbage-collect intent.
     */
    public function evaluateAndExpireIfNeeded(CommerceOrder $order): CommerceOrder
    {
        $orderId = $order->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use ($orderId): CommerceOrder {
                $locked = $this->requireLockedOrder($orderId);
                $now = $this->utcNow();
                if ($locked->getStatus()->allowsCancellation() && $locked->isExpiredAt($now)) {
                    $locked->markExpired($now);
                    $this->recordAudit(
                        SecurityAuditAction::CommerceOrderExpired,
                        null,
                        SecurityAuditActorType::System,
                        $locked,
                        'lazy_expire',
                    );
                    $this->entityManager->flush();
                }

                return $locked;
            });
        } catch (CommerceException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }
    }

    /**
     * Recomputes the order hash and per-line totals from the persisted rows.
     *
     * @param list<CommerceOrderItem> $items
     */
    public function assertOrderIntegrity(CommerceOrder $order, array $items): void
    {
        if ([] === $items) {
            throw CommerceException::invalidInput('Order has no items.');
        }

        $subtotal = Money::zero($order->getCurrency());
        $tax = Money::zero($order->getCurrency());
        $payload = [];
        foreach ($items as $item) {
            if ($item->getCurrency() !== $order->getCurrency()) {
                throw CommerceException::currencyMismatch();
            }
            $lineSubtotal = $this->moneyPolicy->lineSubtotal($item->getUnitPrice(), $item->getQuantity());
            if (!$lineSubtotal->equals($item->getLineSubtotal())) {
                throw CommerceException::totalMismatch();
            }
            $lineTax = $this->moneyPolicy->lineTax($lineSubtotal, $item->getTaxRateBasisPoints());
            if (!$lineTax->equals($item->getLineTax())) {
                throw CommerceException::totalMismatch();
            }
            if (!$this->moneyPolicy->lineTotal($lineSubtotal, $lineTax)->equals($item->getLineTotal())) {
                throw CommerceException::totalMismatch();
            }
            $subtotal = $subtotal->add($lineSubtotal);
            $tax = $tax->add($lineTax);
            $payload[] = $item->toHashPayload();
        }

        $grandTotal = $this->moneyPolicy->grandTotal($subtotal, $order->getDiscount(), $tax);
        if (!$subtotal->equals($order->getSubtotal())
            || !$tax->equals($order->getTax())
            || !$grandTotal->equals($order->getGrandTotal())
        ) {
            throw CommerceException::totalMismatch();
        }

        $this->orderHasher->verify(
            $order->getOrderHash(),
            $order->getId(),
            $order->getPurchaserType(),
            $order->getUser()?->getId(),
            $order->getInstitution()?->getId(),
            $order->getCurrency(),
            $order->getSubtotalAmountMinor(),
            $order->getDiscountAmountMinor(),
            $order->getTaxAmountMinor(),
            $order->getGrandTotalAmountMinor(),
            $payload,
            $order->getSchemaVersion(),
        );
    }

    /**
     * @param non-empty-list<array{offer: CommercialOffer, quantity: int}> $lines
     */
    private function createOrder(
        User $actor,
        CommercePurchaserType $purchaserType,
        ?User $purchaser,
        ?Institution $institution,
        array $lines,
        string $reasonCode,
        int $discountAmountMinor,
        int $ttlSeconds,
    ): CommerceOrder {
        $reasonCode = CommerceInputNormalizer::reasonCode($reasonCode);
        if ($ttlSeconds < 60 || $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw CommerceException::invalidInput('ttlSeconds must be between 60 and 604800.');
        }
        if (\count($lines) > self::MAX_ITEMS_PER_ORDER) {
            throw CommerceException::invalidInput('An order supports at most 10 lines.');
        }
        if ($discountAmountMinor < 0) {
            throw CommerceException::invalidInput('discountAmountMinor must be >= 0.');
        }

        $requested = [];
        foreach ($lines as $line) {
            $offerId = $line['offer']->getId()->toRfc4122();
            if (isset($requested[$offerId])) {
                throw CommerceException::invalidInput('An order cannot repeat the same offer.');
            }
            $requested[$offerId] = $this->moneyPolicy->assertQuantity($line['quantity']);
        }

        $actorId = $actor->getId();
        $purchaserId = $purchaser?->getId();
        $institutionId = $institution?->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $actorId,
                $purchaserType,
                $purchaserId,
                $institutionId,
                $requested,
                $reasonCode,
                $discountAmountMinor,
                $ttlSeconds,
            ): CommerceOrder {
                $lockedInstitution = null;
                if (null !== $institutionId) {
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        $institutionId,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution
                        || InstitutionStatus::Active !== $lockedInstitution->getStatus()
                    ) {
                        throw CommerceException::notFound();
                    }
                }

                $userIds = [$actorId];
                if (null !== $purchaserId) {
                    $userIds[] = $purchaserId;
                }
                $users = $this->freshEntities->findFreshLockedUsers($userIds, LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw CommerceException::userNotFound();
                }
                $freshPurchaser = null;
                if (null !== $purchaserId) {
                    $freshPurchaser = $users[$purchaserId->toRfc4122()] ?? null;
                    if (!$freshPurchaser instanceof User) {
                        throw CommerceException::userNotFound();
                    }
                }

                if (CommercePurchaserType::User === $purchaserType) {
                    if (!$freshPurchaser instanceof User) {
                        throw CommerceException::invalidInput('User order requires a purchaser user.');
                    }
                    $this->authorization->assertCanPurchaseForSelf($freshActor, $freshPurchaser);
                } else {
                    if (!$lockedInstitution instanceof Institution) {
                        throw CommerceException::invalidInput('Institution order requires an institution.');
                    }
                    $this->authorization->assertCanManageInstitutionCommerce($freshActor, $lockedInstitution);
                }

                $now = $this->utcNow();
                $offers = $this->loadPurchasableOffers($requested, $purchaserType, $now);
                $currency = $this->resolveCurrency($offers);

                $order = CommerceOrder::createDraft(
                    CommerceOrder::generatePublicReference(),
                    $purchaserType,
                    $freshPurchaser,
                    $lockedInstitution,
                    $currency,
                    $freshActor,
                    $now->add(new \DateInterval('PT'.$ttlSeconds.'S')),
                    $now,
                );
                $this->orders->save($order, false);

                $subtotal = Money::zero($currency);
                $tax = Money::zero($currency);
                $payload = [];
                foreach ($offers as $offerId => $offer) {
                    $quantity = $requested[$offerId];
                    $unitPrice = $offer->getPrice();
                    $lineSubtotal = $this->moneyPolicy->lineSubtotal($unitPrice, $quantity);
                    $lineTax = $this->moneyPolicy->lineTax($lineSubtotal, $offer->getTaxRateBasisPoints());
                    $lineTotal = $this->moneyPolicy->lineTotal($lineSubtotal, $lineTax);
                    $item = CommerceOrderItem::create(
                        $order,
                        $offer,
                        $quantity,
                        $unitPrice,
                        $this->moneyPolicy->unitTax($unitPrice, $offer->getTaxRateBasisPoints()),
                        $offer->getTaxRateBasisPoints(),
                        $lineSubtotal,
                        $lineTax,
                        $lineTotal,
                        $offer->getName(),
                        $now,
                    );
                    $this->orderItems->save($item, false);
                    $subtotal = $subtotal->add($lineSubtotal);
                    $tax = $tax->add($lineTax);
                    $payload[] = $item->toHashPayload();
                }

                $discount = Money::fromMinor($discountAmountMinor, $currency);
                $grandTotal = $this->moneyPolicy->grandTotal($subtotal, $discount, $tax);
                $orderHash = $this->orderHasher->hash(
                    $order->getId(),
                    $purchaserType,
                    $freshPurchaser?->getId(),
                    $lockedInstitution?->getId(),
                    $currency,
                    $subtotal->getAmountMinor(),
                    $discount->getAmountMinor(),
                    $tax->getAmountMinor(),
                    $grandTotal->getAmountMinor(),
                    $payload,
                    $order->getSchemaVersion(),
                );
                $order->sealTotals($subtotal, $discount, $tax, $grandTotal, $orderHash, $now);

                $this->recordAudit(
                    SecurityAuditAction::CommerceOrderCreated,
                    $freshActor,
                    SecurityAuditActorType::User,
                    $order,
                    $reasonCode,
                    \count($payload),
                );
                $this->entityManager->flush();

                return $order;
            });
        } catch (CommerceException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }
    }

    /**
     * @param array<string, int> $requested offer RFC4122 => quantity
     *
     * @return array<string, CommercialOffer>
     */
    private function loadPurchasableOffers(
        array $requested,
        CommercePurchaserType $purchaserType,
        \DateTimeImmutable $now,
    ): array {
        $offerIds = array_keys($requested);
        sort($offerIds);

        $offers = [];
        foreach ($offerIds as $offerId) {
            $offer = $this->freshCommerce->findFreshOffer(Uuid::fromString($offerId), LockMode::PESSIMISTIC_READ);
            if (!$offer instanceof CommercialOffer) {
                throw CommerceException::notFound();
            }
            $this->offerManager->assertOfferIntegrity($offer);
            if (!$offer->isPurchasableAt($now)) {
                throw CommerceException::offerRetired();
            }
            if ($offer->getTargetType()->toPurchaserType() !== $purchaserType) {
                throw CommerceException::scopeMismatch('Offer target type does not match the purchaser type.');
            }
            if (CommercialOfferBillingType::Recurring === $offer->getBillingType()
                && 1 !== $requested[$offerId]
            ) {
                throw CommerceException::invalidInput('Recurring offers must be ordered with quantity 1.');
            }

            $version = $this->freshCommerce->findFreshPackageVersion(
                $offer->getPackageVersion()->getId(),
                LockMode::PESSIMISTIC_READ,
            );
            if (!$version instanceof AccessPackageVersion
                || AccessPackageVersionStatus::Active !== $version->getStatus()
            ) {
                throw CommerceException::offerRetired();
            }
            $package = $this->freshCommerce->findFreshPackage(
                $offer->getPackage()->getId(),
                LockMode::PESSIMISTIC_READ,
            );
            if (!$package instanceof AccessPackage || AccessPackageStatus::Active !== $package->getStatus()) {
                throw CommerceException::offerRetired();
            }
            if (!hash_equals($version->getPolicyHash(), $offer->getPackageVersion()->getPolicyHash())) {
                throw CommerceException::hashMismatch();
            }

            $offers[$offerId] = $offer;
        }

        return $offers;
    }

    /**
     * @param array<string, CommercialOffer> $offers
     */
    private function resolveCurrency(array $offers): string
    {
        $currency = null;
        foreach ($offers as $offer) {
            $currency ??= $offer->getCurrency();
            if ($currency !== $offer->getCurrency()) {
                throw CommerceException::currencyMismatch();
            }
        }
        if (null === $currency) {
            throw CommerceException::invalidInput('An order requires at least one line.');
        }

        return $currency;
    }

    private function requireLockedOrder(Uuid $orderId): CommerceOrder
    {
        $locked = $this->freshCommerce->findFreshOrder($orderId, LockMode::PESSIMISTIC_WRITE);
        if (!$locked instanceof CommerceOrder) {
            throw CommerceException::notFound();
        }

        return $locked;
    }

    private function requireFreshActor(Uuid $actorId): User
    {
        $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
        $freshActor = $users[$actorId->toRfc4122()] ?? null;
        if (!$freshActor instanceof User) {
            throw CommerceException::userNotFound();
        }

        return $freshActor;
    }

    private function recordAudit(
        SecurityAuditAction $action,
        ?User $actor,
        SecurityAuditActorType $actorType,
        CommerceOrder $order,
        string $reasonCode,
        ?int $itemCount = null,
    ): void {
        $metadata = [
            'source' => 'commerce_order_manager',
            'reason_code' => $reasonCode,
            'order_id' => $order->getId()->toRfc4122(),
            'order_hash' => $order->getOrderHash(),
            'purchaser_type' => $order->getPurchaserType()->value,
            'institution_id' => $order->getInstitution()?->getId()->toRfc4122(),
            'currency' => $order->getCurrency(),
            'subtotal_amount_minor' => $order->getSubtotalAmountMinor(),
            'discount_amount_minor' => $order->getDiscountAmountMinor(),
            'tax_amount_minor' => $order->getTaxAmountMinor(),
            'grand_total_amount_minor' => $order->getGrandTotalAmountMinor(),
            'status' => $order->getStatus()->value,
            'cancellation_reason_code' => $order->getCancellationReasonCode()?->value,
        ];
        if (null !== $itemCount) {
            $metadata['order_item_count'] = $itemCount;
        }

        $this->auditRecorder->record(new SecurityAuditContext(
            action: $action,
            actorType: $actorType,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $actor,
            metadata: $metadata,
            captureRequestHashes: false,
        ), false);
    }

    private function utcNow(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->clock->now());
    }
}
