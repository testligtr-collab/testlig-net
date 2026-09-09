<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\CurriculumUnitFailureReason;

final class CurriculumUnitException extends \RuntimeException
{
    private function __construct(
        private readonly CurriculumUnitFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): CurriculumUnitFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(CurriculumUnitFailureReason::Unauthorized, 'Curriculum unit operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid curriculum unit input.'): self
    {
        return new self(CurriculumUnitFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(CurriculumUnitFailureReason::InvalidTransition, 'Curriculum unit status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(CurriculumUnitFailureReason::Conflict, 'Curriculum unit operation conflict.');
    }

    public static function notFound(): self
    {
        return new self(CurriculumUnitFailureReason::NotFound, 'Curriculum unit was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(CurriculumUnitFailureReason::NotFound, 'User was not found.');
    }

    public static function programNotDraft(): self
    {
        return new self(CurriculumUnitFailureReason::ProgramNotDraft, 'Curriculum unit mutations require a draft program.');
    }

    public static function programImmutable(): self
    {
        return new self(CurriculumUnitFailureReason::ProgramImmutable, 'Published curriculum structure is immutable.');
    }
}
