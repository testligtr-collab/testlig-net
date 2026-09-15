<?php

declare(strict_types=1);

namespace App\Commerce;

/**
 * Canonical SHA-256 of safe snapshot fields. Compare with hash_equals().
 */
final class PaymentReconciliationSnapshotHasher
{
    public static function hash(PaymentProviderTransactionSnapshot $snapshot): string
    {
        $payload = [
            'amount_minor' => $snapshot->amountMinor,
            'authorized_at' => self::formatInstant($snapshot->authorizedAt),
            'cancelled_at' => self::formatInstant($snapshot->cancelledAt),
            'captured_at' => self::formatInstant($snapshot->capturedAt),
            'currency' => $snapshot->currency,
            'environment' => $snapshot->environment->value,
            'provider_authorization_reference' => $snapshot->providerAuthorizationReference,
            'provider_code' => $snapshot->providerCode,
            'provider_payment_reference' => $snapshot->providerPaymentReference,
            'provider_status' => $snapshot->providerStatus->value,
            'provider_updated_at' => self::formatInstant($snapshot->providerUpdatedAt),
            'refund_count' => $snapshot->refundCount,
            'refunded_amount_minor' => $snapshot->refundedAmountMinor,
        ];

        return hash('sha256', json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    public static function equals(string $expected, string $actual): bool
    {
        return hash_equals($expected, $actual);
    }

    private static function formatInstant(?\DateTimeImmutable $instant): ?string
    {
        if (!$instant instanceof \DateTimeImmutable) {
            return null;
        }

        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
