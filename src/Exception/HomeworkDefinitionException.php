<?php

declare(strict_types=1);

namespace App\Exception;

final class HomeworkDefinitionException extends \RuntimeException
{
    public const REASON_NOT_FOUND = 'not_found';
    public const REASON_FORBIDDEN = 'forbidden';
    public const REASON_INVALID_INPUT = 'invalid_input';
    public const REASON_STALE_REVISION = 'stale_revision';
    public const REASON_NOT_EDITABLE = 'not_editable';
    public const REASON_CONFLICT = 'conflict';

    /**
     * @param array<string, string> $fieldErrors
     */
    private function __construct(
        private readonly string $reason,
        string $message,
        private readonly array $fieldErrors = [],
    ) {
        parent::__construct($message);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * @return array<string, string> field key => Turkish message
     */
    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }

    public static function notFound(): self
    {
        return new self(self::REASON_NOT_FOUND, 'Ödev bulunamadı.');
    }

    public static function forbidden(): self
    {
        return new self(self::REASON_FORBIDDEN, 'Bu işlem için yetkin yok.');
    }

    /**
     * @param array<string, string> $fieldErrors
     */
    public static function invalidInput(array $fieldErrors): self
    {
        return new self(self::REASON_INVALID_INPUT, 'Ödev bilgilerinde düzeltilecek alanlar var.', $fieldErrors);
    }

    public static function staleRevision(): self
    {
        return new self(self::REASON_STALE_REVISION, 'Bu taslak başka bir yerde değiştirilmiş. Sayfayı yenileyip tekrar dene.');
    }

    public static function notEditable(): self
    {
        return new self(self::REASON_NOT_EDITABLE, 'Bu ödev düzenlenemez.');
    }

    public static function conflict(): self
    {
        return new self(self::REASON_CONFLICT, 'Taslak şu an kaydedilemedi. Biraz sonra tekrar dene.');
    }
}
