<?php

declare(strict_types=1);

namespace App\Money;

use App\Exception\MoneyException;

/**
 * Immutable non-negative money amount stored as integer minor units.
 *
 * Minor units only — floats are never accepted, returned, or used internally.
 * TRY (kuruş, 2 decimals) is the first supported currency; the value object itself
 * stays currency agnostic and only enforces the ISO 4217 alpha-3 code shape.
 *
 * Arithmetic requires the same currency and rejects overflow beyond
 * {@see Money::MAX_MINOR}, which is also mirrored by DB CHECK constraints.
 */
final class Money
{
    /**
     * Upper bound for a single persisted amount (999_999_999_999 minor units).
     *
     * Chosen well below PHP_INT_MAX so intermediate basis-point math
     * (amount * 10_000) cannot overflow a 64-bit integer.
     */
    public const MAX_MINOR = 999999999999;

    /**
     * Minor-unit exponent for the supported currencies (TRY = 2 → kuruş).
     */
    public const MINOR_UNIT_EXPONENT = 2;

    public const DEFAULT_CURRENCY = 'TRY';

    private function __construct(
        private readonly int $amountMinor,
        private readonly string $currency,
    ) {
    }

    public static function fromMinor(int $amountMinor, string $currency): self
    {
        return new self(self::assertAmount($amountMinor), self::normalizeCurrency($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, self::normalizeCurrency($currency));
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function isZero(): bool
    {
        return 0 === $this->amountMinor;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        if ($other->amountMinor > self::MAX_MINOR - $this->amountMinor) {
            throw MoneyException::overflow();
        }

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    /**
     * Subtracts and rejects results below zero (money is non-negative by design).
     */
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        if ($other->amountMinor > $this->amountMinor) {
            throw MoneyException::negativeAmount();
        }

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw MoneyException::invalidScale();
        }
        if (0 === $factor || 0 === $this->amountMinor) {
            return new self(0, $this->currency);
        }
        if ($this->amountMinor > intdiv(self::MAX_MINOR, $factor)) {
            throw MoneyException::overflow();
        }

        return new self($this->amountMinor * $factor, $this->currency);
    }

    /**
     * Applies a basis-point rate with deterministic half-up rounding to the minor unit.
     *
     * 1 basis point = 0.01%; 10_000 basis points = 100%.
     */
    public function percentageOfBasisPoints(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw MoneyException::invalidScale();
        }
        if (0 === $basisPoints || 0 === $this->amountMinor) {
            return new self(0, $this->currency);
        }
        if ($this->amountMinor > intdiv(\PHP_INT_MAX - 5000, $basisPoints)) {
            throw MoneyException::overflow();
        }

        $rounded = intdiv($this->amountMinor * $basisPoints + 5000, 10000);
        if ($rounded > self::MAX_MINOR) {
            throw MoneyException::overflow();
        }

        return new self($rounded, $this->currency);
    }

    /**
     * @return int -1 when this is less than $other, 0 when equal, 1 when greater
     */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor <=> $other->amountMinor;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amountMinor === $other->amountMinor;
    }

    public function isLessThan(self $other): bool
    {
        return -1 === $this->compareTo($other);
    }

    public function isGreaterThan(self $other): bool
    {
        return 1 === $this->compareTo($other);
    }

    /**
     * Canonical, locale independent representation for hashing and logs (never a float).
     */
    public function toCanonicalString(): string
    {
        return $this->currency.' '.$this->amountMinor;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw MoneyException::currencyMismatch($this->currency, $other->currency);
        }
    }

    private static function assertAmount(int $amountMinor): int
    {
        if ($amountMinor < 0) {
            throw MoneyException::negativeAmount();
        }
        if ($amountMinor > self::MAX_MINOR) {
            throw MoneyException::overflow();
        }

        return $amountMinor;
    }

    private static function normalizeCurrency(string $currency): string
    {
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw MoneyException::invalidCurrency($currency);
        }

        return $currency;
    }
}
