<?php

declare(strict_types=1);

namespace App\Tests\Commerce;

use App\Commerce\CommerceMoneyPolicy;
use App\Enum\CommerceFailureReason;
use App\Exception\CommerceException;
use App\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tax is exclusive: the offer stores a tax-exclusive unit price plus a basis-point rate,
 * and tax is computed per line on the line subtotal, never on the discounted order total.
 */
final class CommerceMoneyPolicyTest extends TestCase
{
    private CommerceMoneyPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new CommerceMoneyPolicy();
    }

    public function testLineSubtotalMultipliesUnitPriceByQuantity(): void
    {
        $subtotal = $this->policy->lineSubtotal(Money::fromMinor(19999, 'TRY'), 3);
        self::assertSame(59997, $subtotal->getAmountMinor());
    }

    public function testLineTaxUsesHalfUpRoundingOnTheLineSubtotal(): void
    {
        $subtotal = $this->policy->lineSubtotal(Money::fromMinor(19999, 'TRY'), 3);
        $tax = $this->policy->lineTax($subtotal, 2000);
        self::assertSame(11999, $tax->getAmountMinor());
        self::assertSame(71996, $this->policy->lineTotal($subtotal, $tax)->getAmountMinor());
    }

    public function testTaxIsComputedPerLineNotOnTheSummedSubtotal(): void
    {
        // Two lines of 3 minor units at 50% each round up to 2 individually (4 total),
        // while taxing the 6-unit sum in one go would yield 3.
        $lines = [
            ['unitPriceAmountMinor' => 3, 'quantity' => 1, 'taxRateBasisPoints' => 5000],
            ['unitPriceAmountMinor' => 3, 'quantity' => 1, 'taxRateBasisPoints' => 5000],
        ];
        $totals = $this->policy->totalsFor($lines, 'TRY');
        self::assertSame(6, $totals['subtotal']->getAmountMinor());
        self::assertSame(4, $totals['tax']->getAmountMinor());
        self::assertSame(10, $totals['grandTotal']->getAmountMinor());
    }

    public function testUnitTaxIsInformationalAndDoesNotDriveTotals(): void
    {
        $unitTax = $this->policy->unitTax(Money::fromMinor(3, 'TRY'), 5000);
        self::assertSame(2, $unitTax->getAmountMinor());

        $totals = $this->policy->totalsFor(
            [['unitPriceAmountMinor' => 3, 'quantity' => 2, 'taxRateBasisPoints' => 5000]],
            'TRY',
        );
        self::assertSame(3, $totals['tax']->getAmountMinor(), 'Tax follows the line subtotal, not unit tax * qty.');
    }

    public function testGrandTotalIsSubtotalMinusDiscountPlusTax(): void
    {
        $grand = $this->policy->grandTotal(
            Money::fromMinor(10000, 'TRY'),
            Money::fromMinor(1500, 'TRY'),
            Money::fromMinor(2000, 'TRY'),
        );
        self::assertSame(10500, $grand->getAmountMinor());
    }

    public function testDiscountDefaultsToZero(): void
    {
        $totals = $this->policy->totalsFor(
            [['unitPriceAmountMinor' => 10000, 'quantity' => 1, 'taxRateBasisPoints' => 2000]],
            'TRY',
        );
        self::assertTrue($totals['discount']->isZero());
        self::assertSame(12000, $totals['grandTotal']->getAmountMinor());
    }

    public function testDiscountEqualToSubtotalLeavesOnlyTax(): void
    {
        $totals = $this->policy->totalsFor(
            [['unitPriceAmountMinor' => 10000, 'quantity' => 1, 'taxRateBasisPoints' => 2000]],
            'TRY',
            10000,
        );
        self::assertSame(2000, $totals['grandTotal']->getAmountMinor());
    }

    public function testDiscountAboveSubtotalIsRejected(): void
    {
        $this->expectFailure(CommerceFailureReason::InvalidInput, function (): void {
            $this->policy->grandTotal(
                Money::fromMinor(1000, 'TRY'),
                Money::fromMinor(1001, 'TRY'),
                Money::zero('TRY'),
            );
        });
    }

    public function testTotalsForRejectsEmptyLines(): void
    {
        $this->expectFailure(CommerceFailureReason::InvalidInput, function (): void {
            $this->policy->totalsFor([], 'TRY');
        });
    }

    public function testTotalsAccumulateAcrossLines(): void
    {
        $totals = $this->policy->totalsFor([
            ['unitPriceAmountMinor' => 50000, 'quantity' => 2, 'taxRateBasisPoints' => 2000],
            ['unitPriceAmountMinor' => 12500, 'quantity' => 1, 'taxRateBasisPoints' => 1000],
        ], 'TRY', 2500);
        self::assertSame(112500, $totals['subtotal']->getAmountMinor());
        self::assertSame(2500, $totals['discount']->getAmountMinor());
        self::assertSame(21250, $totals['tax']->getAmountMinor());
        self::assertSame(131250, $totals['grandTotal']->getAmountMinor());
        self::assertSame('TRY', $totals['grandTotal']->getCurrency());
    }

    public function testZeroTaxRateProducesNoTax(): void
    {
        $totals = $this->policy->totalsFor(
            [['unitPriceAmountMinor' => 9999, 'quantity' => 1, 'taxRateBasisPoints' => 0]],
            'TRY',
        );
        self::assertTrue($totals['tax']->isZero());
        self::assertSame(9999, $totals['grandTotal']->getAmountMinor());
    }

    /**
     * @return list<array{0: int}>
     */
    public static function invalidQuantityProvider(): array
    {
        return [[0], [-1], [CommerceMoneyPolicy::MAX_QUANTITY + 1], [1000]];
    }

    #[DataProvider('invalidQuantityProvider')]
    public function testInvalidQuantityIsRejected(int $quantity): void
    {
        $this->expectFailure(CommerceFailureReason::InvalidInput, function () use ($quantity): void {
            $this->policy->assertQuantity($quantity);
        });
    }

    public function testQuantityBoundsAreAccepted(): void
    {
        self::assertSame(1, $this->policy->assertQuantity(1));
        self::assertSame(
            CommerceMoneyPolicy::MAX_QUANTITY,
            $this->policy->assertQuantity(CommerceMoneyPolicy::MAX_QUANTITY),
        );
    }

    /**
     * @return list<array{0: int}>
     */
    public static function invalidTaxRateProvider(): array
    {
        return [[-1], [CommerceMoneyPolicy::MAX_TAX_RATE_BASIS_POINTS + 1], [20000]];
    }

    #[DataProvider('invalidTaxRateProvider')]
    public function testInvalidTaxRateIsRejected(int $basisPoints): void
    {
        $this->expectFailure(CommerceFailureReason::InvalidInput, function () use ($basisPoints): void {
            $this->policy->assertTaxRateBasisPoints($basisPoints);
        });
    }

    public function testTaxRateBoundsAreAccepted(): void
    {
        self::assertSame(0, $this->policy->assertTaxRateBasisPoints(0));
        self::assertSame(2000, $this->policy->assertTaxRateBasisPoints(2000));
        self::assertSame(
            CommerceMoneyPolicy::MAX_TAX_RATE_BASIS_POINTS,
            $this->policy->assertTaxRateBasisPoints(CommerceMoneyPolicy::MAX_TAX_RATE_BASIS_POINTS),
        );
    }

    private function expectFailure(CommerceFailureReason $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected CommerceException '.$reason->value);
        } catch (CommerceException $e) {
            self::assertSame($reason, $e->getReason());
        }
    }
}
