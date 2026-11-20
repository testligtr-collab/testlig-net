<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Enum\LearningDocumentStatus;

/**
 * Safe Turkish labels for the SuperAdmin documents inventory screen.
 */
final class AdminDocumentsInventoryLabels
{
    public static function kind(string $mediaType): string
    {
        return 'document' === $mediaType ? 'PDF' : 'Doküman';
    }

    public static function status(string $value): string
    {
        return match (LearningDocumentStatus::tryFrom($value)) {
            LearningDocumentStatus::Pending => 'Onay bekliyor',
            LearningDocumentStatus::Ready => 'Kullanıma hazır',
            LearningDocumentStatus::Quarantined => 'Karantina',
            LearningDocumentStatus::Archived => 'Arşiv',
            null => 'Bilinmeyen durum',
        };
    }

    public static function statusTone(string $value): string
    {
        return match (LearningDocumentStatus::tryFrom($value)) {
            LearningDocumentStatus::Ready => 'success',
            LearningDocumentStatus::Pending => 'warning',
            LearningDocumentStatus::Quarantined => 'danger',
            LearningDocumentStatus::Archived => 'limited',
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

        return str_replace('.', ',', rtrim(rtrim(\sprintf('%.1f', $rounded), '0'), '.'));
    }
}
