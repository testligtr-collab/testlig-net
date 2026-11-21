<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * Safe Turkish labels for the SuperAdmin videos inventory screen.
 */
final class AdminVideosInventoryLabels
{
    public static function provider(string $provider): string
    {
        return match ($provider) {
            'youtube' => 'YouTube',
            'vimeo' => 'Vimeo',
            default => 'Bilinmeyen sağlayıcı',
        };
    }
}
