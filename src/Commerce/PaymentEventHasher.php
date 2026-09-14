<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Enum\PaymentEventType;
use App\Exception\CommerceException;
use App\Question\Content\QuestionContentCanonicalEncoder;
use Symfony\Component\Uid\Uuid;

/**
 * Hash chain over the append-only payment event log.
 *
 * Each event hashes its own canonical payload plus the previous event hash for the same
 * attempt, so removing or rewriting a settlement event breaks the chain. Only sanitized
 * metadata participates — no raw provider payloads, card data, or idempotency keys.
 */
final class PaymentEventHasher
{
    public const HASH_HEX_LENGTH = 64;

    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly QuestionContentCanonicalEncoder $encoder,
    ) {
    }

    /**
     * @param array<string, bool|int|string|null> $sanitizedMetadata
     */
    public function hash(
        Uuid $attemptId,
        int $sequenceNumber,
        PaymentEventType $eventType,
        ?string $providerEventReference,
        \DateTimeImmutable $occurredAt,
        ?int $amountMinor,
        ?string $currency,
        array $sanitizedMetadata,
        ?string $previousEventHash,
        int $schemaVersion = self::SCHEMA_VERSION,
    ): string {
        return hash('sha256', $this->encoder->encode($this->canonicalPayload(
            $schemaVersion,
            $attemptId,
            $sequenceNumber,
            $eventType,
            $providerEventReference,
            $occurredAt,
            $amountMinor,
            $currency,
            $sanitizedMetadata,
            $previousEventHash,
        )));
    }

    /**
     * @param array<string, bool|int|string|null> $sanitizedMetadata
     */
    public function verify(
        string $storedHash,
        Uuid $attemptId,
        int $sequenceNumber,
        PaymentEventType $eventType,
        ?string $providerEventReference,
        \DateTimeImmutable $occurredAt,
        ?int $amountMinor,
        ?string $currency,
        array $sanitizedMetadata,
        ?string $previousEventHash,
        int $schemaVersion = self::SCHEMA_VERSION,
    ): void {
        if (1 !== preg_match('/^[0-9a-f]{'.self::HASH_HEX_LENGTH.'}$/', $storedHash)) {
            throw CommerceException::hashMismatch();
        }

        $expected = $this->hash(
            $attemptId,
            $sequenceNumber,
            $eventType,
            $providerEventReference,
            $occurredAt,
            $amountMinor,
            $currency,
            $sanitizedMetadata,
            $previousEventHash,
            $schemaVersion,
        );
        if (!hash_equals($expected, $storedHash)) {
            throw CommerceException::hashMismatch();
        }
    }

    /**
     * @param array<string, bool|int|string|null> $sanitizedMetadata
     *
     * @return array<string, mixed>
     */
    private function canonicalPayload(
        int $schemaVersion,
        Uuid $attemptId,
        int $sequenceNumber,
        PaymentEventType $eventType,
        ?string $providerEventReference,
        \DateTimeImmutable $occurredAt,
        ?int $amountMinor,
        ?string $currency,
        array $sanitizedMetadata,
        ?string $previousEventHash,
    ): array {
        ksort($sanitizedMetadata);

        return [
            'schemaVersion' => $schemaVersion,
            'attemptId' => $attemptId->toRfc4122(),
            'sequenceNumber' => $sequenceNumber,
            'eventType' => $eventType->value,
            'providerEventReference' => $providerEventReference,
            'occurredAt' => CommerceCanonicalInstant::format($occurredAt),
            'amountMinor' => $amountMinor,
            'currency' => $currency,
            'sanitizedMetadata' => $sanitizedMetadata,
            'previousEventHash' => $previousEventHash,
        ];
    }

    public static function assertHash(string $hash): string
    {
        if (1 !== preg_match('/^[0-9a-f]{'.self::HASH_HEX_LENGTH.'}$/', $hash)) {
            throw CommerceException::invalidInput('eventHash must be 64 lowercase hex characters.');
        }

        return $hash;
    }
}
