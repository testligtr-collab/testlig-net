<?php

declare(strict_types=1);

namespace App\Access;

/**
 * Immutable DBAL projection for active seat → membership → institution → license chain.
 */
final readonly class EntitlementSeatAuthSnapshot
{
    public function __construct(
        public string $seatId,
        public string $seatStatus,
        public string $seatUserId,
        public string $seatInstitutionId,
        public string $membershipId,
        public string $membershipStatus,
        public string $membershipUserId,
        public string $membershipInstitutionId,
        public string $institutionStatus,
        public EntitlementLicenseAuthSnapshot $license,
    ) {
    }
}
