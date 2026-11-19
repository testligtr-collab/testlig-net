<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Presentation\AdminMediaInventoryLabels;
use PHPUnit\Framework\TestCase;

final class AdminMediaInventoryLabelsTest extends TestCase
{
    public function testLabelsStayHumanReadableWithoutSecrets(): void
    {
        self::assertSame('Görsel', AdminMediaInventoryLabels::kind('image'));
        self::assertSame('Yerel', AdminMediaInventoryLabels::storageProvider('local'));
        self::assertSame('Hazır', AdminMediaInventoryLabels::status('ready'));
        self::assertSame('success', AdminMediaInventoryLabels::statusTone('ready'));
        self::assertSame('2 KB', AdminMediaInventoryLabels::byteSize(2048));
        self::assertSame('100 bayt', AdminMediaInventoryLabels::byteSize(100));
    }
}
