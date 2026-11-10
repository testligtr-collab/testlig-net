<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Presentation\PaymentAttemptListLabels;
use PHPUnit\Framework\TestCase;

final class PaymentAttemptListLabelsTest extends TestCase
{
    public function testStatusAndEnvironmentLabels(): void
    {
        $labels = new PaymentAttemptListLabels();

        self::assertSame(['label' => 'Başlatıldı', 'variant' => 'neutral'], $labels->status('initiated'));
        self::assertSame(['label' => 'Yetkilendirildi', 'variant' => 'warning'], $labels->status('authorized'));
        self::assertSame(['label' => 'Tahsil edildi', 'variant' => 'success'], $labels->status('captured'));
        self::assertSame(['label' => 'Başarısız', 'variant' => 'danger'], $labels->status('failed'));
        self::assertSame(['label' => 'İptal', 'variant' => 'neutral'], $labels->status('cancelled'));
        self::assertSame(['label' => 'Bilinmiyor', 'variant' => 'neutral'], $labels->status('"><script>'));

        self::assertSame('Test (sandbox)', $labels->environment('sandbox'));
        self::assertSame('Canlı', $labels->environment('production'));
        self::assertSame('other', $labels->environment('other'));
    }
}
