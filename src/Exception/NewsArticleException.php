<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\NewsFailureReason;

final class NewsArticleException extends \RuntimeException
{
    private function __construct(
        private readonly NewsFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): NewsFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(NewsFailureReason::Unauthorized, 'Bu işlem için yetkin yok.');
    }

    public static function invalidInput(): self
    {
        return new self(NewsFailureReason::InvalidInput, 'Başlık, özet veya metin geçersiz. HTML kullanılamaz.');
    }

    public static function invalidTransition(): self
    {
        return new self(NewsFailureReason::InvalidTransition, 'Bu yayın geçişi yapılamaz.');
    }

    public static function notFound(): self
    {
        return new self(NewsFailureReason::NotFound, 'Haber veya kategori bulunamadı.');
    }

    public static function separationOfDuties(): self
    {
        return new self(NewsFailureReason::SeparationOfDuties, 'Haberi yazan veya incelemeye gönderen kişi yayımlayamaz.');
    }

    public static function categoryRequired(): self
    {
        return new self(NewsFailureReason::CategoryRequired, 'Yayımlamak için kategori seçilmelidir.');
    }

    public static function conflict(): self
    {
        return new self(NewsFailureReason::Conflict, 'Bu kayıt başka bir işlemle çakıştı.');
    }
}
