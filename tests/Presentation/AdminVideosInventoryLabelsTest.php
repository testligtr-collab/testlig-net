<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Presentation\AdminVideosInventoryLabels;
use PHPUnit\Framework\TestCase;

final class AdminVideosInventoryLabelsTest extends TestCase
{
    public function testProviderLabels(): void
    {
        self::assertSame('YouTube', AdminVideosInventoryLabels::provider('youtube'));
        self::assertSame('Vimeo', AdminVideosInventoryLabels::provider('vimeo'));
        self::assertSame('Bilinmeyen sağlayıcı', AdminVideosInventoryLabels::provider('other'));
    }
}
