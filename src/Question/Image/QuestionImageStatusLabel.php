<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetStatus;
use App\Enum\StoredMediaScanStatus;
use App\Question\Image\Scan\QuestionImageScanSnapshot;

/**
 * Labels the stored asset. It does not scan, open a socket, or expose storage.
 */
final class QuestionImageStatusLabel
{
    public static function fromSnapshot(QuestionImageScanSnapshot $snapshot): string
    {
        if (StoredMediaAssetKind::Image !== $snapshot->kind) {
            return 'Görsel referansı geçersiz';
        }
        if (StoredMediaAssetStatus::Archived === $snapshot->status) {
            return 'Arşiv';
        }
        if (StoredMediaScanStatus::Infected === $snapshot->scanStatus || StoredMediaAssetStatus::Quarantined === $snapshot->status) {
            return 'Karantinada';
        }
        if (StoredMediaAssetStatus::Ready === $snapshot->status && StoredMediaScanStatus::Clean === $snapshot->scanStatus) {
            return 'Kullanıma hazır';
        }
        if (StoredMediaAssetStatus::Pending === $snapshot->status && StoredMediaScanStatus::Pending === $snapshot->scanStatus) {
            return 'Tarama bekliyor';
        }
        if (StoredMediaScanStatus::Failed === $snapshot->scanStatus) {
            return 'Tarama başarısız';
        }

        return 'Görsel referansı geçersiz';
    }
}
