<?php

declare(strict_types=1);

namespace App\Access;

use App\Enum\AccessPackageCatalogResourceKind;
use App\Enum\AccessPackageTargetType;
use App\Enum\GradeLevel;
use App\Exception\AccessEntitlementException;
use App\Question\Content\QuestionContentCanonicalEncoder;
use Symfony\Component\Uid\Uuid;

/**
 * SHA-256 over canonical package-version policy (no PII / secrets / content body).
 */
final class AccessPackagePolicyHasher
{
    public const HASH_HEX_LENGTH = 64;

    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly QuestionContentCanonicalEncoder $encoder,
    ) {
    }

    /**
     * @param list<string>                                              $learningContentGrantIds RFC4122 sorted
     * @param list<string>                                              $assessmentGrantIds      RFC4122 sorted
     * @param list<array{kind: string, subjectId?: string, grade: int}> $catalogGrants           sorted
     */
    public function hash(
        Uuid $packageId,
        string $packageCode,
        int $versionNumber,
        AccessPackageTargetType $targetType,
        ?int $validityDays,
        ?int $seatLimit,
        array $learningContentGrantIds,
        array $assessmentGrantIds,
        array $catalogGrants,
        int $schemaVersion = self::SCHEMA_VERSION,
    ): string {
        return hash('sha256', $this->encoder->encode($this->canonicalPayload(
            $schemaVersion,
            $packageId,
            $packageCode,
            $versionNumber,
            $targetType,
            $validityDays,
            $seatLimit,
            $learningContentGrantIds,
            $assessmentGrantIds,
            $catalogGrants,
        )));
    }

    /**
     * @param list<string>                                              $learningContentGrantIds
     * @param list<string>                                              $assessmentGrantIds
     * @param list<array{kind: string, subjectId?: string, grade: int}> $catalogGrants
     */
    public function verify(
        string $storedHash,
        Uuid $packageId,
        string $packageCode,
        int $versionNumber,
        AccessPackageTargetType $targetType,
        ?int $validityDays,
        ?int $seatLimit,
        array $learningContentGrantIds,
        array $assessmentGrantIds,
        array $catalogGrants,
        int $schemaVersion = self::SCHEMA_VERSION,
    ): void {
        if ('' === $storedHash || 1 !== preg_match('/^[0-9a-f]{'.self::HASH_HEX_LENGTH.'}$/', $storedHash)) {
            throw AccessEntitlementException::hashMismatch();
        }

        $expected = $this->hash(
            $packageId,
            $packageCode,
            $versionNumber,
            $targetType,
            $validityDays,
            $seatLimit,
            $learningContentGrantIds,
            $assessmentGrantIds,
            $catalogGrants,
            $schemaVersion,
        );
        if (!hash_equals($expected, $storedHash)) {
            throw AccessEntitlementException::hashMismatch();
        }
    }

    /**
     * @param list<string>                                              $learningContentGrantIds
     * @param list<string>                                              $assessmentGrantIds
     * @param list<array{kind: string, subjectId?: string, grade: int}> $catalogGrants
     *
     * @return array<string, mixed>
     */
    private function canonicalPayload(
        int $schemaVersion,
        Uuid $packageId,
        string $packageCode,
        int $versionNumber,
        AccessPackageTargetType $targetType,
        ?int $validityDays,
        ?int $seatLimit,
        array $learningContentGrantIds,
        array $assessmentGrantIds,
        array $catalogGrants,
    ): array {
        $lc = $learningContentGrantIds;
        sort($lc);
        $assessments = $assessmentGrantIds;
        sort($assessments);

        $catalog = $catalogGrants;
        usort($catalog, static function (array $a, array $b): int {
            $kind = $a['kind'] <=> $b['kind'];
            if (0 !== $kind) {
                return $kind;
            }
            $subject = ($a['subjectId'] ?? '') <=> ($b['subjectId'] ?? '');
            if (0 !== $subject) {
                return $subject;
            }

            return $a['grade'] <=> $b['grade'];
        });

        return [
            'schemaVersion' => $schemaVersion,
            'packageId' => $packageId->toRfc4122(),
            'packageCode' => $packageCode,
            'versionNumber' => $versionNumber,
            'targetType' => $targetType->value,
            'validityDays' => $validityDays,
            'seatLimit' => $seatLimit,
            'learningContentGrantIds' => $lc,
            'assessmentGrantIds' => $assessments,
            'catalogGrants' => $catalog,
        ];
    }

    /**
     * @return array{kind: string, subjectId?: string, grade: int}
     */
    public static function catalogGrantPayload(
        AccessPackageCatalogResourceKind $kind,
        ?Uuid $subjectId,
        GradeLevel $gradeLevel,
    ): array {
        $payload = [
            'kind' => $kind->value,
            'grade' => $gradeLevel->value,
        ];
        if (AccessPackageCatalogResourceKind::LearningContent === $kind) {
            if (!$subjectId instanceof Uuid) {
                throw AccessEntitlementException::invalidInput('Learning content catalog grant requires subject.');
            }
            $payload['subjectId'] = $subjectId->toRfc4122();
        }

        return $payload;
    }
}
