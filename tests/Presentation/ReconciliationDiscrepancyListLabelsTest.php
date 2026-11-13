<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Presentation\ReconciliationDiscrepancyListLabels;
use PHPUnit\Framework\TestCase;

final class ReconciliationDiscrepancyListLabelsTest extends TestCase
{
    public function testOutcomeActionAndProviderStateLabels(): void
    {
        $labels = new ReconciliationDiscrepancyListLabels();

        self::assertSame(['label' => 'Yerel geride', 'variant' => 'warning'], $labels->outcome('local_behind'));
        self::assertSame(['label' => 'Sağlayıcı geride', 'variant' => 'warning'], $labels->outcome('provider_behind'));
        self::assertSame(['label' => 'Tutar uyuşmazlığı', 'variant' => 'danger'], $labels->outcome('amount_mismatch'));
        self::assertSame(['label' => 'Para birimi uyuşmazlığı', 'variant' => 'danger'], $labels->outcome('currency_mismatch'));
        self::assertSame(['label' => 'Referans uyuşmazlığı', 'variant' => 'danger'], $labels->outcome('reference_mismatch'));
        self::assertSame(['label' => 'Sağlayıcıda yok', 'variant' => 'warning'], $labels->outcome('missing_at_provider'));
        self::assertSame(['label' => 'Desteklenmiyor', 'variant' => 'limited'], $labels->outcome('unsupported'));
        self::assertSame(['label' => 'Başarısız', 'variant' => 'danger'], $labels->outcome('failed'));
        self::assertSame(['label' => 'Bilinmiyor', 'variant' => 'neutral'], $labels->outcome('"><script>'));

        self::assertSame(['label' => 'Yok', 'variant' => 'neutral'], $labels->action('none'));
        self::assertSame(
            ['label' => 'Webhook yeniden kuyruğa alındı', 'variant' => 'warning'],
            $labels->action('webhook_requeued'),
        );
        self::assertSame(
            ['label' => 'Manuel inceleme gerekli', 'variant' => 'warning'],
            $labels->action('manual_review_required'),
        );

        self::assertSame(['label' => 'Tahsil edildi', 'variant' => 'success'], $labels->providerState('captured'));
        self::assertSame(['label' => 'Yetkilendirildi', 'variant' => 'warning'], $labels->providerState('authorized'));
        self::assertSame(['label' => 'Bilinmiyor', 'variant' => 'neutral'], $labels->providerState('weird'));
    }
}
