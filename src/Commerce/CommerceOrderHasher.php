<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Enum\CommercePurchaserType;
use App\Exception\CommerceException;
use App\Question\Content\QuestionContentCanonicalEncoder;
use Symfony\Component\Uid\Uuid;

/**
 * SHA-256 over the canonical order snapshot: purchaser scope, currency, integer totals,
 * and each line's frozen offer / package-policy hashes.
 *
 * The public reference, buyer email, and any provider reference are deliberately absent —
 * the hash proves "these amounts for this purchaser and these offers", nothing else.
 *
 * @phpstan-type OrderItemPayload array{
 *     offerId: string,
 *     packageId: string,
 *     packageVersionId: string,
 *     quantity: int,
 *     unitPriceAmountMinor: int,
 *     taxRateBasisPoints: int,
 *     lineSubtotalAmountMinor: int,
 *     lineTaxAmountMinor: int,
 *     lineTotalAmountMinor: int,
 *     offerSnapshotHash: string,
 *     packagePolicySnapshotHash: string
 * }
 */
final class CommerceOrderHasher
{
    public const HASH_HEX_LENGTH = 64;

    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly QuestionContentCanonicalEncoder $encoder,
    ) {
    }

    /**
     * @param list<OrderItemPayload> $items
     */
    public function hash(
        Uuid $orderId,
        CommercePurchaserType $purchaserType,
        ?Uuid $userId,
        ?Uuid $institutionId,
        string $currency,
        int $subtotalAmountMinor,
        int $discountAmountMinor,
        int $taxAmountMinor,
        int $grandTotalAmountMinor,
        array $items,
        int $schemaVersion = self::SCHEMA_VERSION,
    ): string {
        return hash('sha256', $this->encoder->encode($this->canonicalPayload(
            $schemaVersion,
            $orderId,
            $purchaserType,
            $userId,
            $institutionId,
            $currency,
            $subtotalAmountMinor,
            $discountAmountMinor,
            $taxAmountMinor,
            $grandTotalAmountMinor,
            $items,
        )));
    }

    /**
     * @param list<OrderItemPayload> $items
     */
    public function verify(
        string $storedHash,
        Uuid $orderId,
        CommercePurchaserType $purchaserType,
        ?Uuid $userId,
        ?Uuid $institutionId,
        string $currency,
        int $subtotalAmountMinor,
        int $discountAmountMinor,
        int $taxAmountMinor,
        int $grandTotalAmountMinor,
        array $items,
        int $schemaVersion = self::SCHEMA_VERSION,
    ): void {
        if (1 !== preg_match('/^[0-9a-f]{'.self::HASH_HEX_LENGTH.'}$/', $storedHash)) {
            throw CommerceException::hashMismatch();
        }

        $expected = $this->hash(
            $orderId,
            $purchaserType,
            $userId,
            $institutionId,
            $currency,
            $subtotalAmountMinor,
            $discountAmountMinor,
            $taxAmountMinor,
            $grandTotalAmountMinor,
            $items,
            $schemaVersion,
        );
        if (!hash_equals($expected, $storedHash)) {
            throw CommerceException::hashMismatch();
        }
    }

    /**
     * @param list<OrderItemPayload> $items
     *
     * @return array<string, mixed>
     */
    private function canonicalPayload(
        int $schemaVersion,
        Uuid $orderId,
        CommercePurchaserType $purchaserType,
        ?Uuid $userId,
        ?Uuid $institutionId,
        string $currency,
        int $subtotalAmountMinor,
        int $discountAmountMinor,
        int $taxAmountMinor,
        int $grandTotalAmountMinor,
        array $items,
    ): array {
        $sorted = $items;
        usort($sorted, static fn (array $a, array $b): int => $a['offerId'] <=> $b['offerId']);

        return [
            'schemaVersion' => $schemaVersion,
            'orderId' => $orderId->toRfc4122(),
            'purchaserType' => $purchaserType->value,
            'userId' => $userId?->toRfc4122(),
            'institutionId' => $institutionId?->toRfc4122(),
            'currency' => $currency,
            'taxMode' => 'exclusive',
            'subtotalAmountMinor' => $subtotalAmountMinor,
            'discountAmountMinor' => $discountAmountMinor,
            'taxAmountMinor' => $taxAmountMinor,
            'grandTotalAmountMinor' => $grandTotalAmountMinor,
            'items' => $sorted,
        ];
    }

    public static function assertHash(string $hash): string
    {
        if (1 !== preg_match('/^[0-9a-f]{'.self::HASH_HEX_LENGTH.'}$/', $hash)) {
            throw CommerceException::invalidInput('orderHash must be 64 lowercase hex characters.');
        }

        return $hash;
    }
}
