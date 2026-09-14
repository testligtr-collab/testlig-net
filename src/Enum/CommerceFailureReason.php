<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Stable, lowercase snake_case failure reasons for the commerce / payment domain.
 */
enum CommerceFailureReason: string
{
    case Unauthorized = 'unauthorized';
    case InvalidInput = 'invalid_input';
    case InvalidTransition = 'invalid_transition';
    case Conflict = 'conflict';
    case NotFound = 'not_found';
    case ScopeMismatch = 'scope_mismatch';
    case Immutable = 'immutable';
    case HashMismatch = 'hash_mismatch';
    case CurrencyMismatch = 'currency_mismatch';
    case TotalMismatch = 'total_mismatch';
    case IdempotencyConflict = 'idempotency_conflict';
    case IdempotencyMisconfigured = 'idempotency_misconfigured';
    case OfferRetired = 'offer_retired';
    case ValidityPolicyMissing = 'validity_policy_missing';
    case ProviderMismatch = 'provider_mismatch';
    case RefundExceedsCapture = 'refund_exceeds_capture';
    case AlreadyFulfilled = 'already_fulfilled';
    case PaymentNotCaptured = 'payment_not_captured';
    case ProviderUnavailable = 'provider_unavailable';
    case WebhookSignatureInvalid = 'webhook_signature_invalid';
    case WebhookReplayRejected = 'webhook_replay_rejected';
    case WebhookIntegrityConflict = 'webhook_integrity_conflict';
}
