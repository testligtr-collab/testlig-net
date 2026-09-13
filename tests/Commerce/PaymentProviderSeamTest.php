<?php

declare(strict_types=1);

namespace App\Tests\Commerce;

use App\Commerce\PaymentProviderAdapterInterface;
use App\Commerce\PaymentProviderChargeRequest;
use App\Commerce\PaymentProviderRefundRequest;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentRefundReasonCode;
use App\Enum\PaymentRefundStatus;
use App\Money\Money;
use App\Tests\Support\FakePaymentProviderAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Stage 2.17 ships the provider seam but no provider: this test locks both facts down.
 */
final class PaymentProviderSeamTest extends TestCase
{
    public function testNoProductionCodeImplementsTheAdapterInterface(): void
    {
        $implementations = [];
        foreach ($this->sourceFiles(\dirname(__DIR__, 2).'/src') as $file) {
            $source = (string) file_get_contents($file);
            if (str_contains($source, 'implements PaymentProviderAdapterInterface')
                || str_contains($source, 'PaymentProviderAdapterInterface,')
            ) {
                $implementations[] = $file;
            }
        }
        self::assertSame([], $implementations, 'No src/ class may implement the payment provider seam yet.');
    }

    public function testNoVendorPaymentSdkIsReferencedAnywhereInSource(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles(\dirname(__DIR__, 2).'/src') as $file) {
            // Comments may name the vendors we deliberately do not integrate with, so only
            // executable code is scanned.
            $code = strtolower($this->stripComments((string) file_get_contents($file)));
            foreach (['iyzico', 'iyzipay', 'paytr', 'stripe', 'braintree', 'adyen'] as $vendor) {
                if (str_contains($code, $vendor)) {
                    $offenders[] = $file.' mentions '.$vendor;
                }
            }
        }
        self::assertSame([], $offenders);
    }

    public function testTheSeamCarriesNoCardData(): void
    {
        $reflection = new \ReflectionClass(PaymentProviderAdapterInterface::class);
        $names = [];
        foreach ($reflection->getMethods() as $method) {
            $names[] = strtolower($method->getName());
            foreach ($method->getParameters() as $parameter) {
                $names[] = strtolower($parameter->getName());
            }
        }
        foreach ([PaymentProviderChargeRequest::class, PaymentProviderRefundRequest::class] as $dto) {
            foreach ((new \ReflectionClass($dto))->getProperties() as $property) {
                $names[] = strtolower($property->getName());
            }
        }

        foreach ($names as $name) {
            foreach (['card', 'pan', 'cvv', 'cvc', 'expiry', 'holder', 'iban'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $name);
            }
        }
    }

    public function testTestDoubleFulfilsTheContractWithoutSideEffects(): void
    {
        $now = new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC'));
        $adapter = new FakePaymentProviderAdapter($now);
        self::assertSame('sandbox_provider', $adapter->getProviderCode());
        self::assertSame(PaymentProviderEnvironment::Sandbox, $adapter->getEnvironment());
        self::assertTrue($adapter->supportsRecurring());

        $request = new PaymentProviderChargeRequest(
            orderId: Uuid::v7(),
            paymentAttemptId: Uuid::v7(),
            orderPublicReference: 'ORD-0123456789ABCDEF01234567',
            amount: Money::fromMinor(12000, 'TRY'),
            idempotencyKey: 'seam-key-000000000000000',
        );
        $authorized = $adapter->authorize($request);
        self::assertSame(PaymentEventType::Authorized, $authorized->eventType);
        self::assertInstanceOf(Money::class, $authorized->amount);
        self::assertSame(12000, $authorized->amount->getAmountMinor());
        self::assertSame(['provider_code' => 'sandbox_provider', 'installment_count' => 1], $authorized->sanitizedMetadata);

        $captured = $adapter->capture(new PaymentProviderChargeRequest(
            orderId: $request->orderId,
            paymentAttemptId: $request->paymentAttemptId,
            orderPublicReference: $request->orderPublicReference,
            amount: $request->amount,
            idempotencyKey: $request->idempotencyKey,
            providerPaymentReference: $authorized->providerPaymentReference,
        ));
        self::assertSame(PaymentEventType::Captured, $captured->eventType);
        self::assertSame($authorized->providerPaymentReference, $captured->providerPaymentReference);

        $refunded = $adapter->refund(new PaymentProviderRefundRequest(
            paymentAttemptId: $request->paymentAttemptId,
            providerPaymentReference: (string) $authorized->providerPaymentReference,
            amount: Money::fromMinor(2000, 'TRY'),
            reasonCode: PaymentRefundReasonCode::PurchaserRequested,
            idempotencyKey: 'seam-refund-000000000000',
        ));
        self::assertSame(PaymentRefundStatus::Succeeded, $refunded->status);
        self::assertSame(2000, $refunded->amount->getAmountMinor());
        self::assertCount(3, $adapter->getCalls());
    }

    public function testDeclinedAuthorizationIsExpressedAsAFailureEvent(): void
    {
        $now = new \DateTimeImmutable('2026-09-13 12:00:00', new \DateTimeZone('UTC'));
        $adapter = new FakePaymentProviderAdapter($now, true);
        $result = $adapter->authorize(new PaymentProviderChargeRequest(
            orderId: Uuid::v7(),
            paymentAttemptId: Uuid::v7(),
            orderPublicReference: 'ORD-0123456789ABCDEF01234567',
            amount: Money::fromMinor(12000, 'TRY'),
            idempotencyKey: 'seam-key-111111111111111',
        ));
        self::assertSame(PaymentEventType::Failed, $result->eventType);
        self::assertNull($result->amount);
        self::assertSame('provider_declined', $result->failureCode);
    }

    private function stripComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                if (\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
