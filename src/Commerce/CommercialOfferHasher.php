<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Enum\CommercialOfferBillingInterval;
use App\Enum\CommercialOfferBillingType;
use App\Enum\CommercialOfferTargetType;
use App\Exception\CommerceException;
use App\Question\Content\QuestionContentCanonicalEncoder;
use App\Time\UtcInstant;
use Symfony\Component\Uid\Uuid;

/**
 * SHA-256 over the canonical commercial offer terms (no PII, card data, or secrets).
 *
 * Mirrors {@see \App\Access\AccessPackagePolicyHasher}: the hash freezes the price and
 * tax terms an order was created against so later catalog edits cannot rewrite history.
 */
final class CommercialOfferHasher
{
    public const HASH_HEX_LENGTH = 64;

    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly QuestionContentCanonicalEncoder $encoder,
    ) {
    }

    public function hash(
        Uuid $offerId,
        string $code,
        Uuid $packageId,
        Uuid $packageVersionId,
        CommercialOfferTargetType $targetType,
        CommercialOfferBillingType $billingType,
        ?CommercialOfferBillingInterval $billingInterval,
        int $priceAmountMinor,
        string $currency,
        int $taxRateBasisPoints,
        ?\DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validUntil,
        int $schemaVersion = self::SCHEMA_VERSION,
    ): string {
        return hash('sha256', $this->encoder->encode($this->canonicalPayload(
            $schemaVersion,
            $offerId,
            $code,
            $packageId,
            $packageVersionId,
            $targetType,
            $billingType,
            $billingInterval,
            $priceAmountMinor,
            $currency,
            $taxRateBasisPoints,
            $validFrom,
            $validUntil,
        )));
    }

    public function verify(
        string $storedHash,
        Uuid $offerId,
        string $code,
        Uuid $packageId,
        Uuid $packageVersionId,
        CommercialOfferTargetType $targetType,
        CommercialOfferBillingType $billingType,
        ?CommercialOfferBillingInterval $billingInterval,
        int $priceAmountMinor,
        string $currency,
        int $taxRateBasisPoints,
        ?\DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validUntil,
        int $schemaVersion = self::SCHEMA_VERSION,
    ): void {
        if (1 !== preg_match('/^[0-9a-f]{'.self::HASH_HEX_LENGTH.'}$/', $storedHash)) {
            throw CommerceException::hashMismatch();
        }

        $expected = $this->hash(
            $offerId,
            $code,
            $packageId,
            $packageVersionId,
            $targetType,
            $billingType,
            $billingInterval,
            $priceAmountMinor,
            $currency,
            $taxRateBasisPoints,
            $validFrom,
            $validUntil,
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
        Uuid $offerId,
        string $code,
        Uuid $packageId,
        Uuid $packageVersionId,
        CommercialOfferTargetType $targetType,
        CommercialOfferBillingType $billingType,
        ?CommercialOfferBillingInterval $billingInterval,
        int $priceAmountMinor,
        string $currency,
        int $taxRateBasisPoints,
        ?\DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validUntil,
    ): array {
        return [
            'schemaVersion' => $schemaVersion,
            'offerId' => $offerId->toRfc4122(),
            'code' => $code,
            'packageId' => $packageId->toRfc4122(),
            'packageVersionId' => $packageVersionId->toRfc4122(),
            'targetType' => $targetType->value,
            'billingType' => $billingType->value,
            'billingInterval' => $billingInterval?->value,
            'priceAmountMinor' => $priceAmountMinor,
            'currency' => $currency,
            'taxRateBasisPoints' => $taxRateBasisPoints,
            'taxMode' => 'exclusive',
            'validFrom' => CommerceCanonicalInstant::format($validFrom),
            'validUntil' => CommerceCanonicalInstant::format($validUntil),
        ];
    }

    public static function assertHash(string $hash): string
    {
        if (1 !== preg_match('/^[0-9a-f]{'.self::HASH_HEX_LENGTH.'}$/', $hash)) {
            throw CommerceException::invalidInput('offerHash must be 64 lowercase hex characters.');
        }

        return $hash;
    }

    /**
     * @internal keeps UTC normalization identical between hashing and persistence
     */
    public static function normalizeInstant(?\DateTimeInterface $value): ?\DateTimeImmutable
    {
        return $value instanceof \DateTimeInterface ? UtcInstant::ensure($value) : null;
    }
}
