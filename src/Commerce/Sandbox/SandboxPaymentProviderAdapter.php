<?php

declare(strict_types=1);

namespace App\Commerce\Sandbox;

use App\Commerce\PaymentProviderAdapterInterface;
use App\Commerce\PaymentProviderChargeRequest;
use App\Commerce\PaymentProviderChargeResult;
use App\Commerce\PaymentProviderRefundRequest;
use App\Commerce\PaymentProviderRefundResult;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentRefundStatus;
use App\Time\UtcInstant;
use Psr\Clock\ClockInterface;

/**
 * Deterministic in-process sandbox adapter for dev/test. Not a commercial SDK.
 *
 * Ambiguous mode simulates network uncertainty without marking the attempt failed.
 */
final class SandboxPaymentProviderAdapter implements PaymentProviderAdapterInterface
{
    public const PROVIDER_CODE = 'sandbox_provider';

    public const MODE_SUCCESS = 'success';

    public const MODE_DECLINE = 'decline';

    public const MODE_AMBIGUOUS = 'ambiguous';

    /**
     * @var list<string>
     */
    private array $calls = [];

    private string $mode;

    public function __construct(
        private readonly ClockInterface $clock,
        string $mode = self::MODE_SUCCESS,
    ) {
        $this->setMode($mode);
    }

    public function setMode(string $mode): void
    {
        if (!\in_array($mode, [self::MODE_SUCCESS, self::MODE_DECLINE, self::MODE_AMBIGUOUS], true)) {
            throw new \InvalidArgumentException('Unsupported sandbox provider mode.');
        }
        $this->mode = $mode;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getProviderCode(): string
    {
        return self::PROVIDER_CODE;
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
        $now = UtcInstant::ensure($this->clock->now());
        if (self::MODE_AMBIGUOUS === $this->mode) {
            throw new SandboxProviderAmbiguousException('Sandbox authorize result is ambiguous.');
        }
        if (self::MODE_DECLINE === $this->mode) {
            return new PaymentProviderChargeResult(
                eventType: PaymentEventType::Failed,
                occurredAt: $now,
                failureCode: 'provider_declined',
                sanitizedMetadata: ['provider_code' => $this->getProviderCode()],
            );
        }

        return new PaymentProviderChargeResult(
            eventType: PaymentEventType::Authorized,
            occurredAt: $now,
            amount: $request->amount,
            providerPaymentReference: 'sandbox_pay_'.substr(bin2hex($request->paymentAttemptId->toBinary()), 0, 12),
            providerAuthorizationReference: 'sandbox_auth_1',
            providerEventReference: 'sandbox_evt_auth_'.substr(bin2hex($request->paymentAttemptId->toBinary()), 0, 10),
            sanitizedMetadata: ['provider_code' => $this->getProviderCode(), 'installment_count' => 1],
        );
    }

    public function capture(PaymentProviderChargeRequest $request): PaymentProviderChargeResult
    {
        $this->calls[] = 'capture:'.$request->paymentAttemptId->toRfc4122();
        $now = UtcInstant::ensure($this->clock->now());
        if (self::MODE_AMBIGUOUS === $this->mode) {
            throw new SandboxProviderAmbiguousException('Sandbox capture result is ambiguous.');
        }

        return new PaymentProviderChargeResult(
            eventType: PaymentEventType::Captured,
            occurredAt: $now,
            amount: $request->amount,
            providerPaymentReference: $request->providerPaymentReference,
            providerEventReference: 'sandbox_evt_cap_'.substr(bin2hex($request->paymentAttemptId->toBinary()), 0, 10),
            sanitizedMetadata: ['provider_code' => $this->getProviderCode()],
        );
    }

    public function refund(PaymentProviderRefundRequest $request): PaymentProviderRefundResult
    {
        $this->calls[] = 'refund:'.$request->paymentAttemptId->toRfc4122();

        return new PaymentProviderRefundResult(
            status: PaymentRefundStatus::Succeeded,
            occurredAt: UtcInstant::ensure($this->clock->now()),
            amount: $request->amount,
            providerRefundReference: 'sandbox_refund_1',
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

    public function resetCalls(): void
    {
        $this->calls = [];
    }
}
