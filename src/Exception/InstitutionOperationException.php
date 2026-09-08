<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\InstitutionFailureReason;

final class InstitutionOperationException extends \RuntimeException
{
    private function __construct(
        private readonly InstitutionFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): InstitutionFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(InstitutionFailureReason::Unauthorized, 'Institution operation is not authorized.');
    }

    public static function invalidTransition(): self
    {
        return new self(InstitutionFailureReason::InvalidTransition, 'Institution status transition is not allowed.');
    }

    public static function invalidInput(string $detail = 'Invalid institution input.'): self
    {
        return new self(InstitutionFailureReason::InvalidInput, $detail);
    }

    public static function conflict(): self
    {
        return new self(InstitutionFailureReason::Conflict, 'Institution operation conflict.');
    }

    public static function notFound(): self
    {
        return new self(InstitutionFailureReason::NotFound, 'Institution was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(InstitutionFailureReason::NotFound, 'User was not found.');
    }

    public static function institutionNotActive(): self
    {
        return new self(InstitutionFailureReason::InstitutionNotActive, 'Institution is not active.');
    }
}
