<?php

declare(strict_types=1);

namespace App\Commerce\Sandbox;

use App\Commerce\PaymentWebhookParserInterface;
use App\Commerce\PaymentWebhookVerificationRequest;
use App\Commerce\VerifiedPaymentWebhook;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Exception\CommerceException;
use App\Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * Parses sandbox webhook JSON into a verified DTO (after signature verification).
 *
 * Expected body (card-data free):
 * {
 *   "provider_event_reference": "...",
 *   "event_type": "authorized|captured|failed|cancelled|refund_succeeded|refund_failed",
 *   "occurred_at": "2026-09-14T12:00:00+00:00",
 *   "payment_attempt_id": "<uuid>",
 *   "order_public_reference": "ORD-...",
 *   "amount_minor": 12000,
 *   "currency": "TRY",
 *   "provider_payment_reference": "...",
 *   "provider_authorization_reference": "...",
 *   "provider_refund_reference": "...",
 *   "failure_code": "..."
 * }
 */
final class SandboxWebhookParser implements PaymentWebhookParserInterface
{
    private const MAX_BODY_BYTES = 65536;

    public function __construct(
        private readonly SandboxWebhookSignatureVerifier $verifier,
    ) {
    }

    public function getProviderCode(): string
    {
        return SandboxPaymentProviderAdapter::PROVIDER_CODE;
    }

    public function parse(PaymentWebhookVerificationRequest $request): VerifiedPaymentWebhook
    {
        if ($request->providerCode !== $this->getProviderCode()) {
            throw CommerceException::providerMismatch();
        }
        if (\strlen($request->rawBody) > self::MAX_BODY_BYTES) {
            throw CommerceException::invalidInput('Webhook body exceeds size limit.');
        }

        try {
            $decoded = json_decode($request->rawBody, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw CommerceException::invalidInput('Webhook body is not valid JSON.');
        }
        if (!\is_array($decoded)) {
            throw CommerceException::invalidInput('Webhook body must be a JSON object.');
        }

        $eventRef = $this->requireString($decoded, 'provider_event_reference', 8, 128);
        $eventTypeRaw = $this->requireString($decoded, 'event_type', 3, 32);
        $eventType = PaymentEventType::tryFrom($eventTypeRaw);
        if (!$eventType instanceof PaymentEventType) {
            throw CommerceException::invalidInput('Unsupported webhook event type.');
        }

        $occurredRaw = $this->requireString($decoded, 'occurred_at', 10, 64);
        try {
            $occurredAt = new \DateTimeImmutable($occurredRaw, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw CommerceException::invalidInput('Invalid webhook occurred_at.');
        }

        $attemptId = null;
        if (isset($decoded['payment_attempt_id']) && \is_string($decoded['payment_attempt_id']) && '' !== $decoded['payment_attempt_id']) {
            try {
                $attemptId = Uuid::fromString($decoded['payment_attempt_id']);
            } catch (\InvalidArgumentException) {
                throw CommerceException::invalidInput('Invalid payment_attempt_id.');
            }
        }

        $orderPublicReference = null;
        if (isset($decoded['order_public_reference']) && \is_string($decoded['order_public_reference'])) {
            $orderPublicReference = trim($decoded['order_public_reference']);
            if ('' === $orderPublicReference || \strlen($orderPublicReference) > 64) {
                throw CommerceException::invalidInput('Invalid order_public_reference.');
            }
        }

        $amount = null;
        if ($eventType->requiresAmount()) {
            if (!isset($decoded['amount_minor'], $decoded['currency'])
                || !\is_int($decoded['amount_minor'])
                || !\is_string($decoded['currency'])
            ) {
                throw CommerceException::invalidInput('Webhook amount_minor/currency required.');
            }
            $amount = Money::fromMinor($decoded['amount_minor'], strtoupper($decoded['currency']));
        }

        $payloadHash = hash('sha256', $request->rawBody);
        $fingerprint = $this->verifier->fingerprint($request->signatureHeader);

        $sanitizedMetadata = [
            'provider_code' => $this->getProviderCode(),
            'provider_environment' => PaymentProviderEnvironment::Sandbox->value,
            'event_source' => 'webhook',
        ];
        if (null !== $orderPublicReference) {
            $sanitizedMetadata['order_public_reference'] = $orderPublicReference;
        }
        if ($amount instanceof Money) {
            $sanitizedMetadata['amount_minor'] = $amount->getAmountMinor();
            $sanitizedMetadata['currency'] = $amount->getCurrency();
        }
        $providerPaymentReference = $this->optionalString($decoded, 'provider_payment_reference', 128);
        $providerAuthorizationReference = $this->optionalString($decoded, 'provider_authorization_reference', 128);
        $providerRefundReference = $this->optionalString($decoded, 'provider_refund_reference', 128);
        $failureCode = $this->optionalString($decoded, 'failure_code', 64);
        if (null !== $providerPaymentReference) {
            $sanitizedMetadata['provider_payment_reference'] = $providerPaymentReference;
        }
        if (null !== $providerAuthorizationReference) {
            $sanitizedMetadata['provider_authorization_reference'] = $providerAuthorizationReference;
        }
        if (null !== $providerRefundReference) {
            $sanitizedMetadata['provider_refund_reference'] = $providerRefundReference;
        }
        if (null !== $failureCode) {
            $sanitizedMetadata['failure_code'] = $failureCode;
        }

        return new VerifiedPaymentWebhook(
            providerCode: $this->getProviderCode(),
            environment: PaymentProviderEnvironment::Sandbox,
            providerEventReference: $eventRef,
            eventType: $eventType,
            payloadHash: $payloadHash,
            signatureFingerprint: $fingerprint,
            providerOccurredAt: $occurredAt,
            receivedAt: $request->receivedAt,
            paymentAttemptId: $attemptId,
            orderPublicReference: $orderPublicReference,
            amount: $amount,
            providerPaymentReference: $providerPaymentReference,
            providerAuthorizationReference: $providerAuthorizationReference,
            providerRefundReference: $providerRefundReference,
            failureCode: $failureCode,
            sanitizedMetadata: $sanitizedMetadata,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireString(array $payload, string $key, int $min, int $max): string
    {
        $value = $payload[$key] ?? null;
        if (!\is_string($value)) {
            throw CommerceException::invalidInput('Webhook field missing: '.$key);
        }
        $value = trim($value);
        $len = \strlen($value);
        if ($len < $min || $len > $max) {
            throw CommerceException::invalidInput('Webhook field length invalid: '.$key);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalString(array $payload, string $key, int $max): ?string
    {
        if (!\array_key_exists($key, $payload) || null === $payload[$key]) {
            return null;
        }
        if (!\is_string($payload[$key])) {
            throw CommerceException::invalidInput('Webhook field type invalid: '.$key);
        }
        $value = trim($payload[$key]);
        if ('' === $value) {
            return null;
        }
        if (\strlen($value) > $max) {
            throw CommerceException::invalidInput('Webhook field too long: '.$key);
        }

        return $value;
    }
}
