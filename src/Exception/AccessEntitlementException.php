<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\AccessEntitlementFailureReason;

final class AccessEntitlementException extends \RuntimeException
{
    private function __construct(
        private readonly AccessEntitlementFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): AccessEntitlementFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(AccessEntitlementFailureReason::Unauthorized, 'Access entitlement operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid access entitlement input.'): self
    {
        return new self(AccessEntitlementFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(AccessEntitlementFailureReason::InvalidTransition, 'Access entitlement status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(AccessEntitlementFailureReason::Conflict, 'Access entitlement operation conflict.');
    }

    public static function notFound(): self
    {
        return new self(AccessEntitlementFailureReason::NotFound, 'Access entitlement resource was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(AccessEntitlementFailureReason::NotFound, 'User was not found.');
    }

    public static function scopeMismatch(string $detail = 'Access entitlement scope mismatch.'): self
    {
        return new self(AccessEntitlementFailureReason::ScopeMismatch, $detail);
    }

    public static function immutable(): self
    {
        return new self(AccessEntitlementFailureReason::Immutable, 'Access entitlement field is immutable.');
    }

    public static function seatLimitExceeded(): self
    {
        return new self(AccessEntitlementFailureReason::SeatLimitExceeded, 'Institution license seat limit exceeded.');
    }

    public static function hashMismatch(): self
    {
        return new self(AccessEntitlementFailureReason::HashMismatch, 'Access package policy hash mismatch.');
    }

    public static function grantInvalid(string $detail = 'Access package grant is invalid.'): self
    {
        return new self(AccessEntitlementFailureReason::GrantInvalid, $detail);
    }

    public static function packageRetired(): self
    {
        return new self(AccessEntitlementFailureReason::PackageRetired, 'Retired package cannot create new licenses.');
    }

    public static function resourceNotPublished(): self
    {
        return new self(AccessEntitlementFailureReason::ResourceNotPublished, 'Granted resource must be published.');
    }
}
