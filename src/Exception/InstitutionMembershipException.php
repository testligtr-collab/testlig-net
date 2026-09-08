<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\InstitutionMembershipFailureReason;

final class InstitutionMembershipException extends \RuntimeException
{
    private function __construct(
        private readonly InstitutionMembershipFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): InstitutionMembershipFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(InstitutionMembershipFailureReason::Unauthorized, 'Membership operation is not authorized.');
    }

    public static function invalidTransition(): self
    {
        return new self(InstitutionMembershipFailureReason::InvalidTransition, 'Membership status transition is not allowed.');
    }

    public static function invalidInput(string $detail = 'Invalid membership input.'): self
    {
        return new self(InstitutionMembershipFailureReason::InvalidInput, $detail);
    }

    public static function conflict(): self
    {
        return new self(InstitutionMembershipFailureReason::Conflict, 'Membership operation conflict.');
    }

    public static function duplicateMembership(): self
    {
        return new self(InstitutionMembershipFailureReason::DuplicateMembership, 'User already has a membership in this institution.');
    }

    public static function lastOwnerProtected(): self
    {
        return new self(InstitutionMembershipFailureReason::LastOwnerProtected, 'The last active owner cannot be suspended or ended.');
    }

    public static function crossInstitution(): self
    {
        return new self(InstitutionMembershipFailureReason::CrossInstitution, 'Cross-institution membership operation is forbidden.');
    }

    public static function institutionNotOperable(): self
    {
        return new self(InstitutionMembershipFailureReason::InstitutionNotOperable, 'Institution does not allow membership management in its current status.');
    }

    public static function ownerRoleRestricted(): self
    {
        return new self(InstitutionMembershipFailureReason::OwnerRoleRestricted, 'Owner role cannot be assigned through changeRole.');
    }

    public static function notFound(): self
    {
        return new self(InstitutionMembershipFailureReason::NotFound, 'Membership was not found.');
    }
}
