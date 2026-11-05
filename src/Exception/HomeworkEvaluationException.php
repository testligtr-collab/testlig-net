<?php

declare(strict_types=1);

namespace App\Exception;

final class HomeworkEvaluationException extends \RuntimeException
{
    public const REASON_NOT_FOUND = 'not_found';
    public const REASON_FORBIDDEN = 'forbidden';
    public const REASON_INVALID_INPUT = 'invalid_input';
    public const REASON_STALE = 'stale_evaluation';
    public const REASON_NOT_SUBMITTED = 'not_submitted';
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
     * @return array<string, string>
     */
    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }

    public static function notFound(): self
    {
        return new self(self::REASON_NOT_FOUND, 'Teslim bulunamadı.');
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
        return new self(self::REASON_INVALID_INPUT, 'Değerlendirmede düzeltilecek alanlar var.', $fieldErrors);
    }

    public static function stale(): self
    {
        return new self(
            self::REASON_STALE,
            'Bu teslim başka bir yerde değerlendirilmiş. Sayfayı yenileyip tekrar dene.',
        );
    }

    public static function notSubmitted(): self
    {
        return new self(
            self::REASON_NOT_SUBMITTED,
            'Yalnız gönderilmiş teslimler değerlendirilebilir.',
        );
    }

    public static function conflict(): self
    {
        return new self(
            self::REASON_CONFLICT,
            'Değerlendirme şu an kaydedilemedi. Kısa süre sonra tekrar dene.',
        );
    }
}
