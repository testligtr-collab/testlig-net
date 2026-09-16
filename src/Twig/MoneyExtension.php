<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Formats integer minor units for presentation (no floats in domain).
 */
final class MoneyExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('money_try', $this->formatTry(...)),
        ];
    }

    /**
     * Formats TRY minor units as ₺1.234,56 (tr_TR grouping/decimal).
     */
    public function formatTry(int $amountMinor, string $currency = 'TRY'): string
    {
        $negative = $amountMinor < 0;
        $abs = abs($amountMinor);
        $major = intdiv($abs, 100);
        $minor = $abs % 100;
        $grouped = number_format($major, 0, ',', '.');
        $formatted = \sprintf('%s,%02d', $grouped, $minor);

        $prefix = 'TRY' === strtoupper($currency) ? '₺' : ($currency.' ');

        return ($negative ? '-' : '').$prefix.$formatted;
    }
}
