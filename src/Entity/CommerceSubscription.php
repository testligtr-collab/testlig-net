<?php

declare(strict_types=1);

namespace App\Entity;

use App\Commerce\CommerceSubscriptionHasher;
use App\Enum\CommerceCancellationReasonCode;
use App\Enum\CommerceSubscriberType;
use App\Enum\CommerceSubscriptionStatus;
use App\Enum\CommercialOfferBillingInterval;
use App\Enum\CommercialOfferBillingType;
use App\Exception\CommerceException;
use App\Repository\CommerceSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Recurring billing agreement for one subscriber (user XOR institution).
 *
 * `currentPeriodEnd` is an exclusive upper bound, matching AccessLicense validity
 * semantics: currentPeriodStart <= now < currentPeriodEnd. Each paid period gets its own
 * period-scoped AccessLicense (see CommerceFulfillmentManager) instead of silently
 * extending an existing license.
 */
#[ORM\Entity(repositoryClass: CommerceSubscriptionRepository::class)]
#[ORM\Table(name: 'commerce_subscriptions')]
#[ORM\UniqueConstraint(name: 'uniq_cs_order_offer', columns: ['order_id', 'offer_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cs_id_order', columns: ['id', 'order_id'])]
#[ORM\UniqueConstraint(name: 'uniq_cs_provider_reference', columns: ['provider_code', 'provider_subscription_reference'])]
#[ORM\Index(name: 'idx_cs_user_status', columns: ['user_id', 'status'])]
#[ORM\Index(name: 'idx_cs_institution_status', columns: ['institution_id', 'status'])]
#[ORM\Index(name: 'idx_cs_period', columns: ['current_period_start', 'current_period_end'])]
class CommerceSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'subscriber_type', length: 32, enumType: CommerceSubscriberType::class)]
    private CommerceSubscriberType $subscriberType;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Institution $institution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CommerceOrder $order;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'offer_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialOffer $offer;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'package_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AccessPackage $package;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'package_version_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AccessPackageVersion $packageVersion;

    #[ORM\Column(name: 'billing_interval', length: 32, enumType: CommercialOfferBillingInterval::class)]
    private CommercialOfferBillingInterval $billingInterval;

    #[ORM\Column(length: 32, enumType: CommerceSubscriptionStatus::class)]
    private CommerceSubscriptionStatus $status;

    #[ORM\Column(name: 'current_period_start', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $currentPeriodStart;

    #[ORM\Column(name: 'current_period_end', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $currentPeriodEnd;

    #[ORM\Column(name: 'period_number')]
    private int $periodNumber;

    #[ORM\Column(name: 'cancel_at_period_end')]
    private bool $cancelAtPeriodEnd;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(
        name: 'cancellation_reason_code',
        length: 64,
        nullable: true,
        enumType: CommerceCancellationReasonCode::class,
    )]
    private ?CommerceCancellationReasonCode $cancellationReasonCode = null;

    #[ORM\Column(name: 'provider_code', length: 32, nullable: true)]
    private ?string $providerCode;

    #[ORM\Column(name: 'provider_subscription_reference', length: 128, nullable: true)]
    private ?string $providerSubscriptionReference;

    #[ORM\Column(name: 'subscription_hash', length: 64)]
    private string $subscriptionHash;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'activated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(name: 'expired_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiredAt = null;

    private function __construct(
        CommerceSubscriberType $subscriberType,
        ?User $user,
        ?Institution $institution,
        CommerceOrder $order,
        CommercialOffer $offer,
        \DateTimeImmutable $currentPeriodStart,
        \DateTimeImmutable $currentPeriodEnd,
        ?string $providerCode,
        ?string $providerSubscriptionReference,
        string $subscriptionHash,
        \DateTimeImmutable $now,
        int $schemaVersion,
        ?Uuid $id = null,
    ) {
        self::assertSubscriberPair($subscriberType, $user, $institution);
        if (CommercialOfferBillingType::Recurring !== $offer->getBillingType()) {
            throw CommerceException::scopeMismatch('Subscriptions require a recurring offer.');
        }
        $billingInterval = $offer->getBillingInterval();
        if (!$billingInterval instanceof CommercialOfferBillingInterval) {
            throw CommerceException::invalidInput('Recurring offers require a billing interval.');
        }
        if ($currentPeriodEnd <= $currentPeriodStart) {
            throw CommerceException::invalidInput('currentPeriodEnd must be after currentPeriodStart.');
        }
        if ((null === $providerCode) !== (null === $providerSubscriptionReference)) {
            throw CommerceException::invalidInput('Provider code and subscription reference must be set together.');
        }
        if (null !== $providerCode && 1 !== preg_match(PaymentAttempt::PROVIDER_CODE_PATTERN, $providerCode)) {
            throw CommerceException::invalidInput('providerCode must be snake_case (2-32 characters).');
        }
        if (null !== $providerSubscriptionReference) {
            PaymentAttempt::assertProviderReference($providerSubscriptionReference);
        }
        CommerceSubscriptionHasher::assertHash($subscriptionHash);

        $this->id = $id ?? new UuidV7();
        $this->subscriberType = $subscriberType;
        $this->user = $user;
        $this->institution = $institution;
        $this->order = $order;
        $this->offer = $offer;
        $this->package = $offer->getPackage();
        $this->packageVersion = $offer->getPackageVersion();
        $this->billingInterval = $billingInterval;
        $this->status = CommerceSubscriptionStatus::Pending;
        $this->currentPeriodStart = $currentPeriodStart;
        $this->currentPeriodEnd = $currentPeriodEnd;
        $this->periodNumber = 1;
        $this->cancelAtPeriodEnd = false;
        $this->providerCode = $providerCode;
        $this->providerSubscriptionReference = $providerSubscriptionReference;
        $this->subscriptionHash = $subscriptionHash;
        $this->schemaVersion = $schemaVersion;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer CommerceSubscriptionManager
     */
    public static function createPending(
        CommerceSubscriberType $subscriberType,
        ?User $user,
        ?Institution $institution,
        CommerceOrder $order,
        CommercialOffer $offer,
        \DateTimeImmutable $currentPeriodStart,
        \DateTimeImmutable $currentPeriodEnd,
        ?string $providerCode,
        ?string $providerSubscriptionReference,
        string $subscriptionHash,
        \DateTimeImmutable $now,
        int $schemaVersion = CommerceSubscriptionHasher::SCHEMA_VERSION,
        ?Uuid $id = null,
    ): self {
        return new self(
            $subscriberType,
            $user,
            $institution,
            $order,
            $offer,
            $currentPeriodStart,
            $currentPeriodEnd,
            $providerCode,
            $providerSubscriptionReference,
            $subscriptionHash,
            $now,
            $schemaVersion,
            $id,
        );
    }

    public function activate(\DateTimeImmutable $activatedAt): void
    {
        if (CommerceSubscriptionStatus::Pending !== $this->status) {
            throw CommerceException::invalidTransition();
        }
        $this->status = CommerceSubscriptionStatus::Active;
        $this->activatedAt = $activatedAt;
        $this->updatedAt = $activatedAt;
    }

    public function markPastDue(\DateTimeImmutable $now): void
    {
        if (CommerceSubscriptionStatus::Active !== $this->status) {
            throw CommerceException::invalidTransition();
        }
        $this->status = CommerceSubscriptionStatus::PastDue;
        $this->updatedAt = $now;
    }

    /**
     * Rolls the billing window forward. Callers must pass a hash recomputed for the new period.
     */
    public function advancePeriod(
        \DateTimeImmutable $nextPeriodStart,
        \DateTimeImmutable $nextPeriodEnd,
        string $subscriptionHash,
        \DateTimeImmutable $now,
    ): void {
        if (!$this->status->allowsRenewal()) {
            throw CommerceException::invalidTransition();
        }
        if ($this->cancelAtPeriodEnd) {
            throw CommerceException::invalidTransition();
        }
        if ($nextPeriodStart < $this->currentPeriodEnd) {
            throw CommerceException::invalidInput('Next period must start at or after the current period end.');
        }
        if ($nextPeriodEnd <= $nextPeriodStart) {
            throw CommerceException::invalidInput('currentPeriodEnd must be after currentPeriodStart.');
        }
        CommerceSubscriptionHasher::assertHash($subscriptionHash);

        $this->currentPeriodStart = $nextPeriodStart;
        $this->currentPeriodEnd = $nextPeriodEnd;
        ++$this->periodNumber;
        $this->status = CommerceSubscriptionStatus::Active;
        $this->subscriptionHash = $subscriptionHash;
        $this->updatedAt = $now;
    }

    public function scheduleCancellation(
        CommerceCancellationReasonCode $reasonCode,
        \DateTimeImmutable $now,
    ): void {
        if ($this->status->isTerminal()) {
            throw CommerceException::invalidTransition();
        }
        $this->cancelAtPeriodEnd = true;
        $this->cancellationReasonCode = $reasonCode;
        $this->updatedAt = $now;
    }

    public function cancelNow(CommerceCancellationReasonCode $reasonCode, \DateTimeImmutable $cancelledAt): void
    {
        if ($this->status->isTerminal()) {
            throw CommerceException::invalidTransition();
        }
        $this->status = CommerceSubscriptionStatus::Cancelled;
        $this->cancelAtPeriodEnd = false;
        $this->cancellationReasonCode = $reasonCode;
        $this->cancelledAt = $cancelledAt;
        $this->updatedAt = $cancelledAt;
    }

    public function markExpired(\DateTimeImmutable $expiredAt): void
    {
        if ($this->status->isTerminal()) {
            throw CommerceException::invalidTransition();
        }
        if ($expiredAt < $this->currentPeriodEnd) {
            throw CommerceException::invalidTransition();
        }
        $this->status = CommerceSubscriptionStatus::Expired;
        $this->expiredAt = $expiredAt;
        $this->updatedAt = $expiredAt;
    }

    public function isWithinCurrentPeriod(\DateTimeImmutable $now): bool
    {
        return $this->currentPeriodStart <= $now && $now < $this->currentPeriodEnd;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSubscriberType(): CommerceSubscriberType
    {
        return $this->subscriberType;
    }

    #[Ignore]
    public function getUser(): ?User
    {
        return $this->user;
    }

    #[Ignore]
    public function getInstitution(): ?Institution
    {
        return $this->institution;
    }

    #[Ignore]
    public function getOrder(): CommerceOrder
    {
        return $this->order;
    }

    #[Ignore]
    public function getOffer(): CommercialOffer
    {
        return $this->offer;
    }

    #[Ignore]
    public function getPackage(): AccessPackage
    {
        return $this->package;
    }

    #[Ignore]
    public function getPackageVersion(): AccessPackageVersion
    {
        return $this->packageVersion;
    }

    public function getBillingInterval(): CommercialOfferBillingInterval
    {
        return $this->billingInterval;
    }

    public function getStatus(): CommerceSubscriptionStatus
    {
        return $this->status;
    }

    public function getCurrentPeriodStart(): \DateTimeImmutable
    {
        return $this->currentPeriodStart;
    }

    public function getCurrentPeriodEnd(): \DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function getPeriodNumber(): int
    {
        return $this->periodNumber;
    }

    public function isCancelAtPeriodEnd(): bool
    {
        return $this->cancelAtPeriodEnd;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getCancellationReasonCode(): ?CommerceCancellationReasonCode
    {
        return $this->cancellationReasonCode;
    }

    public function getProviderCode(): ?string
    {
        return $this->providerCode;
    }

    public function getProviderSubscriptionReference(): ?string
    {
        return $this->providerSubscriptionReference;
    }

    public function getSubscriptionHash(): string
    {
        return $this->subscriptionHash;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function getExpiredAt(): ?\DateTimeImmutable
    {
        return $this->expiredAt;
    }

    private static function assertSubscriberPair(
        CommerceSubscriberType $subscriberType,
        ?User $user,
        ?Institution $institution,
    ): void {
        if (CommerceSubscriberType::User === $subscriberType) {
            if (!$user instanceof User || null !== $institution) {
                throw CommerceException::invalidInput('User subscriptions require user and null institution.');
            }

            return;
        }
        if (!$institution instanceof Institution || null !== $user) {
            throw CommerceException::invalidInput('Institution subscriptions require institution and null user.');
        }
    }
}
