<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Presentation\AdminDocumentsInventoryLabels;
use PHPUnit\Framework\TestCase;

final class AdminDocumentsInventoryLabelsTest extends TestCase
{
    public function testSafeLabels(): void
    {
        self::assertSame('PDF', AdminDocumentsInventoryLabels::kind('document'));
        self::assertSame('Doküman', AdminDocumentsInventoryLabels::kind('other'));
        self::assertSame('Kullanıma hazır', AdminDocumentsInventoryLabels::status('ready'));
        self::assertSame('Onay bekliyor', AdminDocumentsInventoryLabels::status('pending'));
        self::assertSame('success', AdminDocumentsInventoryLabels::statusTone('ready'));
        self::assertSame('2 KB', AdminDocumentsInventoryLabels::byteSize(2048));
        self::assertSame('100 bayt', AdminDocumentsInventoryLabels::byteSize(100));
    }
}
