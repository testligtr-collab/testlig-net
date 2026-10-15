<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

final class QuestionImageScanMessage
{
    public static function forVerdict(QuestionImageScanVerdict $verdict): string
    {
        return match ($verdict) {
            QuestionImageScanVerdict::Clean => 'Görsel tarandı ve kullanıma hazır.',
            QuestionImageScanVerdict::Infected => 'Görsel zararlı bulundu ve karantinaya alındı.',
            QuestionImageScanVerdict::StaleSignature => 'İmza güncelliği doğrulanamadı. Görsel beklemeye devam ediyor.',
            QuestionImageScanVerdict::Unchanged => 'Bu görsel taranamaz.',
            default => 'Tarama tamamlanamadı.',
        };
    }

    public static function ineligible(): string
    {
        return 'Bu görsel taranamaz.';
    }
}
