<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\AcademicYearFailureReason;

final class AcademicYearException extends \RuntimeException
{
    private function __construct(
        private readonly AcademicYearFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): AcademicYearFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(AcademicYearFailureReason::Unauthorized, 'Academic year operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid academic year input.'): self
    {
        return new self(AcademicYearFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(AcademicYearFailureReason::InvalidTransition, 'Academic year status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(AcademicYearFailureReason::Conflict, 'Academic year operation conflict.');
    }

    public static function notFound(): self
    {
        return new self(AcademicYearFailureReason::NotFound, 'Academic year was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(AcademicYearFailureReason::NotFound, 'User was not found.');
    }

    public static function crossInstitution(): self
    {
        return new self(AcademicYearFailureReason::CrossInstitution, 'Cross-institution academic year operation is forbidden.');
    }

    public static function institutionNotOperable(): self
    {
        return new self(AcademicYearFailureReason::InstitutionNotOperable, 'Institution does not allow academic year management in its current status.');
    }

    public static function dateOverlap(): self
    {
        return new self(AcademicYearFailureReason::DateOverlap, 'Academic year date range overlaps another year in the same institution.');
    }

    public static function yearNotOperable(): self
    {
        return new self(AcademicYearFailureReason::YearNotOperable, 'Academic year does not allow this operation in its current status.');
    }
}
