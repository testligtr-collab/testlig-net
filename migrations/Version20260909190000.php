<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.8 hardening: answer integrity HMAC, append-only triggers, primary-alignment DB guards.
 *
 * Does not modify Version20260909180000. Triggers block direct UPDATE/DELETE on immutable
 * revision tables. Test cleanup may set session @testlig_immutable_delete_bypass=1;
 * production application code never sets this variable.
 */
final class Version20260909190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden question bank: answer integrity HMAC, immutability triggers, primary alignment scope';
    }

    public function up(Schema $schema): void
    {
        // Empty/default placeholder for any pre-existing rows; application always writes a real HMAC.
        $this->addSql("ALTER TABLE question_answer_keys ADD answer_integrity_hmac VARCHAR(64) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE question_answer_keys ALTER COLUMN answer_integrity_hmac DROP DEFAULT');

        // At most one primary alignment per revision (NULL scope for non-primary; MariaDB allows multiple NULLs).
        $this->addSql('ALTER TABLE question_revision_alignments ADD primary_revision_scope_id BINARY(16) AS (IF(`is_primary`, `revision_id`, NULL)) STORED');
        $this->addSql('CREATE UNIQUE INDEX uniq_qra_one_primary_per_revision ON question_revision_alignments (primary_revision_scope_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_qra_id_revision_is_primary ON question_revision_alignments (id, revision_id, is_primary)');

        // Guard may only reference a primary alignment for the same revision.
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards DROP FOREIGN KEY FK_QRPAG_ALIGNMENT');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards DROP FOREIGN KEY FK_QRPAG_ALIGNMENT_REVISION');
        // Drop leftover supporting index from the previous composite FK rename.
        $this->addSql('DROP INDEX IDX_72B8CC73AB7AC2A01DFA7C8F ON question_revision_primary_alignment_guards');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards ADD must_be_primary TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards ADD CONSTRAINT chk_qrpag_must_be_primary CHECK (must_be_primary = 1)');
        // Doctrine OneToOne JoinColumn on alignment_id.
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards ADD CONSTRAINT FK_72B8CC73AB7AC2A0 FOREIGN KEY (alignment_id) REFERENCES question_revision_alignments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards ADD CONSTRAINT FK_QRPAG_ALIGNMENT_REVISION_PRIMARY FOREIGN KEY (alignment_id, revision_id, must_be_primary) REFERENCES question_revision_alignments (id, revision_id, is_primary) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards RENAME INDEX FK_QRPAG_ALIGNMENT_REVISION_PRIMARY TO IDX_72B8CC73AB7AC2A01DFA7C8F9D2AE37');

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_revisions_bu BEFORE UPDATE ON question_revisions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_revisions are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_revisions_bd BEFORE DELETE ON question_revisions
            FOR EACH ROW
            BEGIN
                IF IFNULL(@testlig_immutable_delete_bypass, 0) <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_revisions are append-only';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_revision_options_bu BEFORE UPDATE ON question_revision_options
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_revision_options are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_revision_options_bd BEFORE DELETE ON question_revision_options
            FOR EACH ROW
            BEGIN
                IF IFNULL(@testlig_immutable_delete_bypass, 0) <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_revision_options are append-only';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_answer_keys_bu BEFORE UPDATE ON question_answer_keys
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_answer_keys are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_answer_keys_bd BEFORE DELETE ON question_answer_keys
            FOR EACH ROW
            BEGIN
                IF IFNULL(@testlig_immutable_delete_bypass, 0) <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_answer_keys are append-only';
                END IF;
            END
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_revision_alignments_bu BEFORE UPDATE ON question_revision_alignments
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_revision_alignments are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_revision_alignments_bd BEFORE DELETE ON question_revision_alignments
            FOR EACH ROW
            BEGIN
                IF IFNULL(@testlig_immutable_delete_bypass, 0) <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_revision_alignments are append-only';
                END IF;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_question_revision_alignments_bd');
        $this->addSql('DROP TRIGGER IF EXISTS trg_question_revision_alignments_bu');
        $this->addSql('DROP TRIGGER IF EXISTS trg_question_answer_keys_bd');
        $this->addSql('DROP TRIGGER IF EXISTS trg_question_answer_keys_bu');
        $this->addSql('DROP TRIGGER IF EXISTS trg_question_revision_options_bd');
        $this->addSql('DROP TRIGGER IF EXISTS trg_question_revision_options_bu');
        $this->addSql('DROP TRIGGER IF EXISTS trg_question_revisions_bd');
        $this->addSql('DROP TRIGGER IF EXISTS trg_question_revisions_bu');

        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards DROP FOREIGN KEY FK_QRPAG_ALIGNMENT_REVISION_PRIMARY');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards DROP FOREIGN KEY FK_72B8CC73AB7AC2A0');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards DROP CONSTRAINT chk_qrpag_must_be_primary');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards DROP COLUMN must_be_primary');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards ADD CONSTRAINT FK_QRPAG_ALIGNMENT FOREIGN KEY (alignment_id) REFERENCES question_revision_alignments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards ADD CONSTRAINT FK_QRPAG_ALIGNMENT_REVISION FOREIGN KEY (alignment_id, revision_id) REFERENCES question_revision_alignments (id, revision_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE question_revision_primary_alignment_guards RENAME INDEX FK_QRPAG_ALIGNMENT_REVISION TO IDX_72B8CC73AB7AC2A01DFA7C8F');

        $this->addSql('DROP INDEX uniq_qra_id_revision_is_primary ON question_revision_alignments');
        $this->addSql('DROP INDEX uniq_qra_one_primary_per_revision ON question_revision_alignments');
        $this->addSql('ALTER TABLE question_revision_alignments DROP COLUMN primary_revision_scope_id');

        $this->addSql('ALTER TABLE question_answer_keys DROP COLUMN answer_integrity_hmac');
    }
}
