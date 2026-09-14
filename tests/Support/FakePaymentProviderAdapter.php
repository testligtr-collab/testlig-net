<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Commerce\PaymentProviderAdapterInterface;
use App\Commerce\PaymentProviderChargeRequest;
use App\Commerce\PaymentProviderChargeResult;
use App\Commerce\PaymentProviderRefundRequest;
use App\Commerce\PaymentProviderRefundResult;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentRefundStatus;

/**
 * The only adapter that may exist in tests: a deterministic in-memory double.
 *
 * Production/dev use {@see \App\Commerce\Sandbox\SandboxPaymentProviderAdapter}; this
 * class remains a lightweight unit-test double without container wiring.
 */
final class FakePaymentProviderAdapter implements PaymentProviderAdapterInterface
{
    /**
     * @var list<string>
     */
    private array $calls = [];

    public function __construct(
        private readonly \DateTimeImmutable $now,
        private readonly bool $decline = false,
    ) {
    }

    public function getProviderCode(): string
    {
        return 'sandbox_provider';
    }

    public function getEnvironment(): PaymentProviderEnvironment
    {
        return PaymentProviderEnvironment::Sandbox;
    }

    public function supportsRecurring(): bool
    {
        return true;
    }

    public function authorize(PaymentProviderChargeRequest $request): PaymentProviderChargeResult
    {
        $this->calls[] = 'authorize:'.$request->paymentAttemptId->toRfc4122();
        if ($this->decline) {
            return new PaymentProviderChargeResult(
                eventType: PaymentEventType::Failed,
                occurredAt: $this->now,
                failureCode: 'provider_declined',
                sanitizedMetadata: ['provider_code' => $this->getProviderCode()],
            );
        }

        return new PaymentProviderChargeResult(
            eventType: PaymentEventType::Authorized,
            occurredAt: $this->now,
            amount: $request->amount,
            providerPaymentReference: 'fake_pay_'.substr(bin2hex($request->paymentAttemptId->toBinary()), 0, 12),
            providerAuthorizationReference: 'fake_auth_1',
            providerEventReference: 'fake_evt_auth_1',
            sanitizedMetadata: ['provider_code' => $this->getProviderCode(), 'installment_count' => 1],
        );
    }

    public function capture(PaymentProviderChargeRequest $request): PaymentProviderChargeResult
    {
        $this->calls[] = 'capture:'.$request->paymentAttemptId->toRfc4122();

        return new PaymentProviderChargeResult(
            eventType: PaymentEventType::Captured,
            occurredAt: $this->now,
            amount: $request->amount,
            providerPaymentReference: $request->providerPaymentReference,
            providerEventReference: 'fake_evt_cap_1',
            sanitizedMetadata: ['provider_code' => $this->getProviderCode()],
        );
    }

    public function refund(PaymentProviderRefundRequest $request): PaymentProviderRefundResult
    {
        $this->calls[] = 'refund:'.$request->paymentAttemptId->toRfc4122();

        return new PaymentProviderRefundResult(
            status: PaymentRefundStatus::Succeeded,
            occurredAt: $this->now,
            amount: $request->amount,
            providerRefundReference: 'fake_refund_1',
            sanitizedMetadata: ['provider_code' => $this->getProviderCode()],
        );
    }

    /**
     * @return list<string>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }
}
