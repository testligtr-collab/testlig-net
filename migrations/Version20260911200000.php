<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.13: versioned assessment result review policies per delivery + active guard.
 *
 * Irreversible — down() does not restore prior schema.
 * Does not modify Version20260910900000 or Version20260911120000.
 */
final class Version20260911200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create assessment result review policy tables, CHECKs, composite FKs, and triggers.';
    }

    public function up(Schema $schema): void
    {
        foreach ([
            'assessment_result_review_policies',
            'assessment_result_active_review_policy_guards',
        ] as $tableName) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = '.$this->connection->quote($tableName),
            );
            $this->abortIf(
                $exists > 0,
                \sprintf('Version20260911200000 preflight failed: table %s already exists.', $tableName),
            );
        }

        foreach (['assessment_deliveries', 'institutions', 'users'] as $required) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = '.$this->connection->quote($required),
            );
            $this->abortIf(
                0 === $exists,
                \sprintf('Version20260911200000 requires table %s.', $required),
            );
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_result_review_policies (
                id BINARY(16) NOT NULL,
                institution_id BINARY(16) NOT NULL,
                delivery_id BINARY(16) NOT NULL,
                version INT NOT NULL,
                status VARCHAR(32) NOT NULL,
                availability_mode VARCHAR(32) NOT NULL,
                scheduled_at DATETIME DEFAULT NULL,
                show_score_summary TINYINT(1) NOT NULL,
                show_item_outcomes TINYINT(1) NOT NULL,
                show_student_answer TINYINT(1) NOT NULL,
                show_correct_answer TINYINT(1) NOT NULL,
                show_explanation TINYINT(1) NOT NULL,
                created_by_id BINARY(16) NOT NULL,
                activated_by_id BINARY(16) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                activated_at DATETIME DEFAULT NULL,
                superseded_at DATETIME DEFAULT NULL,
                updated_at DATETIME NOT NULL,
                reason_code VARCHAR(64) NOT NULL,
                policy_hash VARCHAR(64) NOT NULL,
                schema_version INT NOT NULL,
                UNIQUE INDEX uniq_arrp_delivery_version (delivery_id, version),
                UNIQUE INDEX uniq_arrp_id_delivery (id, delivery_id),
                UNIQUE INDEX uniq_arrp_id_institution (id, institution_id),
                UNIQUE INDEX uniq_arrp_id_delivery_institution (id, delivery_id, institution_id),
                INDEX idx_arrp_delivery_status (delivery_id, status),
                INDEX idx_arrp_institution (institution_id),
                PRIMARY KEY (id),
                CONSTRAINT chk_arrp_status CHECK (status IN ('draft', 'active', 'superseded')),
                CONSTRAINT chk_arrp_version CHECK (version >= 1),
                CONSTRAINT chk_arrp_schema_version CHECK (schema_version >= 1),
                CONSTRAINT chk_arrp_availability_mode CHECK (
                    availability_mode IN ('never', 'after_delivery_closed', 'scheduled_after_close')
                ),
                CONSTRAINT chk_arrp_scheduled_at CHECK (
                    (
                        availability_mode = 'scheduled_after_close'
                        AND scheduled_at IS NOT NULL
                    )
                    OR (
                        availability_mode <> 'scheduled_after_close'
                        AND scheduled_at IS NULL
                    )
                ),
                CONSTRAINT chk_arrp_never_flags CHECK (
                    availability_mode <> 'never'
                    OR (
                        show_item_outcomes = 0
                        AND show_student_answer = 0
                        AND show_correct_answer = 0
                        AND show_explanation = 0
                    )
                ),
                CONSTRAINT chk_arrp_sensitive_requires_outcomes CHECK (
                    (show_correct_answer = 0 AND show_explanation = 0)
                    OR show_item_outcomes = 1
                ),
                CONSTRAINT chk_arrp_lifecycle CHECK (
                    (
                        status = 'draft'
                        AND activated_at IS NULL AND activated_by_id IS NULL
                        AND superseded_at IS NULL
                    )
                    OR (
                        status = 'active'
                        AND activated_at IS NOT NULL AND activated_by_id IS NOT NULL
                        AND superseded_at IS NULL
                    )
                    OR (
                        status = 'superseded'
                        AND activated_at IS NOT NULL AND activated_by_id IS NOT NULL
                        AND superseded_at IS NOT NULL
                    )
                ),
                CONSTRAINT chk_arrp_reason_code CHECK (
                    reason_code REGEXP '^[a-z][a-z0-9_]{0,63}$'
                ),
                CONSTRAINT chk_arrp_policy_hash CHECK (
                    policy_hash REGEXP '^[0-9a-f]{64}$'
                )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_result_active_review_policy_guards (
                delivery_id BINARY(16) NOT NULL,
                policy_id BINARY(16) NOT NULL,
                UNIQUE INDEX uniq_ararpg_policy (policy_id),
                PRIMARY KEY (delivery_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE assessment_result_review_policies ADD CONSTRAINT FK_ARRP_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_result_review_policies ADD CONSTRAINT FK_ARRP_DELIVERY FOREIGN KEY (delivery_id) REFERENCES assessment_deliveries (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_result_review_policies ADD CONSTRAINT FK_ARRP_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_result_review_policies ADD CONSTRAINT FK_ARRP_ACTIVATED_BY FOREIGN KEY (activated_by_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_result_review_policies ADD CONSTRAINT FK_ARRP_DELIVERY_INSTITUTION FOREIGN KEY (delivery_id, institution_id) REFERENCES assessment_deliveries (id, institution_id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE assessment_result_active_review_policy_guards ADD CONSTRAINT FK_ARARPG_DELIVERY FOREIGN KEY (delivery_id) REFERENCES assessment_deliveries (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_result_active_review_policy_guards ADD CONSTRAINT FK_ARARPG_POLICY FOREIGN KEY (policy_id) REFERENCES assessment_result_review_policies (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assessment_result_active_review_policy_guards ADD CONSTRAINT FK_ARARPG_POLICY_DELIVERY FOREIGN KEY (policy_id, delivery_id) REFERENCES assessment_result_review_policies (id, delivery_id) ON DELETE CASCADE');

        // Sequential version + draft shape on INSERT
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_result_review_policies_bi');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_result_review_policies_bi
            BEFORE INSERT ON assessment_result_review_policies
            FOR EACH ROW
            BEGIN
                DECLARE max_version INT DEFAULT 0;
                DECLARE delivery_institution_id BINARY(16);

                SELECT d.institution_id
                  INTO delivery_institution_id
                  FROM assessment_deliveries d
                 WHERE d.id = NEW.delivery_id
                 LIMIT 1;

                IF delivery_institution_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'review_policy delivery not found';
                END IF;

                IF delivery_institution_id <> NEW.institution_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'review_policy institution mismatch';
                END IF;

                SELECT COALESCE(MAX(p.version), 0)
                  INTO max_version
                  FROM assessment_result_review_policies p
                 WHERE p.delivery_id = NEW.delivery_id;

                IF NEW.version <> (max_version + 1) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'review_policy version must be MAX+1';
                END IF;

                IF NEW.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'review_policy create requires draft status';
                END IF;

                IF NEW.activated_at IS NOT NULL OR NEW.activated_by_id IS NOT NULL OR NEW.superseded_at IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'review_policy draft forbids activation/supersede timestamps';
                END IF;
            END
            SQL);

        // Immutability + allowed transitions
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_result_review_policies_bu');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_result_review_policies_bu
            BEFORE UPDATE ON assessment_result_review_policies
            FOR EACH ROW
            BEGIN
                IF OLD.id <> NEW.id
                   OR OLD.institution_id <> NEW.institution_id
                   OR OLD.delivery_id <> NEW.delivery_id
                   OR OLD.version <> NEW.version
                   OR OLD.schema_version <> NEW.schema_version
                   OR OLD.created_by_id <> NEW.created_by_id
                   OR OLD.created_at <> NEW.created_at
                THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'review_policy identity is immutable';
                END IF;

                IF NOT (
                    (OLD.status = 'draft' AND NEW.status IN ('draft', 'active'))
                    OR (OLD.status = 'active' AND NEW.status IN ('active', 'superseded'))
                    OR (OLD.status = 'superseded' AND NEW.status = 'superseded')
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'review_policy status transition is not allowed';
                END IF;

                IF OLD.status IN ('active', 'superseded') THEN
                    IF OLD.availability_mode <> NEW.availability_mode
                       OR NOT (OLD.scheduled_at <=> NEW.scheduled_at)
                       OR OLD.show_score_summary <> NEW.show_score_summary
                       OR OLD.show_item_outcomes <> NEW.show_item_outcomes
                       OR OLD.show_student_answer <> NEW.show_student_answer
                       OR OLD.show_correct_answer <> NEW.show_correct_answer
                       OR OLD.show_explanation <> NEW.show_explanation
                       OR OLD.policy_hash <> NEW.policy_hash
                       OR OLD.reason_code <> NEW.reason_code
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'review_policy content is immutable when active or superseded';
                    END IF;
                END IF;

                IF OLD.status = 'active' AND NEW.status = 'active' THEN
                    IF NOT (OLD.activated_at <=> NEW.activated_at)
                       OR NOT (OLD.activated_by_id <=> NEW.activated_by_id)
                       OR NOT (OLD.superseded_at <=> NEW.superseded_at)
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'review_policy activation fields immutable while active';
                    END IF;
                END IF;

                IF OLD.status = 'superseded' THEN
                    IF NOT (OLD.activated_at <=> NEW.activated_at)
                       OR NOT (OLD.activated_by_id <=> NEW.activated_by_id)
                       OR NOT (OLD.superseded_at <=> NEW.superseded_at)
                       OR OLD.updated_at <> NEW.updated_at
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'superseded review_policy is immutable';
                    END IF;
                END IF;

                IF OLD.status = 'draft' AND NEW.status = 'active' THEN
                    IF NEW.activated_at IS NULL OR NEW.activated_by_id IS NULL OR NEW.superseded_at IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'review_policy activate requires activated_at/by';
                    END IF;
                END IF;

                IF OLD.status = 'active' AND NEW.status = 'superseded' THEN
                    IF NEW.superseded_at IS NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'review_policy supersede requires superseded_at';
                    END IF;
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_result_review_policies_bd');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_result_review_policies_bd
            BEFORE DELETE ON assessment_result_review_policies
            FOR EACH ROW
            BEGIN
                IF OLD.status IN ('active', 'superseded') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'active or superseded review_policy cannot be deleted';
                END IF;
            END
            SQL);

        // Active guard sync
        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_result_review_policies_ai_guard');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_result_review_policies_ai_guard
            AFTER INSERT ON assessment_result_review_policies
            FOR EACH ROW
            BEGIN
                IF NEW.status = 'active' THEN
                    INSERT INTO assessment_result_active_review_policy_guards (delivery_id, policy_id)
                    VALUES (NEW.delivery_id, NEW.id);
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_result_review_policies_au_guard');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_result_review_policies_au_guard
            AFTER UPDATE ON assessment_result_review_policies
            FOR EACH ROW
            BEGIN
                DECLARE guard_exists INT DEFAULT 0;

                IF OLD.status = 'active' AND NEW.status <> 'active' THEN
                    DELETE FROM assessment_result_active_review_policy_guards
                     WHERE policy_id = NEW.id;
                END IF;

                IF NEW.status = 'active' THEN
                    SELECT COUNT(*) INTO guard_exists
                      FROM assessment_result_active_review_policy_guards g
                     WHERE g.delivery_id = NEW.delivery_id;

                    IF guard_exists = 0 THEN
                        INSERT INTO assessment_result_active_review_policy_guards (delivery_id, policy_id)
                        VALUES (NEW.delivery_id, NEW.id);
                    END IF;
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_result_active_review_policy_guards_bi');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_result_active_review_policy_guards_bi
            BEFORE INSERT ON assessment_result_active_review_policy_guards
            FOR EACH ROW
            BEGIN
                DECLARE policy_status VARCHAR(32);
                DECLARE policy_delivery_id BINARY(16);

                SELECT p.status, p.delivery_id
                  INTO policy_status, policy_delivery_id
                  FROM assessment_result_review_policies p
                 WHERE p.id = NEW.policy_id
                 LIMIT 1;

                IF policy_status IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'active_review_policy_guard policy not found';
                END IF;

                IF policy_status <> 'active' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'active_review_policy_guard requires active status';
                END IF;

                IF policy_delivery_id <> NEW.delivery_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'active_review_policy_guard delivery mismatch';
                END IF;
            END
            SQL);

        $this->addSql('DROP TRIGGER IF EXISTS trg_assessment_result_active_review_policy_guards_bd');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_assessment_result_active_review_policy_guards_bd
            BEFORE DELETE ON assessment_result_active_review_policy_guards
            FOR EACH ROW
            BEGIN
                DECLARE policy_status VARCHAR(32);

                SELECT p.status
                  INTO policy_status
                  FROM assessment_result_review_policies p
                 WHERE p.id = OLD.policy_id
                 LIMIT 1;

                IF policy_status = 'active' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cannot detach active review policy guard';
                END IF;
            END
            SQL);

        // Align InnoDB auto-index names with Doctrine association naming (schema:update empty).
        $this->addSql('ALTER TABLE assessment_result_review_policies RENAME INDEX FK_ARRP_CREATED_BY TO IDX_78253CDCB03A8386');
        $this->addSql('ALTER TABLE assessment_result_review_policies RENAME INDEX FK_ARRP_ACTIVATED_BY TO IDX_78253CDCE00EB9A0');
        $this->addSql('ALTER TABLE assessment_result_review_policies RENAME INDEX FK_ARRP_DELIVERY_INSTITUTION TO IDX_78253CDC1213692110405986');
        $this->addSql('ALTER TABLE assessment_result_active_review_policy_guards RENAME INDEX FK_ARARPG_POLICY_DELIVERY TO IDX_F305E3822D29E3C612136921');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Stage 2.13 assessment result review policy migration is irreversible.',
        );
    }
}
