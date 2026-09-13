<?php

declare(strict_types=1);

namespace App\Service;

use App\Commerce\CommerceInputNormalizer;
use App\Commerce\CommerceMoneyPolicy;
use App\Commerce\CommercialOfferHasher;
use App\Dto\SecurityAuditContext;
use App\Entity\AccessPackage;
use App\Entity\AccessPackageVersion;
use App\Entity\CommercialOffer;
use App\Entity\User;
use App\Enum\AccessPackageStatus;
use App\Enum\AccessPackageVersionStatus;
use App\Enum\CommercialOfferBillingInterval;
use App\Enum\CommercialOfferBillingType;
use App\Enum\CommercialOfferStatus;
use App\Enum\CommercialOfferTargetType;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CommerceException;
use App\Money\Money;
use App\Repository\CommercialOfferRepository;
use App\Security\CommerceAuthorization;
use App\Time\UtcInstant;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Commercial offer catalog lifecycle (draft → active → retired). SUPER_ADMIN only.
 *
 * Lock order: CommercialOffer → AccessPackage → AccessPackageVersion → Users → Audit
 */
final class CommercialOfferManager
{
    public function __construct(
        private readonly CommercialOfferRepository $offers,
        private readonly CommerceAuthorization $authorization,
        private readonly CommercialOfferHasher $offerHasher,
        private readonly CommerceMoneyPolicy $moneyPolicy,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly CommerceFreshEntityLoader $freshCommerce,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createDraft(
        User $actor,
        AccessPackageVersion $packageVersion,
        string $code,
        string $name,
        ?string $description,
        CommercialOfferBillingType $billingType,
        ?CommercialOfferBillingInterval $billingInterval,
        int $priceAmountMinor,
        string $currency,
        int $taxRateBasisPoints,
        string $reasonCode,
        ?\DateTimeImmutable $validFrom = null,
        ?\DateTimeImmutable $validUntil = null,
    ): CommercialOffer {
        $reasonCode = CommerceInputNormalizer::reasonCode($reasonCode);
        $code = CommerceInputNormalizer::offerCode($code);
        $name = CommerceInputNormalizer::displayName($name);
        $description = CommerceInputNormalizer::description($description);
        $currency = CommerceInputNormalizer::currency($currency);
        $this->moneyPolicy->assertTaxRateBasisPoints($taxRateBasisPoints);
        $validFrom = CommercialOfferHasher::normalizeInstant($validFrom);
        $validUntil = CommercialOfferHasher::normalizeInstant($validUntil);
        $versionId = $packageVersion->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $actorId,
                $versionId,
                $code,
                $name,
                $description,
                $billingType,
                $billingInterval,
                $priceAmountMinor,
                $currency,
                $taxRateBasisPoints,
                $reasonCode,
                $validFrom,
                $validUntil,
            ): CommercialOffer {
                $freshActor = $this->requireFreshActor($actorId);
                $this->authorization->assertCanManageCatalog($freshActor);

                $version = $this->freshCommerce->findFreshPackageVersion($versionId, LockMode::PESSIMISTIC_READ);
                if (!$version instanceof AccessPackageVersion) {
                    throw CommerceException::notFound();
                }
                if (AccessPackageVersionStatus::Active !== $version->getStatus()) {
                    throw CommerceException::invalidInput('Offers require an active package version.');
                }
                $package = $this->freshCommerce->findFreshPackage(
                    $version->getPackage()->getId(),
                    LockMode::PESSIMISTIC_READ,
                );
                if (!$package instanceof AccessPackage) {
                    throw CommerceException::notFound();
                }
                if (AccessPackageStatus::Active !== $package->getStatus()) {
                    throw CommerceException::invalidInput('Offers require an active package.');
                }
                $this->assertPurchaseValidityPolicy($package, $version, $billingType);

                $targetType = CommercialOfferTargetType::from($package->getTargetType()->value);
                $price = Money::fromMinor($priceAmountMinor, $currency);
                $now = $this->utcNow();
                $offerId = Uuid::v7();
                $offerHash = $this->offerHasher->hash(
                    $offerId,
                    $code,
                    $package->getId(),
                    $version->getId(),
                    $targetType,
                    $billingType,
                    $billingInterval,
                    $price->getAmountMinor(),
                    $price->getCurrency(),
                    $taxRateBasisPoints,
                    $validFrom,
                    $validUntil,
                );

                $offer = CommercialOffer::createDraft(
                    $code,
                    $name,
                    $description,
                    $package,
                    $version,
                    $targetType,
                    $billingType,
                    $billingInterval,
                    $price,
                    $taxRateBasisPoints,
                    $validFrom,
                    $validUntil,
                    $offerHash,
                    $freshActor,
                    $now,
                    CommercialOfferHasher::SCHEMA_VERSION,
                    $offerId,
                );
                $this->offers->save($offer, false);

                $this->recordAudit(
                    SecurityAuditAction::CommercialOfferCreated,
                    $freshActor,
                    $offer,
                    $reasonCode,
                );
                $this->entityManager->flush();

                return $offer;
            });
        } catch (CommerceException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }
    }

    public function updateDraft(
        CommercialOffer $offer,
        User $actor,
        string $name,
        ?string $description,
        CommercialOfferBillingType $billingType,
        ?CommercialOfferBillingInterval $billingInterval,
        int $priceAmountMinor,
        int $taxRateBasisPoints,
        string $reasonCode,
        ?\DateTimeImmutable $validFrom = null,
        ?\DateTimeImmutable $validUntil = null,
    ): CommercialOffer {
        $reasonCode = CommerceInputNormalizer::reasonCode($reasonCode);
        $name = CommerceInputNormalizer::displayName($name);
        $description = CommerceInputNormalizer::description($description);
        $this->moneyPolicy->assertTaxRateBasisPoints($taxRateBasisPoints);
        $validFrom = CommercialOfferHasher::normalizeInstant($validFrom);
        $validUntil = CommercialOfferHasher::normalizeInstant($validUntil);
        $offerId = $offer->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $offerId,
                $actorId,
                $name,
                $description,
                $billingType,
                $billingInterval,
                $priceAmountMinor,
                $taxRateBasisPoints,
                $reasonCode,
                $validFrom,
                $validUntil,
            ): CommercialOffer {
                $locked = $this->requireLockedOffer($offerId);
                $freshActor = $this->requireFreshActor($actorId);
                $this->authorization->assertCanManageCatalog($freshActor);
                if (CommercialOfferStatus::Draft !== $locked->getStatus()) {
                    throw CommerceException::invalidTransition();
                }
                $this->assertPurchaseValidityPolicy(
                    $locked->getPackage(),
                    $locked->getPackageVersion(),
                    $billingType,
                );

                $price = Money::fromMinor($priceAmountMinor, $locked->getCurrency());
                $offerHash = $this->offerHasher->hash(
                    $locked->getId(),
                    $locked->getCode(),
                    $locked->getPackage()->getId(),
                    $locked->getPackageVersion()->getId(),
                    $locked->getTargetType(),
                    $billingType,
                    $billingInterval,
                    $price->getAmountMinor(),
                    $price->getCurrency(),
                    $taxRateBasisPoints,
                    $validFrom,
                    $validUntil,
                    $locked->getSchemaVersion(),
                );

                $locked->updateDraft(
                    $name,
                    $description,
                    $billingType,
                    $billingInterval,
                    $price,
                    $taxRateBasisPoints,
                    $validFrom,
                    $validUntil,
                    $offerHash,
                    $this->utcNow(),
                );

                $this->recordAudit(
                    SecurityAuditAction::CommercialOfferUpdated,
                    $freshActor,
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

    public function activate(CommercialOffer $offer, User $actor, string $reasonCode): CommercialOffer
    {
        return $this->transition(
            $offer,
            $actor,
            $reasonCode,
            SecurityAuditAction::CommercialOfferActivated,
            function (CommercialOffer $locked, User $freshActor, \DateTimeImmutable $now): void {
                $this->assertOfferIntegrity($locked);
                $version = $this->freshCommerce->findFreshPackageVersion(
                    $locked->getPackageVersion()->getId(),
                    LockMode::PESSIMISTIC_READ,
                );
                if (!$version instanceof AccessPackageVersion
                    || AccessPackageVersionStatus::Active !== $version->getStatus()
                ) {
                    throw CommerceException::invalidInput('Offer activation requires an active package version.');
                }
                $package = $this->freshCommerce->findFreshPackage(
                    $locked->getPackage()->getId(),
                    LockMode::PESSIMISTIC_READ,
                );
                if (!$package instanceof AccessPackage
                    || AccessPackageStatus::Active !== $package->getStatus()
                ) {
                    throw CommerceException::invalidInput('Offer activation requires an active package.');
                }
                if (!hash_equals($version->getPolicyHash(), $locked->getPackageVersion()->getPolicyHash())) {
                    throw CommerceException::hashMismatch();
                }
                $locked->activate($freshActor, $now);
            },
        );
    }

    public function retire(CommercialOffer $offer, User $actor, string $reasonCode): CommercialOffer
    {
        return $this->transition(
            $offer,
            $actor,
            $reasonCode,
            SecurityAuditAction::CommercialOfferRetired,
            static function (CommercialOffer $locked, User $freshActor, \DateTimeImmutable $now): void {
                $locked->retire($freshActor, $now);
            },
        );
    }

    /**
     * Recomputes and hash_equals-verifies the stored offer hash from fresh catalog identity
     * and offer scalar fields. Never compares only a stored offer hash to an order-item
     * snapshot — callers still hash_equals the item snapshot against `$offer->getOfferHash()`
     * after this method proves the offer row itself is intact.
     *
     * Offer status is intentionally ignored: a sealed order may still fulfill after the offer
     * is retired, because checkout already froze the commercial terms in the order item.
     */
    public function assertOfferIntegrity(CommercialOffer $offer): void
    {
        $package = $this->freshCommerce->findFreshPackage(
            $offer->getPackage()->getId(),
            LockMode::NONE,
        );
        if (!$package instanceof AccessPackage) {
            throw CommerceException::notFound();
        }
        $version = $this->freshCommerce->findFreshPackageVersion(
            $offer->getPackageVersion()->getId(),
            LockMode::NONE,
        );
        if (!$version instanceof AccessPackageVersion) {
            throw CommerceException::notFound();
        }
        if (!$version->getPackage()->getId()->equals($package->getId())) {
            throw CommerceException::scopeMismatch('Offer package version does not belong to the offer package.');
        }
        if (!$offer->getTargetType()->matchesPackageTarget($package->getTargetType())) {
            throw CommerceException::scopeMismatch('Offer target type does not match the package target type.');
        }

        $this->offerHasher->verify(
            $offer->getOfferHash(),
            $offer->getId(),
            $offer->getCode(),
            $package->getId(),
            $version->getId(),
            $offer->getTargetType(),
            $offer->getBillingType(),
            $offer->getBillingInterval(),
            $offer->getPriceAmountMinor(),
            $offer->getCurrency(),
            $offer->getTaxRateBasisPoints(),
            $offer->getValidFrom(),
            $offer->getValidUntil(),
            $offer->getSchemaVersion(),
        );
    }

    /**
     * @param callable(CommercialOffer, User, \DateTimeImmutable): void $mutator
     */
    private function transition(
        CommercialOffer $offer,
        User $actor,
        string $reasonCode,
        SecurityAuditAction $action,
        callable $mutator,
    ): CommercialOffer {
        $reasonCode = CommerceInputNormalizer::reasonCode($reasonCode);
        $offerId = $offer->getId();
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use (
                $offerId,
                $actorId,
                $reasonCode,
                $action,
                $mutator,
            ): CommercialOffer {
                $locked = $this->requireLockedOffer($offerId);
                $freshActor = $this->requireFreshActor($actorId);
                $this->authorization->assertCanManageCatalog($freshActor);

                $mutator($locked, $freshActor, $this->utcNow());

                $this->recordAudit($action, $freshActor, $locked, $reasonCode);
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
     * Commercial fulfillment must be able to derive a bounded license window from the
     * package graph. Requiring it at catalog time avoids ambiguous NULL validity later.
     */
    private function assertPurchaseValidityPolicy(
        AccessPackage $package,
        AccessPackageVersion $version,
        CommercialOfferBillingType $billingType,
    ): void {
        if (CommercialOfferBillingType::OneTime !== $billingType) {
            return;
        }
        if (null === ($version->getValidityDays() ?? $package->getDefaultValidityDays())) {
            throw CommerceException::validityPolicyMissing();
        }
    }

    private function requireLockedOffer(Uuid $offerId): CommercialOffer
    {
        $locked = $this->freshCommerce->findFreshOffer($offerId, LockMode::PESSIMISTIC_WRITE);
        if (!$locked instanceof CommercialOffer) {
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
        User $actor,
        CommercialOffer $offer,
        string $reasonCode,
    ): void {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: $action,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $actor,
            metadata: [
                'source' => 'commercial_offer_manager',
                'reason_code' => $reasonCode,
                'offer_id' => $offer->getId()->toRfc4122(),
                'offer_code' => $offer->getCode(),
                'offer_hash' => $offer->getOfferHash(),
                'package_id' => $offer->getPackage()->getId()->toRfc4122(),
                'package_version_id' => $offer->getPackageVersion()->getId()->toRfc4122(),
                'target_type' => $offer->getTargetType()->value,
                'billing_type' => $offer->getBillingType()->value,
                'billing_interval' => $offer->getBillingInterval()?->value,
                'currency' => $offer->getCurrency(),
                'amount_minor' => $offer->getPriceAmountMinor(),
                'tax_rate_basis_points' => $offer->getTaxRateBasisPoints(),
                'status' => $offer->getStatus()->value,
                'schema_version' => $offer->getSchemaVersion(),
            ],
            captureRequestHashes: false,
        ), false);
    }

    private function utcNow(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->clock->now());
    }
}
