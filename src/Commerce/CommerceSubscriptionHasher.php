<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Enum\CommerceSubscriberType;
use App\Enum\CommercialOfferBillingInterval;
use App\Exception\CommerceException;
use App\Question\Content\QuestionContentCanonicalEncoder;
use Symfony\Component\Uid\Uuid;

/**
 * SHA-256 over the canonical subscription terms and current billing period.
 *
 * Recomputed before a recurring fulfillment grants a period-scoped license, so a
 * silently widened period window cannot extend access.
 */
final class CommerceSubscriptionHasher
{
    public const HASH_HEX_LENGTH = 64;

    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly QuestionContentCanonicalEncoder $encoder,
    ) {
    }

    public function hash(
        Uuid $subscriptionId,
        CommerceSubscriberType $subscriberType,
        ?Uuid $userId,
        ?Uuid $institutionId,
        Uuid $offerId,
        Uuid $packageId,
        Uuid $packageVersionId,
        CommercialOfferBillingInterval $billingInterval,
        \DateTimeImmutable $currentPeriodStart,
        \DateTimeImmutable $currentPeriodEnd,
        int $schemaVersion = self::SCHEMA_VERSION,
    ): string {
        return hash('sha256', $this->encoder->encode($this->canonicalPayload(
            $schemaVersion,
            $subscriptionId,
            $subscriberType,
            $userId,
            $institutionId,
            $offerId,
            $packageId,
            $packageVersionId,
            $billingInterval,
            $currentPeriodStart,
            $currentPeriodEnd,
        )));
    }

    public function verify(
        string $storedHash,
        Uuid $subscriptionId,
        CommerceSubscriberType $subscriberType,
        ?Uuid $userId,
        ?Uuid $institutionId,
        Uuid $offerId,
        Uuid $packageId,
        Uuid $packageVersionId,
        CommercialOfferBillingInterval $billingInterval,
        \DateTimeImmutable $currentPeriodStart,
        \DateTimeImmutable $currentPeriodEnd,
        int $schemaVersion = self::SCHEMA_VERSION,
    ): void {
        if (1 !== preg_match('/^[0-9a-f]{'.self::HASH_HEX_LENGTH.'}$/', $storedHash)) {
            throw CommerceException::hashMismatch();
        }

        $expected = $this->hash(
            $subscriptionId,
            $subscriberType,
            $userId,
            $institutionId,
            $offerId,
            $packageId,
            $packageVersionId,
            $billingInterval,
            $currentPeriodStart,
            $currentPeriodEnd,
            $schemaVersion,
        );
        if (!hash_equals($expected, $storedHash)) {
            throw CommerceException::hashMismatch();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalPayload(
        int $schemaVersion,
        Uuid $subscriptionId,
        CommerceSubscriberType $subscriberType,
        ?Uuid $userId,
        ?Uuid $institutionId,
        Uuid $offerId,
        Uuid $packageId,
        Uuid $packageVersionId,
        CommercialOfferBillingInterval $billingInterval,
        \DateTimeImmutable $currentPeriodStart,
        \DateTimeImmutable $currentPeriodEnd,
    ): array {
        return [
            'schemaVersion' => $schemaVersion,
            'subscriptionId' => $subscriptionId->toRfc4122(),
            'subscriberType' => $subscriberType->value,
            'userId' => $userId?->toRfc4122(),
            'institutionId' => $institutionId?->toRfc4122(),
            'offerId' => $offerId->toRfc4122(),
            'packageId' => $packageId->toRfc4122(),
            'packageVersionId' => $packageVersionId->toRfc4122(),
            'billingInterval' => $billingInterval->value,
            'currentPeriodStart' => CommerceCanonicalInstant::format($currentPeriodStart),
            'currentPeriodEnd' => CommerceCanonicalInstant::format($currentPeriodEnd),
        ];
    }

    public static function assertHash(string $hash): string
    {
        if (1 !== preg_match('/^[0-9a-f]{'.self::HASH_HEX_LENGTH.'}$/', $hash)) {
            throw CommerceException::invalidInput('subscriptionHash must be 64 lowercase hex characters.');
        }

        return $hash;
    }
}
