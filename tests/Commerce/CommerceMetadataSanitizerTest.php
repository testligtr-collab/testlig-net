<?php

declare(strict_types=1);

namespace App\Tests\Commerce;

use App\Commerce\PaymentEventMetadataSanitizer;
use App\Enum\CommerceFailureReason;
use App\Enum\SecurityAuditAction;
use App\Exception\CommerceException;
use App\Exception\SecurityAuditMetadataException;
use App\Service\SecurityAuditMetadataSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Leak scans for both sanitizers: commerce identifiers and amounts are auditable,
 * while card data, PII, secrets and raw idempotency keys must be refused loudly.
 */
final class CommerceMetadataSanitizerTest extends TestCase
{
    private PaymentEventMetadataSanitizer $eventSanitizer;
    private SecurityAuditMetadataSanitizer $auditSanitizer;

    protected function setUp(): void
    {
        $this->eventSanitizer = new PaymentEventMetadataSanitizer();
        $this->auditSanitizer = new SecurityAuditMetadataSanitizer();
    }

    public function testEventSanitizerKeepsAllowlistedScalarsSorted(): void
    {
        $clean = $this->eventSanitizer->sanitize([
            'retry_count' => 2,
            'provider_code' => 'sandbox_manual',
            'is_three_d_secure' => false,
            'provider_status_code' => null,
        ]);
        self::assertSame(
            ['is_three_d_secure', 'provider_code', 'provider_status_code', 'retry_count'],
            array_keys($clean),
        );
        self::assertSame('sandbox_manual', $clean['provider_code']);
        self::assertFalse($clean['is_three_d_secure']);
        self::assertNull($clean['provider_status_code']);
        self::assertSame(2, $clean['retry_count']);
    }

    public function testEventSanitizerNormalizesKeyShape(): void
    {
        $clean = $this->eventSanitizer->sanitize([' Provider-Code ' => 'sandbox_manual']);
        self::assertSame(['provider_code' => 'sandbox_manual'], $clean);
    }

