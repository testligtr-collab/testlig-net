<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.16 hardening: draft-only INSERT/DELETE for package grants; access-time hash
 * integrity depends on active/superseded grant graphs being immutable at DB level.
 *
 * Irreversible security migration — down() does not restore weaker grant mutation.
 *
 * Does not modify Version20260912160000 or earlier migrations.
 */
final class Version20260912170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce draft-only INSERT/DELETE on access package grants; preflight hash integrity';
    }

    public function up(Schema $schema): void
    {
        $licenseVersionHashMismatch = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM access_licenses l
            INNER JOIN access_package_versions v ON v.id = l.package_version_id
            WHERE l.policy_snapshot_hash <> v.policy_hash
            SQL);
        $this->write(\sprintf('Preflight: license snapshot ≠ version policy_hash=%d', $licenseVersionHashMismatch));
        $this->abortIf(
            $licenseVersionHashMismatch > 0,
            \sprintf(
                'Abort: %d access_licenses row(s) have policy_snapshot_hash differing from version policy_hash.',
                $licenseVersionHashMismatch,
            ),
        );

        $versions = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT HEX(v.id) AS version_id, v.policy_hash, v.schema_version, v.version_number,
                   v.validity_days, v.seat_limit, HEX(p.id) AS package_id, p.code, p.target_type
              FROM access_package_versions v
              INNER JOIN access_packages p ON p.id = v.package_id
             WHERE v.status IN ('active', 'superseded')
            SQL);
        foreach ($versions as $version) {
            $versionBin = hex2bin(strtolower((string) $version['version_id']));
            $this->abortIf(false === $versionBin, 'Abort: invalid version id hex.');

            $lcIds = $this->connection->fetchFirstColumn(
                'SELECT LOWER(HEX(content_id)) FROM access_package_learning_content_grants WHERE version_id = ? ORDER BY 1',
                [$versionBin],
            );
            $assessmentIds = $this->connection->fetchFirstColumn(
                'SELECT LOWER(HEX(assessment_id)) FROM access_package_assessment_grants WHERE version_id = ? ORDER BY 1',
                [$versionBin],
            );
            $catalogRows = $this->connection->fetchAllAssociative(
                'SELECT resource_kind, LOWER(HEX(subject_id)) AS subject_id, grade_level
                   FROM access_package_catalog_grants WHERE version_id = ?
                  ORDER BY resource_kind, subject_id, grade_level',
                [$versionBin],
            );

            $learningContentGrantIds = array_map([$this, 'hexUuidToRfc4122'], $lcIds);
            $assessmentGrantIds = array_map([$this, 'hexUuidToRfc4122'], $assessmentIds);
            sort($learningContentGrantIds);
            sort($assessmentGrantIds);

            $catalog = [];
            foreach ($catalogRows as $row) {
                $entry = [
                    'kind' => (string) $row['resource_kind'],
                    'grade' => (int) $row['grade_level'],
                ];
                if ('learning_content' === $row['resource_kind']) {
                    $this->abortIf(
                        null === $row['subject_id'] || '' === $row['subject_id'],
                        'Abort: learning_content catalog grant missing subject.',
                    );
                    $entry['subjectId'] = $this->hexUuidToRfc4122((string) $row['subject_id']);
                } else {
                    $this->abortIf(
                        null !== $row['subject_id'] && '' !== $row['subject_id'],
                        'Abort: assessment catalog grant must have NULL subject.',
                    );
                }
                $catalog[] = $entry;
            }
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

            $payload = [
                'schemaVersion' => (int) $version['schema_version'],
                'packageId' => $this->hexUuidToRfc4122((string) $version['package_id']),
                'packageCode' => (string) $version['code'],
                'versionNumber' => (int) $version['version_number'],
                'targetType' => (string) $version['target_type'],
                'validityDays' => null !== $version['validity_days'] ? (int) $version['validity_days'] : null,
                'seatLimit' => null !== $version['seat_limit'] ? (int) $version['seat_limit'] : null,
                'learningContentGrantIds' => $learningContentGrantIds,
                'assessmentGrantIds' => $assessmentGrantIds,
                'catalogGrants' => $catalog,
            ];
            $canonical = $this->canonicalJson($payload);
            $freshHash = hash('sha256', $canonical);
            $stored = strtolower((string) $version['policy_hash']);
            $this->write(\sprintf(
                'Preflight version %s: stored=%s fresh=%s',
                $this->hexUuidToRfc4122((string) $version['version_id']),
                $stored,
                $freshHash,
            ));
            $this->abortIf(
                !hash_equals($stored, $freshHash),
                \sprintf(
                    'Abort: access_package_versions %s policy_hash does not match fresh grant graph.',
                    $this->hexUuidToRfc4122((string) $version['version_id']),
                ),
            );
        }

        // Learning content grants — draft-only INSERT/DELETE
        $this->addSql('DROP TRIGGER IF EXISTS trg_aplcg_bi_draft_only');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_aplcg_bi_draft_only
            BEFORE INSERT ON access_package_learning_content_grants
            FOR EACH ROW
            BEGIN
                DECLARE v_status VARCHAR(32);
                SELECT status INTO v_status FROM access_package_versions WHERE id = NEW.version_id;
                IF v_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'learning content grant version not found';
                END IF;
                IF v_status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'learning content grants can only be inserted on draft versions';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_aplcg_bd_draft_only');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_aplcg_bd_draft_only
            BEFORE DELETE ON access_package_learning_content_grants
            FOR EACH ROW
            BEGIN
                DECLARE v_status VARCHAR(32);
                SELECT status INTO v_status FROM access_package_versions WHERE id = OLD.version_id;
                IF v_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'learning content grant version not found';
                END IF;
                IF v_status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'learning content grants can only be deleted on draft versions';
                END IF;
            END
            SQL);

        // Assessment grants
        $this->addSql('DROP TRIGGER IF EXISTS trg_apag_bi_draft_only');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_apag_bi_draft_only
            BEFORE INSERT ON access_package_assessment_grants
            FOR EACH ROW
            BEGIN
                DECLARE v_status VARCHAR(32);
                SELECT status INTO v_status FROM access_package_versions WHERE id = NEW.version_id;
                IF v_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment grant version not found';
                END IF;
                IF v_status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment grants can only be inserted on draft versions';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_apag_bd_draft_only');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_apag_bd_draft_only
            BEFORE DELETE ON access_package_assessment_grants
            FOR EACH ROW
            BEGIN
                DECLARE v_status VARCHAR(32);
                SELECT status INTO v_status FROM access_package_versions WHERE id = OLD.version_id;
                IF v_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment grant version not found';
                END IF;
                IF v_status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'assessment grants can only be deleted on draft versions';
                END IF;
            END
            SQL);

        // Catalog grants
        $this->addSql('DROP TRIGGER IF EXISTS trg_apcg_bi_draft_only');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_apcg_bi_draft_only
            BEFORE INSERT ON access_package_catalog_grants
            FOR EACH ROW
            BEGIN
                DECLARE v_status VARCHAR(32);
                SELECT status INTO v_status FROM access_package_versions WHERE id = NEW.version_id;
                IF v_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'catalog grant version not found';
                END IF;
                IF v_status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'catalog grants can only be inserted on draft versions';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_apcg_bd_draft_only');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_apcg_bd_draft_only
            BEFORE DELETE ON access_package_catalog_grants
            FOR EACH ROW
            BEGIN
                DECLARE v_status VARCHAR(32);
                SELECT status INTO v_status FROM access_package_versions WHERE id = OLD.version_id;
                IF v_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'catalog grant version not found';
                END IF;
                IF v_status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'catalog grants can only be deleted on draft versions';
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Version20260912170000 is an irreversible security migration.');
    }

    private function hexUuidToRfc4122(string $hex): string
    {
        $hex = strtolower($hex);
        $this->abortIf(32 !== \strlen($hex), 'Abort: invalid UUID hex length.');

        return \sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function canonicalJson(array $data): string
    {
        return json_encode($this->canonicalize($data), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->canonicalize($item);
        }

        return $out;
    }
}
