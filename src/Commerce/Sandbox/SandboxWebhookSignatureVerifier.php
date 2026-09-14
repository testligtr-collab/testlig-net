<?php

declare(strict_types=1);

namespace App\Commerce\Sandbox;

use App\Commerce\PaymentWebhookSignatureVerifierInterface;
use App\Commerce\PaymentWebhookVerificationRequest;
use App\Exception\CommerceException;
use App\Time\UtcInstant;
use Psr\Clock\ClockInterface;

/**
 * HMAC-SHA256 webhook verifier for the sandbox provider (test/dev only).
 *
 * Signature header format: `t=<unix>,v1=<hex>` over `t.rawBody`.
 * Rejects missing/invalid signatures and timestamps outside the replay window.
 */
final class SandboxWebhookSignatureVerifier implements PaymentWebhookSignatureVerifierInterface
{
    public const HEADER_SIGNATURE = 'X-Testlig-Webhook-Signature';

    public const HEADER_TIMESTAMP = 'X-Testlig-Webhook-Timestamp';

    public const DEFAULT_TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly string $signingKey,
        private readonly ClockInterface $clock,
        private readonly int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ) {
        if (\strlen($signingKey) < 32) {
            throw new \InvalidArgumentException('Sandbox webhook signing key must be at least 32 bytes.');
        }
        if ($toleranceSeconds < 1 || $toleranceSeconds > 3600) {
            throw new \InvalidArgumentException('Webhook timestamp tolerance must be 1-3600 seconds.');
        }
    }

    public function getProviderCode(): string
    {
        return SandboxPaymentProviderAdapter::PROVIDER_CODE;
    }

    public function verify(PaymentWebhookVerificationRequest $request): void
    {
        if ($request->providerCode !== $this->getProviderCode()) {
            throw CommerceException::providerMismatch();
        }
        if ('' === $request->signatureHeader || '' === $request->timestampHeader) {
            throw CommerceException::webhookSignatureInvalid();
        }
        if (1 !== preg_match('/^\d{10,12}$/', $request->timestampHeader)) {
            throw CommerceException::webhookReplayRejected();
        }

        $timestamp = (int) $request->timestampHeader;
        $now = UtcInstant::ensure($this->clock->now())->getTimestamp();
        $delta = abs($now - $timestamp);
        if ($delta > $this->toleranceSeconds) {
            throw CommerceException::webhookReplayRejected();
        }

        $expected = hash_hmac('sha256', $request->timestampHeader.'.'.$request->rawBody, $this->signingKey);
        $provided = $this->extractV1($request->signatureHeader);
        if (null === $provided || !hash_equals($expected, $provided)) {
            throw CommerceException::webhookSignatureInvalid();
        }
    }

    public function sign(string $rawBody, int $unixTimestamp): string
    {
        $digest = hash_hmac('sha256', $unixTimestamp.'.'.$rawBody, $this->signingKey);

        return 't='.$unixTimestamp.',v1='.$digest;
    }

    public function fingerprint(string $signatureHeader): string
    {
        return hash('sha256', $signatureHeader);
    }

    private function extractV1(string $header): ?string
    {
        if (1 === preg_match('/(?:^|,)v1=([0-9a-f]{64})(?:$|,)/', $header, $matches)) {
            return $matches[1];
        }
        if (1 === preg_match('/^[0-9a-f]{64}$/', $header)) {
            return $header;
        }

        return null;
    }
}
