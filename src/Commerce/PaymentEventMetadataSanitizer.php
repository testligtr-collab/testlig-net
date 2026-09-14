<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Exception\CommerceException;

/**
 * Strict allowlist sanitizer for the `sanitizedMetadata` column of payment events.
 *
 * Provider payloads are never stored verbatim. Only a small set of non-PII, non-card,
 * non-secret scalar keys survives; anything else (pan, card, cvv, token, email, raw
 * idempotency key, signature, ...) is rejected rather than silently dropped, so a
 * careless caller fails loudly in tests instead of leaking in production.
 */
final class PaymentEventMetadataSanitizer
{
    /**
     * @var list<string>
     */
    private const ALLOWED_KEYS = [
        'provider_code',
        'provider_environment',
        'provider_status_code',
        'provider_error_code',
        'settlement_currency',
        'settlement_amount_minor',
        'installment_count',
        'is_three_d_secure',
        'retry_count',
        'sequence_number',
        'event_source',
    ];

    /**
     * @var list<string>
     */
    private const FORBIDDEN_FRAGMENTS = [
        'pan',
        'card',
        'cvv',
        'cvc',
        'expiry',
        'expiration',
        'holder',
        'iban',
        'token',
        'secret',
        'signature',
        'authorization',
        'apikey',
        'password',
        'email',
        'phone',
        'address',
        'ip',
        'useragent',
        'idempotency',
        'raw',
        'payload',
        'body',
        'name',
        'identity',
        'tckn',
    ];

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @return array<string, bool|int|string|null>
     */
    public function sanitize(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (!\is_string($key) || '' === $key) {
                throw CommerceException::invalidInput('Payment event metadata keys must be non-empty strings.');
            }
            $normalized = str_replace(['-', ' '], '_', strtolower(trim($key)));
            $compact = str_replace('_', '', $normalized);
            foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
                if (str_contains($compact, $fragment)) {
                    throw CommerceException::invalidInput(\sprintf(
                        'Payment event metadata key "%s" is forbidden.',
                        $normalized,
                    ));
                }
            }
            if (!\in_array($normalized, self::ALLOWED_KEYS, true)) {
                throw CommerceException::invalidInput(\sprintf(
                    'Payment event metadata key "%s" is not allowlisted.',
                    $normalized,
                ));
            }
            if (null !== $value && !\is_bool($value) && !\is_int($value) && !\is_string($value)) {
                throw CommerceException::invalidInput(\sprintf(
                    'Payment event metadata value for "%s" must be bool, int, string, or null.',
                    $normalized,
                ));
            }
            if (\is_string($value) && mb_strlen($value) > 128) {
                throw CommerceException::invalidInput(\sprintf(
                    'Payment event metadata value for "%s" must be at most 128 characters.',
                    $normalized,
                ));
            }
            $clean[$normalized] = $value;
        }
        ksort($clean);

        return $clean;
    }
}
