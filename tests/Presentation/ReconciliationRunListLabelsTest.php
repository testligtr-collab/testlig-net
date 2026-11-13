<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Presentation\ReconciliationRunListLabels;
use PHPUnit\Framework\TestCase;

final class ReconciliationRunListLabelsTest extends TestCase
{
    public function testStatusAndEnvironmentLabels(): void
    {
        $labels = new ReconciliationRunListLabels();

        self::assertSame(['label' => 'Devam ediyor', 'variant' => 'warning'], $labels->status('running'));
        self::assertSame(['label' => 'Tamamlandı', 'variant' => 'success'], $labels->status('completed'));
        self::assertSame(
            ['label' => 'Tutarsızlıklarla tamamlandı', 'variant' => 'warning'],
            $labels->status('completed_with_discrepancies'),
        );
        self::assertSame(['label' => 'Başarısız', 'variant' => 'danger'], $labels->status('failed'));
        self::assertSame(['label' => 'Bilinmiyor', 'variant' => 'neutral'], $labels->status('"><script>'));

        self::assertSame('Test (sandbox)', $labels->environment('sandbox'));
        self::assertSame('Canlı', $labels->environment('production'));
        self::assertSame('other', $labels->environment('other'));
    }
}
