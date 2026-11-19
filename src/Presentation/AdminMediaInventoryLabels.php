<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetStatus;
use App\Enum\StoredMediaStorageProvider;

/**
 * Safe Turkish labels for the SuperAdmin media inventory screen.
 */
final class AdminMediaInventoryLabels
{
    public static function kind(string $value): string
    {
        return match (StoredMediaAssetKind::tryFrom($value)) {
            StoredMediaAssetKind::Image => 'Görsel',
            StoredMediaAssetKind::Video => 'Video',
            StoredMediaAssetKind::Audio => 'Ses',
            StoredMediaAssetKind::Document => 'Doküman',
            StoredMediaAssetKind::Presentation => 'Sunum',
            StoredMediaAssetKind::Animation => 'Animasyon',
            StoredMediaAssetKind::InteractivePackage => 'Etkileşimli paket',
            StoredMediaAssetKind::Subtitle => 'Altyazı',
            StoredMediaAssetKind::Transcript => 'Deşifre',
            StoredMediaAssetKind::Thumbnail => 'Küçük resim',
            null => 'Bilinmeyen tür',
        };
    }

    public static function storageProvider(string $value): string
    {
        return match (StoredMediaStorageProvider::tryFrom($value)) {
            StoredMediaStorageProvider::Local => 'Yerel',
            StoredMediaStorageProvider::S3 => 'S3',
            StoredMediaStorageProvider::R2 => 'R2',
            StoredMediaStorageProvider::Bunny => 'Bunny',
            null => 'Bilinmeyen depolama',
        };
    }

    public static function status(string $value): string
    {
        return match (StoredMediaAssetStatus::tryFrom($value)) {
            StoredMediaAssetStatus::Pending => 'Beklemede',
            StoredMediaAssetStatus::Ready => 'Hazır',
            StoredMediaAssetStatus::Quarantined => 'Karantinada',
            StoredMediaAssetStatus::Archived => 'Arşivlendi',
            null => 'Bilinmeyen durum',
        };
    }

    public static function statusTone(string $value): string
    {
        return match (StoredMediaAssetStatus::tryFrom($value)) {
            StoredMediaAssetStatus::Ready => 'success',
            StoredMediaAssetStatus::Pending => 'warning',
            StoredMediaAssetStatus::Quarantined => 'danger',
            StoredMediaAssetStatus::Archived => 'limited',
            null => 'limited',
        };
    }

    public static function byteSize(int $bytes): string
    {
        if ($bytes < 0) {
            $bytes = 0;
        }
        if ($bytes < 1024) {
            return $bytes.' bayt';
        }
        if ($bytes < 1024 * 1024) {
            return self::formatDecimal($bytes / 1024).' KB';
        }

        return self::formatDecimal($bytes / (1024 * 1024)).' MB';
    }

    private static function formatDecimal(float $value): string
    {
        $rounded = round($value, 1);

        return str_replace('.', ',', rtrim(rtrim(sprintf('%.1f', $rounded), '0'), '.'));
    }
}
