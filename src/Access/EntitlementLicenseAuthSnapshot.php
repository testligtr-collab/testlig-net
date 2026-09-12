<?php

declare(strict_types=1);

namespace App\Access;

/**
 * Immutable DBAL projection for license + package + version authorization.
 */
final readonly class EntitlementLicenseAuthSnapshot
{
    public function __construct(
        public string $licenseId,
        public string $licenseStatus,
        public string $licenseeType,
        public ?string $userId,
        public ?string $institutionId,
        public \DateTimeImmutable $validFrom,
        public \DateTimeImmutable $validUntil,
        public string $policySnapshotHash,
        public string $packageId,
        public string $packageCode,
        public string $packageStatus,
        public string $packageTargetType,
        public string $versionId,
        public int $versionNumber,
        public string $versionStatus,
        public ?int $validityDays,
        public ?int $seatLimit,
        public string $policyHash,
        public int $schemaVersion,
    ) {
    }
}
