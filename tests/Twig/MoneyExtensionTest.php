<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\MoneyExtension;
use PHPUnit\Framework\TestCase;

final class MoneyExtensionTest extends TestCase
{
    public function testFormatsTryMinorUnits(): void
    {
        $ext = new MoneyExtension();
        self::assertSame('₺0,00', $ext->formatTry(0));
        self::assertSame('₺1,00', $ext->formatTry(100));
        self::assertSame('₺1.234,56', $ext->formatTry(123456));
        self::assertSame('-₺10,50', $ext->formatTry(-1050));
    }
}
