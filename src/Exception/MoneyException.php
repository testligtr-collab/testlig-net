<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\MoneyFailureReason;

final class MoneyException extends \RuntimeException
{
    private function __construct(
        private readonly MoneyFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): MoneyFailureReason
    {
        return $this->reason;
    }

    public static function invalidCurrency(string $currency): self
    {
        return new self(
            MoneyFailureReason::InvalidCurrency,
            \sprintf('Currency must be an ISO 4217 alpha-3 uppercase code, got "%s".', $currency),
        );
    }

    public static function negativeAmount(): self
    {
        return new self(MoneyFailureReason::NegativeAmount, 'Money amount in minor units must be >= 0.');
    }

    public static function overflow(): self
    {
        return new self(MoneyFailureReason::Overflow, 'Money amount in minor units exceeds the supported range.');
    }

    public static function currencyMismatch(string $left, string $right): self
    {
        return new self(
            MoneyFailureReason::CurrencyMismatch,
            \sprintf('Money operations require the same currency, got "%s" and "%s".', $left, $right),
        );
    }

    public static function invalidScale(): self
    {
        return new self(MoneyFailureReason::InvalidScale, 'Money multiplier must be an integer >= 0.');
    }
}
