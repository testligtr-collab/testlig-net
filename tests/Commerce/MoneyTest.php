<?php

declare(strict_types=1);

namespace App\Tests\Commerce;

use App\Enum\MoneyFailureReason;
use App\Exception\MoneyException;
use App\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testFromMinorKeepsIntegerMinorUnits(): void
    {
        $money = Money::fromMinor(12345, 'TRY');
        self::assertSame(12345, $money->getAmountMinor());
        self::assertSame('TRY', $money->getCurrency());
        self::assertFalse($money->isZero());
        self::assertSame('TRY 12345', $money->toCanonicalString());
    }

    public function testZeroIsZero(): void
    {
        $zero = Money::zero('TRY');
        self::assertSame(0, $zero->getAmountMinor());
        self::assertTrue($zero->isZero());
    }

    public function testAddAndSubtractSameCurrency(): void
    {
        $a = Money::fromMinor(1000, 'TRY');
        $b = Money::fromMinor(250, 'TRY');
        self::assertSame(1250, $a->add($b)->getAmountMinor());
        self::assertSame(750, $a->subtract($b)->getAmountMinor());
        self::assertSame(1000, $a->getAmountMinor(), 'Money must be immutable.');
    }

    public function testAddRejectsCurrencyMismatch(): void
    {
        $this->expectFailure(MoneyFailureReason::CurrencyMismatch, static function (): void {
            Money::fromMinor(100, 'TRY')->add(Money::fromMinor(100, 'USD'));
        });
    }

    public function testSubtractRejectsCurrencyMismatch(): void
    {
        $this->expectFailure(MoneyFailureReason::CurrencyMismatch, static function (): void {
            Money::fromMinor(100, 'TRY')->subtract(Money::fromMinor(100, 'EUR'));
        });
    }

    public function testCompareRejectsCurrencyMismatch(): void
    {
        $this->expectFailure(MoneyFailureReason::CurrencyMismatch, static function (): void {
            Money::fromMinor(100, 'TRY')->compareTo(Money::fromMinor(100, 'EUR'));
        });
    }

    public function testSubtractBelowZeroIsRejected(): void
    {
        $this->expectFailure(MoneyFailureReason::NegativeAmount, static function (): void {
            Money::fromMinor(100, 'TRY')->subtract(Money::fromMinor(101, 'TRY'));
        });
    }

    public function testNegativeConstructionIsRejected(): void
    {
        $this->expectFailure(MoneyFailureReason::NegativeAmount, static function (): void {
            Money::fromMinor(-1, 'TRY');
        });
    }

    public function testAmountAboveCeilingIsRejected(): void
    {
        $this->expectFailure(MoneyFailureReason::Overflow, static function (): void {
            Money::fromMinor(Money::MAX_MINOR + 1, 'TRY');
        });
    }

    public function testAddOverflowIsRejected(): void
    {
        $this->expectFailure(MoneyFailureReason::Overflow, static function (): void {
            Money::fromMinor(Money::MAX_MINOR, 'TRY')->add(Money::fromMinor(1, 'TRY'));
        });
    }

    public function testMultiplyOverflowIsRejected(): void
    {
        $this->expectFailure(MoneyFailureReason::Overflow, static function (): void {
            Money::fromMinor(Money::MAX_MINOR, 'TRY')->multiply(2);
        });
    }

    public function testMultiplyByZeroAndOne(): void
    {
        $money = Money::fromMinor(999, 'TRY');
        self::assertSame(0, $money->multiply(0)->getAmountMinor());
        self::assertSame(999, $money->multiply(1)->getAmountMinor());
        self::assertSame(2997, $money->multiply(3)->getAmountMinor());
    }

    public function testMultiplyByNegativeFactorIsRejected(): void
    {
        $this->expectFailure(MoneyFailureReason::InvalidScale, static function (): void {
            Money::fromMinor(100, 'TRY')->multiply(-1);
        });
    }

    /**
     * @return list<array{0: int, 1: int, 2: int}>
     */
    public static function basisPointProvider(): array
    {
        return [
            [10000, 2000, 2000],
            [10000, 1800, 1800],
            [999, 2000, 200],
            [1, 2000, 0],
            [3, 2000, 1],
            [25, 2000, 5],
            [1, 5000, 1],
            [1, 4999, 0],
            [7, 5000, 4],
            [10000, 0, 0],
            [0, 2000, 0],
            [10000, 10000, 10000],
        ];
    }

    /**
     * Half-up rounding is deterministic: 0.5 minor units always rounds away from zero.
     */
    #[DataProvider('basisPointProvider')]
    public function testPercentageOfBasisPointsRoundsHalfUp(
        int $amountMinor,
        int $basisPoints,
        int $expectedMinor,
    ): void {
        self::assertSame(
            $expectedMinor,
            Money::fromMinor($amountMinor, 'TRY')->percentageOfBasisPoints($basisPoints)->getAmountMinor(),
        );
    }

    public function testPercentageOfBasisPointsRejectsNegativeRate(): void
    {
        $this->expectFailure(MoneyFailureReason::InvalidScale, static function (): void {
            Money::fromMinor(100, 'TRY')->percentageOfBasisPoints(-1);
        });
    }

    public function testPercentageOfBasisPointsOverflowIsRejected(): void
    {
        $this->expectFailure(MoneyFailureReason::Overflow, static function (): void {
            Money::fromMinor(Money::MAX_MINOR, 'TRY')->percentageOfBasisPoints(20000);
        });
    }

    public function testComparisonHelpers(): void
    {
        $small = Money::fromMinor(100, 'TRY');
        $large = Money::fromMinor(200, 'TRY');
        self::assertSame(-1, $small->compareTo($large));
        self::assertSame(1, $large->compareTo($small));
        self::assertSame(0, $small->compareTo(Money::fromMinor(100, 'TRY')));
        self::assertTrue($small->isLessThan($large));
        self::assertFalse($large->isLessThan($small));
        self::assertTrue($large->isGreaterThan($small));
        self::assertFalse($small->isGreaterThan($large));
        self::assertTrue($small->equals(Money::fromMinor(100, 'TRY')));
        self::assertFalse($small->equals(Money::fromMinor(100, 'USD')));
        self::assertFalse($small->equals($large));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function invalidCurrencyProvider(): array
    {
        return [['try'], ['TR'], ['TRYX'], [''], ['T2Y'], ['TR '], [' TRY']];
    }

    #[DataProvider('invalidCurrencyProvider')]
    public function testInvalidCurrencyIsRejected(string $currency): void
    {
        $this->expectFailure(MoneyFailureReason::InvalidCurrency, static function () use ($currency): void {
            Money::fromMinor(100, $currency);
        });
    }

    public function testZeroRejectsInvalidCurrency(): void
    {
        $this->expectFailure(MoneyFailureReason::InvalidCurrency, static function (): void {
            Money::zero('trl');
        });
    }

    public function testMinorUnitExponentMatchesTwoDecimalCurrencies(): void
    {
        self::assertSame(2, Money::MINOR_UNIT_EXPONENT);
        self::assertSame('TRY', Money::DEFAULT_CURRENCY);
        self::assertSame(999999999999, Money::MAX_MINOR);
    }

    public function testNoFloatCreepsIntoTheValueObject(): void
    {
        $reflection = new \ReflectionClass(Money::class);
        $source = (string) file_get_contents((string) $reflection->getFileName());
        foreach ([': float', 'float $', '(float)', 'floatval', 'round(', 'number_format', '/ 100'] as $needle) {
            self::assertStringNotContainsString($needle, $source);
        }

        foreach ($reflection->getMethods() as $method) {
            $returnType = $method->getReturnType();
            if ($returnType instanceof \ReflectionNamedType) {
                self::assertNotSame('float', $returnType->getName());
            }
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType) {
                    self::assertNotSame('float', $type->getName());
                }
            }
        }
    }

    public function testConstructorIsPrivateSoFactoriesValidate(): void
    {
        $constructor = (new \ReflectionClass(Money::class))->getConstructor();
        self::assertInstanceOf(\ReflectionMethod::class, $constructor);
        self::assertTrue($constructor->isPrivate());
    }

    private function expectFailure(MoneyFailureReason $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected MoneyException '.$reason->value);
        } catch (MoneyException $e) {
            self::assertSame($reason, $e->getReason());
            self::assertNotSame('', $e->getMessage());
        }
    }
}
