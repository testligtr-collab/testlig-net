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
    public const REASON_ALREADY_ASSIGNED = 'already_assigned';
    public const REASON_NO_RECIPIENTS = 'no_recipients';
    public const REASON_DUE_NOT_FUTURE = 'due_not_future';
    public const REASON_CONFIRMATION_REQUIRED = 'confirmation_required';

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

    public static function alreadyAssigned(): self
    {
        return new self(self::REASON_ALREADY_ASSIGNED, 'Bu ödev zaten sınıfa atandı.');
    }

    public static function noRecipients(): self
    {
        return new self(
            self::REASON_NO_RECIPIENTS,
            'Bu sınıfta atama için uygun aktif öğrenci yok. Atama oluşturulmadı.',
            ['recipients' => 'Bu sınıfta uygun aktif öğrenci yok.'],
        );
    }

    public static function dueNotFuture(): self
    {
        return new self(
            self::REASON_DUE_NOT_FUTURE,
            'Son teslim zamanı atama anından sonra olmalıdır.',
            ['due' => 'Son teslim zamanı atama anından sonra olmalıdır.'],
        );
    }

    public static function confirmationRequired(): self
    {
        return new self(
            self::REASON_CONFIRMATION_REQUIRED,
            'Atamayı onaylaman gerekir.',
            ['confirm' => 'Atamayı onaylamak için kutuyu işaretle.'],
        );
    }
}
