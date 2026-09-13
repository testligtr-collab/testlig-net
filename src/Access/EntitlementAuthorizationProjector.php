<?php

declare(strict_types=1);

namespace App\Access;

use App\Enum\AccessPackageCatalogResourceKind;
use App\Enum\AccessPackageTargetType;
use App\Enum\GradeLevel;
use App\Exception\AccessEntitlementException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Fresh DBAL projections for entitlement decisions — never trust managed associations.
 */
final class EntitlementAuthorizationProjector
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AccessPackagePolicyHasher $hasher,
    ) {
    }

    /**
     * Candidate user licenses (fresh DB status evaluated in the gate — not only active).
     *
     * @return list<string> license RFC4122 ids
     */
    public function findUserLicenseCandidateIds(Uuid $userId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            "SELECT HEX(id) FROM access_licenses
             WHERE user_id = ? AND licensee_type = ?
               AND status IN ('active', 'suspended', 'revoked', 'expired')
             ORDER BY created_at ASC",
            [$userId->toBinary(), 'user'],
        );

        return array_map(static fn (string $hex): string => self::hexToRfc4122($hex), $rows);
    }

    /**
     * Candidate seats for the user (active + revoked) so revoked seats yield seat_revoked, not a silent miss.
     *
     * @return list<string> seat RFC4122 ids
     */
    public function findSeatCandidateIdsForUser(Uuid $userId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            "SELECT HEX(id) FROM institution_license_seats
             WHERE user_id = ? AND status IN ('active', 'revoked')
             ORDER BY assigned_at ASC",
            [$userId->toBinary()],
        );

        return array_map(static fn (string $hex): string => self::hexToRfc4122($hex), $rows);
    }

    public function loadLicenseSnapshot(Uuid $licenseId): ?EntitlementLicenseAuthSnapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT
                HEX(l.id) AS license_id,
                l.status AS license_status,
                l.licensee_type,
                HEX(l.user_id) AS user_id,
                HEX(l.institution_id) AS institution_id,
                l.valid_from,
                l.valid_until,
                l.policy_snapshot_hash,
                HEX(p.id) AS package_id,
                p.code AS package_code,
                p.status AS package_status,
                p.target_type AS package_target_type,
                HEX(v.id) AS version_id,
                v.version_number,
                v.status AS version_status,
                v.validity_days,
                v.seat_limit,
                v.policy_hash,
                v.schema_version
             FROM access_licenses l
             INNER JOIN access_packages p ON p.id = l.package_id
             INNER JOIN access_package_versions v ON v.id = l.package_version_id
             WHERE l.id = ?',
            [$licenseId->toBinary()],
        );
        if (false === $row) {
            return null;
        }

        return $this->mapLicenseRow($row);
    }

    public function loadSeatSnapshot(Uuid $seatId): ?EntitlementSeatAuthSnapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT
                HEX(s.id) AS seat_id,
                s.status AS seat_status,
                HEX(s.user_id) AS seat_user_id,
                HEX(s.institution_id) AS seat_institution_id,
                HEX(s.membership_id) AS membership_id,
                m.status AS membership_status,
                HEX(m.user_id) AS membership_user_id,
                HEX(m.institution_id) AS membership_institution_id,
                i.status AS institution_status,
                HEX(l.id) AS license_id,
                l.status AS license_status,
                l.licensee_type,
                HEX(l.user_id) AS user_id,
                HEX(l.institution_id) AS institution_id,
                l.valid_from,
                l.valid_until,
                l.policy_snapshot_hash,
                HEX(p.id) AS package_id,
                p.code AS package_code,
                p.status AS package_status,
                p.target_type AS package_target_type,
                HEX(v.id) AS version_id,
                v.version_number,
                v.status AS version_status,
                v.validity_days,
                v.seat_limit,
                v.policy_hash,
                v.schema_version
             FROM institution_license_seats s
             INNER JOIN institution_memberships m ON m.id = s.membership_id
             INNER JOIN institutions i ON i.id = s.institution_id
             INNER JOIN access_licenses l ON l.id = s.license_id
             INNER JOIN access_packages p ON p.id = l.package_id
             INNER JOIN access_package_versions v ON v.id = l.package_version_id
             WHERE s.id = ?',
            [$seatId->toBinary()],
        );
        if (false === $row) {
            return null;
        }

        return new EntitlementSeatAuthSnapshot(
            seatId: self::hexToRfc4122((string) $row['seat_id']),
            seatStatus: (string) $row['seat_status'],
            seatUserId: self::hexToRfc4122((string) $row['seat_user_id']),
            seatInstitutionId: self::hexToRfc4122((string) $row['seat_institution_id']),
            membershipId: self::hexToRfc4122((string) $row['membership_id']),
            membershipStatus: (string) $row['membership_status'],
            membershipUserId: self::hexToRfc4122((string) $row['membership_user_id']),
            membershipInstitutionId: self::hexToRfc4122((string) $row['membership_institution_id']),
            institutionStatus: (string) $row['institution_status'],
            license: $this->mapLicenseRow($row),
        );
    }

    public function loadGrantGraph(Uuid $versionId): EntitlementGrantGraph
    {
        $lcRows = $this->connection->fetchFirstColumn(
            'SELECT HEX(content_id) FROM access_package_learning_content_grants
             WHERE version_id = ? ORDER BY HEX(content_id) ASC',
            [$versionId->toBinary()],
        );
        $assessmentRows = $this->connection->fetchFirstColumn(
            'SELECT HEX(assessment_id) FROM access_package_assessment_grants
             WHERE version_id = ? ORDER BY HEX(assessment_id) ASC',
            [$versionId->toBinary()],
        );
        $catalogRows = $this->connection->fetchAllAssociative(
            'SELECT resource_kind, HEX(subject_id) AS subject_id, grade_level
             FROM access_package_catalog_grants
             WHERE version_id = ?
             ORDER BY resource_kind ASC, HEX(subject_id) ASC, grade_level ASC',
            [$versionId->toBinary()],
        );

        $catalog = [];
        foreach ($catalogRows as $row) {
            $kind = AccessPackageCatalogResourceKind::from((string) $row['resource_kind']);
            $subjectId = null;
            if (null !== $row['subject_id'] && '' !== $row['subject_id']) {
                $subjectId = Uuid::fromString(self::hexToRfc4122((string) $row['subject_id']));
            }
            $catalog[] = AccessPackagePolicyHasher::catalogGrantPayload(
                $kind,
                $subjectId,
                GradeLevel::from((int) $row['grade_level']),
            );
        }

        return new EntitlementGrantGraph(
            learningContentIds: array_map(static fn (string $hex): string => self::hexToRfc4122($hex), $lcRows),
            assessmentIds: array_map(static fn (string $hex): string => self::hexToRfc4122($hex), $assessmentRows),
            catalogGrants: $catalog,
        );
    }

    public function computeFreshPolicyHash(EntitlementLicenseAuthSnapshot $license, EntitlementGrantGraph $graph): string
    {
        return $this->hasher->hash(
            Uuid::fromString($license->packageId),
            $license->packageCode,
            $license->versionNumber,
            AccessPackageTargetType::from($license->packageTargetType),
            $license->validityDays,
            $license->seatLimit,
            $graph->learningContentIds,
            $graph->assessmentIds,
            $graph->catalogGrants,
            $license->schemaVersion,
        );
    }

    /**
     * Triple hash_equals: fresh ↔ version, fresh ↔ license snapshot, version ↔ license snapshot.
     */
    public function assertPolicyHashesIntact(EntitlementLicenseAuthSnapshot $license, EntitlementGrantGraph $graph): void
    {
        $fresh = $this->computeFreshPolicyHash($license, $graph);
        if (!hash_equals($fresh, $license->policyHash)
            || !hash_equals($fresh, $license->policySnapshotHash)
            || !hash_equals($license->policyHash, $license->policySnapshotHash)
        ) {
            throw AccessEntitlementException::hashMismatch();
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapLicenseRow(array $row): EntitlementLicenseAuthSnapshot
    {
        return new EntitlementLicenseAuthSnapshot(
            licenseId: self::hexToRfc4122((string) $row['license_id']),
            licenseStatus: (string) $row['license_status'],
            licenseeType: (string) $row['licensee_type'],
            userId: null !== $row['user_id'] && '' !== $row['user_id']
                ? self::hexToRfc4122((string) $row['user_id'])
                : null,
            institutionId: null !== $row['institution_id'] && '' !== $row['institution_id']
                ? self::hexToRfc4122((string) $row['institution_id'])
                : null,
            validFrom: new \DateTimeImmutable((string) $row['valid_from'], new \DateTimeZone('UTC')),
            validUntil: new \DateTimeImmutable((string) $row['valid_until'], new \DateTimeZone('UTC')),
            policySnapshotHash: (string) $row['policy_snapshot_hash'],
            packageId: self::hexToRfc4122((string) $row['package_id']),
            packageCode: (string) $row['package_code'],
            packageStatus: (string) $row['package_status'],
            packageTargetType: (string) $row['package_target_type'],
            versionId: self::hexToRfc4122((string) $row['version_id']),
            versionNumber: (int) $row['version_number'],
            versionStatus: (string) $row['version_status'],
            validityDays: null !== $row['validity_days'] ? (int) $row['validity_days'] : null,
            seatLimit: null !== $row['seat_limit'] ? (int) $row['seat_limit'] : null,
            policyHash: (string) $row['policy_hash'],
            schemaVersion: (int) $row['schema_version'],
        );
    }

    private static function hexToRfc4122(string $hex): string
    {
        $hex = strtolower($hex);
        if (32 !== \strlen($hex) || 1 !== preg_match('/^[0-9a-f]{32}$/', $hex)) {
            throw AccessEntitlementException::invalidInput('Invalid UUID binary projection.');
        }

        return Uuid::fromString(\sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ))->toRfc4122();
    }
}
