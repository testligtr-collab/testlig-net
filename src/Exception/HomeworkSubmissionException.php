<?php

declare(strict_types=1);

namespace App\Exception;

final class HomeworkSubmissionException extends \RuntimeException
{
    public const REASON_NOT_FOUND = 'not_found';
    public const REASON_FORBIDDEN = 'forbidden';
    public const REASON_INVALID_INPUT = 'invalid_input';
    public const REASON_STALE_REVISION = 'stale_revision';
    public const REASON_ALREADY_SUBMITTED = 'already_submitted';
    public const REASON_DUE_PASSED = 'due_passed';
    public const REASON_CONFIRMATION_REQUIRED = 'confirmation_required';
    public const REASON_CONFLICT = 'conflict';
    public const REASON_CLOSED = 'closed';

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
        return new self(self::REASON_INVALID_INPUT, 'Yanıtında düzeltilecek alanlar var.', $fieldErrors);
    }

    public static function staleRevision(): self
    {
        return new self(
            self::REASON_STALE_REVISION,
            'Bu ödev başka bir yerde değiştirilmiş. Sayfayı yenileyip tekrar dene.',
        );
    }

    public static function alreadySubmitted(): self
    {
        return new self(
            self::REASON_ALREADY_SUBMITTED,
            'Bu ödev zaten gönderildi. Yanıt değiştirilemez.',
        );
    }

    public static function duePassed(): self
    {
        return new self(
            self::REASON_DUE_PASSED,
            'Teslim süresi sona erdi. Kaydetme ve gönderme kapandı.',
            ['due' => 'Teslim süresi sona erdi.'],
        );
    }

    public static function confirmationRequired(): self
    {
        return new self(
            self::REASON_CONFIRMATION_REQUIRED,
            'Göndermeden önce onayı işaretlemen gerekir.',
            ['confirm' => 'Gönderdikten sonra yanıtımı değiştiremeyeceğimi anladım kutusunu işaretle.'],
        );
    }

    public static function conflict(): self
    {
        return new self(self::REASON_CONFLICT, 'Ödev şu an kaydedilemedi. Biraz sonra tekrar dene.');
    }

    public static function closed(): self
    {
        return new self(
            self::REASON_CLOSED,
            'Bu ödeve şu an yazılamaz.',
            ['access' => 'Bu ödeve şu an yazılamaz.'],
        );
    }
}
