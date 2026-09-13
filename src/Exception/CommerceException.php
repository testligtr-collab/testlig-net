<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\CommerceFailureReason;

/**
 * Commerce / payment domain failures. Messages never carry card data, provider
 * secrets, raw idempotency keys, or buyer PII.
 */
final class CommerceException extends \RuntimeException
{
    private function __construct(
        private readonly CommerceFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): CommerceFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(CommerceFailureReason::Unauthorized, 'Commerce operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid commerce input.'): self
    {
        return new self(CommerceFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(CommerceFailureReason::InvalidTransition, 'Commerce status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(CommerceFailureReason::Conflict, 'Commerce operation conflict.');
    }

    public static function notFound(): self
    {
        return new self(CommerceFailureReason::NotFound, 'Commerce resource was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(CommerceFailureReason::NotFound, 'User was not found.');
    }

    public static function scopeMismatch(string $detail = 'Commerce scope mismatch.'): self
    {
        return new self(CommerceFailureReason::ScopeMismatch, $detail);
    }

    public static function immutable(): self
    {
        return new self(CommerceFailureReason::Immutable, 'Commerce field is immutable.');
    }

    public static function hashMismatch(): self
    {
        return new self(CommerceFailureReason::HashMismatch, 'Commerce snapshot hash mismatch.');
    }

    public static function currencyMismatch(): self
    {
        return new self(CommerceFailureReason::CurrencyMismatch, 'Commerce currency mismatch.');
    }

    public static function totalMismatch(): self
    {
        return new self(CommerceFailureReason::TotalMismatch, 'Commerce totals do not match the recomputed amounts.');
    }

    public static function idempotencyConflict(): self
    {
        return new self(
            CommerceFailureReason::IdempotencyConflict,
            'Idempotency key was already used for a different commerce payload.',
        );
    }

    public static function idempotencyMisconfigured(): self
    {
        return new self(
            CommerceFailureReason::IdempotencyMisconfigured,
            'COMMERCE_IDEMPOTENCY_HASH_KEY is missing, too short, or a placeholder.',
        );
    }

    public static function offerRetired(): self
    {
        return new self(CommerceFailureReason::OfferRetired, 'Retired or inactive offer cannot be ordered.');
    }

    public static function validityPolicyMissing(): self
    {
        return new self(
            CommerceFailureReason::ValidityPolicyMissing,
            'Commercial fulfillment requires validityDays on the package version or package default.',
        );
    }

    public static function providerMismatch(): self
    {
        return new self(CommerceFailureReason::ProviderMismatch, 'Payment provider or environment mismatch.');
    }

    public static function refundExceedsCapture(): self
    {
        return new self(CommerceFailureReason::RefundExceedsCapture, 'Refund total would exceed the captured amount.');
    }

    public static function alreadyFulfilled(): self
    {
        return new self(CommerceFailureReason::AlreadyFulfilled, 'Order item is already fulfilled.');
    }

    public static function paymentNotCaptured(): self
    {
        return new self(CommerceFailureReason::PaymentNotCaptured, 'Fulfillment requires a captured payment attempt.');
    }
}