    public function testEventSanitizerAcceptsEmptyMetadata(): void
    {
        self::assertSame([], $this->eventSanitizer->sanitize([]));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function forbiddenEventKeyProvider(): array
    {
        return [
            ['card_number'],
            ['cardNumber'],
            ['pan'],
            ['cvv'],
            ['cvc'],
            ['expiry_month'],
            ['expiration_year'],
            ['card_holder'],
            ['iban'],
            ['provider_token'],
            ['api_secret'],
            ['signature'],
            ['authorization_header'],
            ['apikey'],
            ['password'],
            ['buyer_email'],
            ['buyer_phone'],
            ['billing_address'],
            ['client_ip'],
            ['user_agent'],
            ['idempotency_key'],
            ['raw_response'],
            ['payload'],
            ['response_body'],
            ['buyer_name'],
            ['identity_number'],
            ['tckn'],
        ];
    }

    #[DataProvider('forbiddenEventKeyProvider')]
    public function testEventSanitizerRejectsForbiddenKeys(string $key): void
    {
        $this->expectCommerceFailure(function () use ($key): void {
            $this->eventSanitizer->sanitize([$key => 'whatever']);
        });
    }

    public function testEventSanitizerRejectsUnknownKeys(): void
    {
        $this->expectCommerceFailure(function (): void {
            $this->eventSanitizer->sanitize(['some_new_provider_field' => 'x']);
        });
    }

    public function testEventSanitizerRejectsNonStringKeys(): void
    {
        $this->expectCommerceFailure(function (): void {
            $this->eventSanitizer->sanitize([0 => 'x']);
        });
        $this->expectCommerceFailure(function (): void {
            $this->eventSanitizer->sanitize(['' => 'x']);
        });
    }

    public function testEventSanitizerRejectsNonScalarValues(): void
    {
        $this->expectCommerceFailure(function (): void {
            $this->eventSanitizer->sanitize(['provider_code' => ['nested']]);
        });
        $this->expectCommerceFailure(function (): void {
            $this->eventSanitizer->sanitize(['provider_code' => 1.5]);
        });
    }

    public function testEventSanitizerRejectsOverlongValues(): void
    {
        $this->expectCommerceFailure(function (): void {
            $this->eventSanitizer->sanitize(['provider_code' => str_repeat('a', 129)]);
        });
        self::assertSame(
            ['provider_code' => str_repeat('a', 128)],
            $this->eventSanitizer->sanitize(['provider_code' => str_repeat('a', 128)]),
        );
    }

    /**
     * @return list<array{0: string}>
     */
    public static function commerceAuditKeyProvider(): array
    {
        return [
            ['offer_id'],
            ['offer_code'],
            ['offer_hash'],
            ['order_id'],
            ['order_hash'],
            ['order_item_id'],
            ['payment_attempt_id'],
            ['payment_event_id'],
            ['event_hash'],
            ['sequence_number'],
            ['subscription_id'],
            ['fulfillment_id'],
            ['refund_id'],
            ['license_id'],
            ['currency'],
            ['grand_total_amount_minor'],
            ['amount_minor'],
            ['tax_rate_basis_points'],
            ['billing_type'],
            ['provider_code'],
            ['provider_environment'],
        ];
    }

    #[DataProvider('commerceAuditKeyProvider')]
    public function testAuditSanitizerAllowsCommerceKeys(string $key): void
    {
        $clean = $this->auditSanitizer->sanitize([$key => 'value-1']);
        self::assertSame([$key => 'value-1'], $clean);
    }

    public function testAuditSanitizerKeepsIntegerAmounts(): void
    {
        $clean = $this->auditSanitizer->sanitize([
            'amount_minor' => 12000,
            'grand_total_amount_minor' => 12000,
            'subtotal_amount_minor' => 10000,
            'discount_amount_minor' => 0,
            'tax_amount_minor' => 2000,
        ]);
        self::assertSame(12000, $clean['amount_minor']);
        self::assertSame(0, $clean['discount_amount_minor']);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function forbiddenAuditKeyProvider(): array
    {
        return [
            ['password'],
            ['plain_password'],
            ['token'],
            ['secret'],
            ['card_number'],
            ['pan'],
            ['cvv'],
            ['idempotency_key'],
            ['idempotency_key_hash'],
            ['buyer_email'],
            ['email'],
        ];
    }

    #[DataProvider('forbiddenAuditKeyProvider')]
    public function testAuditSanitizerRejectsSensitiveKeys(string $key): void
    {
        $this->expectException(SecurityAuditMetadataException::class);
        $this->auditSanitizer->sanitize([$key => 'value']);
    }

    public function testAuditSanitizerStillRejectsUnknownCommerceLikeKeys(): void
    {
        $this->expectException(SecurityAuditMetadataException::class);
        $this->auditSanitizer->sanitize(['order_public_reference' => 'ORD-ABC']);
    }

    public function testCommerceAuditActionsExistAndAreSnakeCase(): void
    {
        $commerceActions = array_values(array_filter(
            SecurityAuditAction::cases(),
            static fn (SecurityAuditAction $action): bool => str_starts_with($action->value, 'commerce_')
                || str_starts_with($action->value, 'payment_')
                || str_starts_with($action->value, 'commercial_offer_'),
        ));
        self::assertGreaterThanOrEqual(20, \count($commerceActions));
        foreach ($commerceActions as $action) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $action->value);
        }

        $values = array_map(
            static fn (SecurityAuditAction $action): string => $action->value,
            SecurityAuditAction::cases(),
        );
        self::assertSame(\count($values), \count(array_unique($values)), 'Audit action values must be unique.');

        foreach ([
            'commercial_offer_created',
            'commercial_offer_activated',
            'commercial_offer_retired',
            'commerce_order_created',
            'commerce_order_cancelled',
            'commerce_order_paid',
            'payment_attempt_started',
            'payment_captured',
            'payment_failed',
            'payment_refund_requested',
            'payment_refund_succeeded',
            'commerce_fulfillment_completed',
            'commerce_fulfillment_reversed',
            'commerce_subscription_created',
        ] as $expected) {
            self::assertContains($expected, $values, 'Missing audit action '.$expected);
        }
    }

    private function expectCommerceFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected CommerceException for forbidden metadata.');
        } catch (CommerceException $e) {
            self::assertSame(CommerceFailureReason::InvalidInput, $e->getReason());
        }
    }
}
