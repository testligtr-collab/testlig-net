<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Exception\CommerceException;
use App\Money\Money;

/**
 * Tax-exclusive money policy for commerce orders.
 *
 * Offer prices are stored **tax exclusive** (net). Tax is derived per order line
 * from the offer's `taxRateBasisPoints` snapshot, so historical orders keep their
 * own rate even after the catalog rate changes:
 *
 *   lineSubtotal = unitPrice * quantity                (net, tax exclusive)
 *   lineTax      = halfUp(lineSubtotal * bp / 10_000)
 *   lineTotal    = lineSubtotal + lineTax
 *   subtotal     = Σ lineSubtotal
 *   tax          = Σ lineTax
 *   grandTotal   = subtotal - discount + tax
 *
 * Rounding is deterministic half-up to the minor unit and happens **per line**, so
 * recomputing an order from its persisted items always reproduces the stored totals.
 * Discounts apply to the net subtotal and default to zero in Stage 2.17.
 */
final class CommerceMoneyPolicy
{
    public const MAX_TAX_RATE_BASIS_POINTS = 10000;

    public const MAX_QUANTITY = 10;

    public function assertTaxRateBasisPoints(int $basisPoints): int
    {
        if ($basisPoints < 0 || $basisPoints > self::MAX_TAX_RATE_BASIS_POINTS) {
            throw CommerceException::invalidInput('taxRateBasisPoints must be between 0 and 10000.');
        }

        return $basisPoints;
    }

    public function assertQuantity(int $quantity): int
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw CommerceException::invalidInput(
                \sprintf('quantity must be between 1 and %d.', self::MAX_QUANTITY),
            );
        }

        return $quantity;
    }

    public function lineSubtotal(Money $unitPrice, int $quantity): Money
    {
        return $unitPrice->multiply($this->assertQuantity($quantity));
    }

    public function lineTax(Money $lineSubtotal, int $taxRateBasisPoints): Money
    {
        return $lineSubtotal->percentageOfBasisPoints($this->assertTaxRateBasisPoints($taxRateBasisPoints));
    }

    public function lineTotal(Money $lineSubtotal, Money $lineTax): Money
    {
        return $lineSubtotal->add($lineTax);
    }

    /**
     * Unit tax is informational only (display/receipt) and never used to build totals.
     */
    public function unitTax(Money $unitPrice, int $taxRateBasisPoints): Money
    {
        return $unitPrice->percentageOfBasisPoints($this->assertTaxRateBasisPoints($taxRateBasisPoints));
    }

    public function grandTotal(Money $subtotal, Money $discount, Money $tax): Money
    {
        if ($discount->isGreaterThan($subtotal)) {
            throw CommerceException::invalidInput('discount must not exceed the order subtotal.');
        }

        return $subtotal->subtract($discount)->add($tax);
    }

    /**
     * @param list<array{unitPriceAmountMinor: int, quantity: int, taxRateBasisPoints: int}> $lines
     *
     * @return array{subtotal: Money, discount: Money, tax: Money, grandTotal: Money}
     */
    public function totalsFor(array $lines, string $currency, int $discountAmountMinor = 0): array
    {
        if ([] === $lines) {
            throw CommerceException::invalidInput('An order requires at least one line.');
        }

        $subtotal = Money::zero($currency);
        $tax = Money::zero($currency);
        foreach ($lines as $line) {
            $lineSubtotal = $this->lineSubtotal(
                Money::fromMinor($line['unitPriceAmountMinor'], $currency),
                $line['quantity'],
            );
            $subtotal = $subtotal->add($lineSubtotal);
            $tax = $tax->add($this->lineTax($lineSubtotal, $line['taxRateBasisPoints']));
        }

        $discount = Money::fromMinor($discountAmountMinor, $currency);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'grandTotal' => $this->grandTotal($subtotal, $discount, $tax),
        ];
    }
}
